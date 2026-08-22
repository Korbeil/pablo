<?php

declare(strict_types=1);

namespace Pablo\Provider\Gh;

use Pablo\Domain\Time;
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

    private const REVIEWS_QUERY = <<<'GQL'
query($owner: String!, $repo: String!, $pr: Int!) {
  repository(owner: $owner, name: $repo) {
    pullRequest(number: $pr) {
      author { login }
      reviews(first: 100) {
        nodes {
          author { login __typename }
          state
          submittedAt
        }
      }
    }
  }
}
GQL;

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
        /** @var array<int, array<string, mixed>> $items */
        $items = json_decode($out, true);
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
        $limit = min(max(\count($branches) * 5, 50), 500);
        $out = $this->runner->run([
            'gh', 'pr', 'list', '--repo', $repoSlug,
            '--state', 'all', '--json',
            'number,title,state,isDraft,mergedAt,url,headRefName,baseRefName',
            '--limit', (string) $limit,
        ], timeout: self::GH_CALL_TIMEOUT_S);
        $wanted = array_fill_keys($branches, true);
        $result = [];
        /** @var array<int, array<string, mixed>> $items */
        $items = json_decode($out, true);
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
     * @param list<array{0: string, 1: list<string>}> $repoBranches [slug, branches]
     *
     * @return array<string, array<string, PrInfo>> slug => branch => PrInfo
     */
    public function prsForBranchesBulk(array $repoBranches): array
    {
        if ([] === $repoBranches) {
            return [];
        }

        $commands = [];
        $slugIndex = [];
        $branchesByIndex = [];
        foreach ($repoBranches as $i => [$slug, $branches]) {
            $slugIndex[$i] = $slug;
            $branchesByIndex[$i] = $branches;
            $limit = min(max(\count($branches) * 5, 50), 500);
            $commands[] = [
                'gh', 'pr', 'list', '--repo', $slug,
                '--state', 'all', '--json',
                'number,title,state,isDraft,mergedAt,url,headRefName,baseRefName',
                '--limit', (string) $limit,
            ];
        }

        $results = $this->runner->runParallel($commands, check: false, timeout: self::GH_CALL_TIMEOUT_S);
        $all = [];
        foreach ($results as $i => $out) {
            $slug = $slugIndex[$i];
            $branches = $branchesByIndex[$i];
            $wanted = array_fill_keys($branches, true);
            $all[$slug] = [];
            try {
                /** @var array<int, array<string, mixed>> $items */
                $items = json_decode($out, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                continue;
            }
            foreach ($items as $item) {
                $head = $item['headRefName'] ?? null;
                if (null === $head || !isset($wanted[$head]) || isset($all[$slug][$head])) {
                    continue;
                }
                $all[$slug][$head] = $this->prFromItem($item);
            }
        }

        return $all;
    }

    /**
     * @param list<string> $ignoreChecks
     */
    private function isIgnored(CiCheck $check, array $ignoreChecks): bool
    {
        foreach ($ignoreChecks as $marker) {
            if (str_contains($check->name, strtolower((string) $marker))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<CiCheck> $rollup
     * @param list<string>  $ignoreChecks
     */
    public function evaluateCi(array $rollup, array $ignoreChecks = []): string
    {
        $pending = false;
        foreach ($rollup as $check) {
            if ($this->isIgnored($check, $ignoreChecks)) {
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

    public function ciStatus(string $repoSlug, int $prNumber, array $ignoreChecks = []): string
    {
        $out = $this->runner->run([
            'gh', 'pr', 'view', (string) $prNumber, '--repo', $repoSlug,
            '--json', 'statusCheckRollup',
        ], timeout: self::GH_CALL_TIMEOUT_S);
        /** @var array<string, mixed> $data */
        $data = json_decode($out, true);
        $rollup = array_values(array_map(CiCheck::fromRollup(...), $data['statusCheckRollup'] ?? []));

        return $this->evaluateCi($rollup, $ignoreChecks);
    }

    public function readyAnchor(string $repoSlug, int $prNumber): \DateTimeImmutable
    {
        $ownerRepo = explode('/', $repoSlug, 2);
        $out = $this->runner->run([
            'gh', 'api', 'graphql',
            '-f', 'query='.self::TIMELINE_QUERY,
            '-F', 'owner='.$ownerRepo[0], '-F', 'repo='.$ownerRepo[1], '-F', 'pr='.$prNumber,
        ], timeout: self::GH_CALL_TIMEOUT_S);
        /** @var array<string, mixed> $data */
        $data = json_decode($out, true);
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
        $out = $this->runner->run([
            'gh', 'api', 'graphql',
            '-f', 'query='.self::REVIEWS_QUERY,
            '-F', 'owner='.$ownerRepo[0], '-F', 'repo='.$ownerRepo[1], '-F', 'pr='.$prNumber,
        ], timeout: self::GH_CALL_TIMEOUT_S);
        /** @var array<string, mixed> $data */
        $data = json_decode($out, true);
        $pr = $data['data']['repository']['pullRequest'];
        $nodes = array_values(array_filter($pr['reviews']['nodes'] ?? [], static fn ($n) => null !== $n));

        return new PrReviews(
            (string) $pr['author']['login'],
            array_map(ReviewNode::fromNode(...), $nodes),
        );
    }

    /**
     * @param list<ReviewNode> $reviews
     * @param list<string>     $botWhitelist
     */
    public function evaluateReviews(array $reviews, \DateTimeImmutable $anchor, string $author, array $botWhitelist): ?string
    {
        $latest = [];
        foreach ($reviews as $node) {
            $reviewAuthor = $node->login;
            if ('' === $reviewAuthor || $reviewAuthor === $author) {
                continue;
            }
            if ($node->isBot() && !\in_array($reviewAuthor, $botWhitelist, true)) {
                continue;
            }
            if (!\in_array($node->state, ['APPROVED', 'CHANGES_REQUESTED', 'COMMENTED'], true)) {
                continue;
            }
            $submitted = $node->submittedAt;
            if (null === $submitted || $this->time->parseTs($submitted) <= $anchor) {
                continue;
            }
            $current = $latest[$reviewAuthor] ?? null;
            if (null === $current || $this->time->parseTs($submitted) > $this->time->parseTs((string) $current->submittedAt)) {
                $latest[$reviewAuthor] = $node;
            }
        }
        $verdicts = [];
        foreach ($latest as $node) {
            $verdicts[$node->state] = true;
        }
        if (isset($verdicts['CHANGES_REQUESTED']) || isset($verdicts['COMMENTED'])) {
            return 'changes';
        }
        if (isset($verdicts['APPROVED'])) {
            return 'approved';
        }

        return null;
    }

    public function markReady(string $repoSlug, int $prNumber): void
    {
        $this->runner->run(['gh', 'pr', 'ready', (string) $prNumber, '--repo', $repoSlug], timeout: self::GH_CALL_TIMEOUT_S);
    }

    public function markDraft(string $repoSlug, int $prNumber): void
    {
        $this->runner->run(['gh', 'pr', 'ready', (string) $prNumber, '--repo', $repoSlug, '--undo'], timeout: self::GH_CALL_TIMEOUT_S);
    }

    public function isMerged(string $repoSlug, int $prNumber): bool
    {
        $out = $this->runner->run([
            'gh', 'pr', 'view', (string) $prNumber, '--repo', $repoSlug,
            '--json', 'state,mergedAt',
        ], timeout: self::GH_CALL_TIMEOUT_S);
        /** @var array<string, mixed> $data */
        $data = json_decode($out, true);

        return 'MERGED' === $data['state'] || !empty($data['mergedAt']);
    }

    /** @return array<int, string> */
    public function rerunCi(string $repoSlug, string $branch): array
    {
        $out = $this->runner->run([
            'gh', 'run', 'list', '--repo', $repoSlug,
            '--branch', $branch, '--status', 'completed',
            '--limit', '20', '--json', 'databaseId,workflowName',
        ], timeout: self::GH_CALL_TIMEOUT_S);
        /** @var array<int, array<string, mixed>> $runs */
        $runs = json_decode($out, true);
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
