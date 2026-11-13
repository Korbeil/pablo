<?php

declare(strict_types=1);

namespace Pablo\Provider\Gh;

use Pablo\Domain\Time;
use Pablo\Support\Json;
use Pablo\Support\PabloError;
use Pablo\Support\ProcessRunnerInterface;

/**
 * GitHub PR plumbing via the gh CLI: CI status, review evaluation, draft/ready
 * toggling, merge detection.
 */
final class GhPr implements GhPrInterface
{
    public const GH_CALL_TIMEOUT_S = 20;

    private const TIMELINE_QUERY = <<<'GQL'
query($owner: String!, $repo: String!, $pr: Int!) {
  repository(owner: $owner, name: $repo) {
    pullRequest(number: $pr) {
      createdAt
      timelineItems(itemTypes: [READY_FOR_REVIEW_EVENT], last: 1) {
        nodes { ... on ReadyForReviewEvent { createdAt } }
      }
    }
  }
}
GQL;

    /**
     * Reviews are read newest-first page by page (`last` + `before`): every
     * inline reply is a review of its own, so a long-lived PR easily holds
     * more than 100 and `first: 100` would only ever see the oldest ones.
     * Threads are read in full (`first` + `after`): an unresolved thread is
     * a signal wherever it sits. Within a thread, the newest 100 comments
     * are kept (`last`): the reviews that matter are each reviewer's latest.
     * The `with*` flags drop a connection that is already complete from the
     * follow-up pages.
     */
    private const REVIEWS_QUERY = <<<'GQL'
query($owner: String!, $repo: String!, $pr: Int!, $reviewsBefore: String, $threadsAfter: String, $withReviews: Boolean = true, $withThreads: Boolean = true) {
  repository(owner: $owner, name: $repo) {
    pullRequest(number: $pr) {
      author { login }
      reviews(last: 100, before: $reviewsBefore) @include(if: $withReviews) {
        pageInfo { hasPreviousPage startCursor }
        nodes {
          id
          author { login __typename }
          state
          submittedAt
          body
        }
      }
      reviewThreads(first: 100, after: $threadsAfter) @include(if: $withThreads) {
        pageInfo { hasNextPage endCursor }
        nodes {
          isResolved
          comments(last: 100) {
            nodes { pullRequestReview { id } }
          }
        }
      }
    }
  }
}
GQL;

    /** Upper bound on review/thread pages per fetch (100 items each). */
    public const MAX_REVIEW_PAGES = 20;

    public function __construct(
        private readonly ProcessRunnerInterface $runner,
        private readonly Time $time,
    ) {
    }

    /**
     * @param array<string, mixed> $item
     */
    private function prFromItem(array $item): PrInfo
    {
        return new PrInfo(
            number: (int) $item['number'],
            title: (string) $item['title'],
            state: (string) $item['state'],
            isDraft: (bool) $item['isDraft'],
            url: (string) $item['url'],
            mergedAt: null !== $item['mergedAt'] ? (string) $item['mergedAt'] : null,
            baseRefName: null !== ($item['baseRefName'] ?? null) ? (string) $item['baseRefName'] : null,
        );
    }

    public function prForBranch(string $repoSlug, string $branch): ?PrInfo
    {
        $out = $this->runner->run([
            'gh', 'pr', 'list', '--repo', $repoSlug, '--head', $branch,
            '--state', 'all', '--json', 'number,title,state,isDraft,mergedAt,url,baseRefName',
            '--limit', '1',
        ], timeout: self::GH_CALL_TIMEOUT_S);
        $items = Json::decodeList($out, 'gh pr list output');
        if ([] === $items) {
            return null;
        }

        return $this->prFromItem($items[0]);
    }

    /**
     * @param list<string> $branches
     *
     * @return array<string, PrInfo>
     */
    public function prsForBranches(string $repoSlug, array $branches): array
    {
        if ([] === $branches) {
            return [];
        }
        $out = $this->runner->run($this->prListArgv($repoSlug, \count($branches)), timeout: self::GH_CALL_TIMEOUT_S);

        return $this->matchHeads(Json::decodeList($out, 'gh pr list output'), $branches);
    }

    /**
     * @param list<array{0: string, 1: list<string>}> $repoBranches [slug, branches]
     *
     * @return array<string, array<string, PrInfo>> slug => branch => PrInfo
     */
    public function prsForBranchesBulk(array $repoBranches): array
    {
        if ([] === $repoBranches) {
            return [];
        }

        $commands = array_map(fn (array $pair): array => $this->prListArgv($pair[0], \count($pair[1])), $repoBranches);
        // check: false: one repository's gh failure must not hide the others' PRs.
        $results = $this->runner->runParallel($commands, check: false, timeout: self::GH_CALL_TIMEOUT_S);
        $all = [];
        foreach ($results as $i => $out) {
            [$slug, $branches] = $repoBranches[$i];
            $items = Json::tryDecodeArray($out);
            $all[$slug] = null === $items ? [] : $this->matchHeads($items, $branches);
        }

        return $all;
    }

    /**
     * One `gh pr list` over every PR of the repository, sized for $branchCount
     * branches (five PRs per branch, between 50 and 500).
     *
     * @return list<string>
     */
    private function prListArgv(string $repoSlug, int $branchCount): array
    {
        return [
            'gh', 'pr', 'list', '--repo', $repoSlug,
            '--state', 'all', '--json',
            'number,title,state,isDraft,mergedAt,url,headRefName,baseRefName',
            '--limit', (string) min(max($branchCount * 5, 50), 500),
        ];
    }

    /**
     * The first (newest) PR of each wanted head branch.
     *
     * @param array<mixed> $items    `gh pr list` items
     * @param list<string> $branches
     *
     * @return array<string, PrInfo>
     */
    private function matchHeads(array $items, array $branches): array
    {
        $wanted = array_fill_keys($branches, true);
        $result = [];
        foreach ($items as $item) {
            $head = $item['headRefName'] ?? null;
            if (null === $head || !isset($wanted[$head]) || isset($result[$head])) {
                continue;
            }
            $result[$head] = $this->prFromItem($item);
        }

        return $result;
    }

    /**
     * @param list<string> $ignoreChecks
     */
    private static function isIgnored(CiCheck $check, array $ignoreChecks): bool
    {
        foreach ($ignoreChecks as $marker) {
            if (str_contains($check->name, strtolower((string) $marker))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The pure CI evaluation behind ciStatus(): red | pending | green.
     *
     * @param list<CiCheck> $rollup
     * @param list<string>  $ignoreChecks
     */
    public static function evaluateCi(array $rollup, array $ignoreChecks = []): string
    {
        $pending = false;
        foreach (self::latestRuns($rollup) as $check) {
            if (self::isIgnored($check, $ignoreChecks)) {
                continue;
            }
            if ($check->failed) {
                return 'red';
            }
            if ($check->pending) {
                $pending = true;
            }
        }

        return $pending ? 'pending' : 'green';
    }

    /**
     * Keeps only the most recent run of each check run: a workflow triggered
     * twice on one commit leaves both runs in the rollup, and the older one
     * (often cancelled or failed, then superseded) must not decide the state.
     * Legacy status contexts carry no run key and are all kept.
     *
     * @param list<CiCheck> $rollup
     *
     * @return list<CiCheck>
     */
    private static function latestRuns(array $rollup): array
    {
        $kept = [];
        foreach ($rollup as $i => $check) {
            if (null === $check->runKey) {
                $kept[$i] = $check;
                continue;
            }
            $key = 'run:'.$check->runKey;
            if (!isset($kept[$key]) || $check->startedAt >= $kept[$key]->startedAt) {
                $kept[$key] = $check;
            }
        }

        return array_values($kept);
    }

    public function ciStatus(string $repoSlug, int $prNumber, array $ignoreChecks = []): string
    {
        $out = $this->runner->run([
            'gh', 'pr', 'view', (string) $prNumber, '--repo', $repoSlug,
            '--json', 'statusCheckRollup',
        ], timeout: self::GH_CALL_TIMEOUT_S);
        $data = Json::decodeArray($out, 'gh pr view output');
        $rollup = array_values(array_map(CiCheck::fromRollup(...), $data['statusCheckRollup'] ?? []));

        return self::evaluateCi($rollup, $ignoreChecks);
    }

    public function reviewVerdict(string $repoSlug, int $prNumber, array $botWhitelist): ?string
    {
        $anchor = $this->readyAnchor($repoSlug, $prNumber);
        $prReviews = $this->fetchReviews($repoSlug, $prNumber);

        return ReviewVerdict::evaluate($prReviews->reviews, $prReviews->threads, $anchor, $prReviews->author, $botWhitelist);
    }

    /** GitHub's last "ready for review" event, else the PR's creation. */
    private function readyAnchor(string $repoSlug, int $prNumber): \DateTimeImmutable
    {
        $ownerRepo = explode('/', $repoSlug, 2);
        $out = $this->runner->run([
            'gh', 'api', 'graphql',
            '-f', 'query='.self::TIMELINE_QUERY,
            '-F', 'owner='.$ownerRepo[0], '-F', 'repo='.$ownerRepo[1], '-F', 'pr='.$prNumber,
        ], timeout: self::GH_CALL_TIMEOUT_S);
        $data = Json::decodeArray($out, 'gh api graphql timeline output');
        $pr = $data['data']['repository']['pullRequest'];
        $nodes = array_values(array_filter($pr['timelineItems']['nodes'] ?? [], static fn ($n) => null !== $n));
        if ([] !== $nodes) {
            return $this->time->parseTs((string) $nodes[\count($nodes) - 1]['createdAt']);
        }

        return $this->time->parseTs((string) $pr['createdAt']);
    }

    public function fetchReviews(string $repoSlug, int $prNumber): PrReviews
    {
        $ownerRepo = explode('/', $repoSlug, 2);
        $author = null;
        /** @var list<list<array<string, mixed>>> $reviewPages newest page first */
        $reviewPages = [];
        /** @var list<array<string, mixed>> $threads */
        $threads = [];
        $reviewsBefore = null;
        $threadsAfter = null;
        $withReviews = true;
        $withThreads = true;
        for ($page = 0; $page < self::MAX_REVIEW_PAGES && ($withReviews || $withThreads); ++$page) {
            $argv = [
                'gh', 'api', 'graphql',
                '-f', 'query='.self::REVIEWS_QUERY,
                '-F', 'owner='.$ownerRepo[0], '-F', 'repo='.$ownerRepo[1], '-F', 'pr='.$prNumber,
            ];
            if (null !== $reviewsBefore) {
                array_push($argv, '-f', 'reviewsBefore='.$reviewsBefore);
            }
            if (null !== $threadsAfter) {
                array_push($argv, '-f', 'threadsAfter='.$threadsAfter);
            }
            if (!$withReviews) {
                array_push($argv, '-F', 'withReviews=false');
            }
            if (!$withThreads) {
                array_push($argv, '-F', 'withThreads=false');
            }
            $out = $this->runner->run($argv, timeout: self::GH_CALL_TIMEOUT_S);
            $data = Json::decodeArray($out, 'gh api graphql reviews output');
            $pr = $data['data']['repository']['pullRequest'];
            $author ??= (string) $pr['author']['login'];

            if ($withReviews) {
                $reviewPages[] = array_values(array_filter($pr['reviews']['nodes'] ?? [], static fn ($n) => null !== $n));
                $info = $pr['reviews']['pageInfo'] ?? [];
                $reviewsBefore = true === ($info['hasPreviousPage'] ?? false) ? ($info['startCursor'] ?? null) : null;
                $withReviews = null !== $reviewsBefore;
            }
            if ($withThreads) {
                array_push($threads, ...array_values(array_filter($pr['reviewThreads']['nodes'] ?? [], static fn ($n) => null !== $n)));
                $info = $pr['reviewThreads']['pageInfo'] ?? [];
                $threadsAfter = true === ($info['hasNextPage'] ?? false) ? ($info['endCursor'] ?? null) : null;
                $withThreads = null !== $threadsAfter;
            }
        }
        // Pages were read newest first: put them back in chronological order.
        $reviews = array_merge(...array_reverse($reviewPages));

        return new PrReviews(
            (string) $author,
            array_map(ReviewNode::fromNode(...), $reviews),
            array_map(ReviewThread::fromNode(...), $threads),
        );
    }

    public function markReady(string $repoSlug, int $prNumber): void
    {
        $this->runner->run(['gh', 'pr', 'ready', (string) $prNumber, '--repo', $repoSlug], timeout: self::GH_CALL_TIMEOUT_S);
    }

    public function markDraft(string $repoSlug, int $prNumber): void
    {
        $this->runner->run(['gh', 'pr', 'ready', (string) $prNumber, '--repo', $repoSlug, '--undo'], timeout: self::GH_CALL_TIMEOUT_S);
    }

    public function prState(string $repoSlug, int $prNumber): string
    {
        $out = $this->runner->run([
            'gh', 'pr', 'view', (string) $prNumber, '--repo', $repoSlug,
            '--json', 'state,mergedAt',
        ], timeout: self::GH_CALL_TIMEOUT_S);
        $data = Json::decodeArray($out, 'gh pr view output');

        return self::normalizeState((string) ($data['state'] ?? ''), $data['mergedAt'] ?? null);
    }

    /**
     * The prState() answer from a PR's raw state and mergedAt: a PR with a
     * mergedAt is MERGED whatever its state says.
     */
    public static function normalizeState(string $state, mixed $mergedAt): string
    {
        $state = strtoupper($state);

        return 'MERGED' === $state || !empty($mergedAt) ? 'MERGED' : $state;
    }

    /** @return array<int, string> */
    public function rerunCi(string $repoSlug, string $branch): array
    {
        $out = $this->runner->run([
            'gh', 'run', 'list', '--repo', $repoSlug,
            '--branch', $branch, '--status', 'completed',
            '--limit', '20', '--json', 'databaseId,workflowName',
        ], timeout: self::GH_CALL_TIMEOUT_S);
        $runs = Json::decodeList($out, 'gh run list output');
        if ([] === $runs) {
            throw new PabloError("no completed workflow runs found on branch {$branch}");
        }
        $seen = [];
        $latest = [];
        foreach ($runs as $run) {
            $wf = $run['workflowName'];
            if (!isset($seen[$wf])) {
                $seen[$wf] = true;
                $latest[] = $run;
            }
        }
        $rerunIds = [];
        foreach ($latest as $run) {
            $runId = (string) $run['databaseId'];
            $this->runner->run(['gh', 'run', 'rerun', $runId, '--repo', $repoSlug], timeout: self::GH_CALL_TIMEOUT_S);
            $rerunIds[] = $runId;
        }

        return $rerunIds;
    }
}
