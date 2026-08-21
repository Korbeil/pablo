<?php

declare(strict_types=1);

namespace Pablo\Provider\Gh;

use Pablo\Support\PabloError;
use Pablo\Support\Proc;

/**
 * GitHub PR plumbing via the gh CLI: CI status, review evaluation, draft/ready
 * toggling, merge detection.
 */
final class GhPr
{
    public const GH_CALL_TIMEOUT_S = 20;

    public const CI_FAILURE_CONCLUSIONS = [
        'FAILURE', 'TIMED_OUT', 'CANCELLED', 'ACTION_REQUIRED', 'STARTUP_FAILURE', 'ERROR',
    ];

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

    /** @var callable|null test seams */
    private static $prForBranch;
    /** @var callable|null */
    private static $prsForBranches;

    public static function setPrForBranch(?callable $fn): void
    {
        self::$prForBranch = $fn;
    }

    public static function setPrsForBranches(?callable $fn): void
    {
        self::$prsForBranches = $fn;
    }

    public static function prForBranch(string $repoSlug, string $branch): ?PrInfo
    {
        if (null !== self::$prForBranch) {
            return (self::$prForBranch)($repoSlug, $branch);
        }
        $out = Proc::run([
            'gh', 'pr', 'list', '--repo', $repoSlug, '--head', $branch,
            '--state', 'all', '--json', 'number,title,state,isDraft,mergedAt,url,baseRefName',
            '--limit', '1',
        ], timeout: self::GH_CALL_TIMEOUT_S);
        /** @var array<int, array<string, mixed>> $items */
        $items = json_decode($out, true);
        if ([] === $items) {
            return null;
        }
        $item = $items[0];

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

    /**
     * @param list<string> $branches
     *
     * @return array<string, PrInfo>
     */
    public static function prsForBranches(string $repoSlug, array $branches): array
    {
        if (null !== self::$prsForBranches) {
            return (self::$prsForBranches)($repoSlug, $branches);
        }
        if ([] === $branches) {
            return [];
        }
        $limit = min(max(\count($branches) * 5, 50), 500);
        $out = Proc::run([
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
            $result[$head] = new PrInfo(
                number: (int) $item['number'],
                title: (string) $item['title'],
                state: (string) $item['state'],
                isDraft: (bool) $item['isDraft'],
                url: (string) $item['url'],
                mergedAt: null !== $item['mergedAt'] ? (string) $item['mergedAt'] : null,
                baseRefName: null !== ($item['baseRefName'] ?? null) ? (string) $item['baseRefName'] : null,
            );
        }

        return $result;
    }

    /**
     * @param list<array{0: string, 1: list<string>}> $repoBranches [slug, branches]
     *
     * @return array<string, array<string, PrInfo>> slug => branch => PrInfo
     */
    public static function prsForBranchesBulk(array $repoBranches): array
    {
        if ([] === $repoBranches) {
            return [];
        }

        if (null !== self::$prsForBranches) {
            $result = [];
            foreach ($repoBranches as [$slug, $branches]) {
                $result[$slug] = (self::$prsForBranches)($slug, $branches);
            }

            return $result;
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

        $results = Proc::runParallel($commands, check: false, timeout: self::GH_CALL_TIMEOUT_S);
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
                $all[$slug][$head] = new PrInfo(
                    number: (int) $item['number'],
                    title: (string) $item['title'],
                    state: (string) $item['state'],
                    isDraft: (bool) $item['isDraft'],
                    url: (string) $item['url'],
                    mergedAt: null !== $item['mergedAt'] ? (string) $item['mergedAt'] : null,
                    baseRefName: null !== ($item['baseRefName'] ?? null) ? (string) $item['baseRefName'] : null,
                );
            }
        }

        return $all;
    }

    /**
     * @param array<string, mixed> $check
     * @param list<string>         $ignoreChecks
     */
    private static function isIgnored(array $check, array $ignoreChecks): bool
    {
        if ([] === $ignoreChecks) {
            return false;
        }
        $name = strtolower((string) ($check['name'] ?? $check['workflowName'] ?? $check['context'] ?? ''));
        foreach ($ignoreChecks as $marker) {
            if (str_contains($name, strtolower((string) $marker))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $rollup
     * @param list<string>               $ignoreChecks
     */
    public static function evaluateCi(array $rollup, array $ignoreChecks = []): string
    {
        $pending = false;
        foreach ($rollup as $check) {
            if (self::isIgnored($check, $ignoreChecks)) {
                continue;
            }
            if (\array_key_exists('state', $check)) { // StatusContext
                $state = $check['state'];
                if (\in_array($state, ['FAILURE', 'ERROR'], true)) {
                    return 'red';
                }
                if (\in_array($state, ['PENDING', 'EXPECTED'], true)) {
                    $pending = true;
                }
                continue;
            }
            if (($check['status'] ?? null) !== 'COMPLETED') {
                if (\in_array(strtoupper((string) ($check['conclusion'] ?? '')), self::CI_FAILURE_CONCLUSIONS, true)) {
                    return 'red';
                }
                $pending = true;
                continue;
            }
            if (\in_array(strtoupper((string) ($check['conclusion'] ?? '')), self::CI_FAILURE_CONCLUSIONS, true)) {
                return 'red';
            }
        }

        return $pending ? 'pending' : 'green';
    }

    /**
     * @param list<string> $ignoreChecks
     */
    /** @var callable|null test seams */
    /** @var callable|null */
    private static $ciStatus;
    /** @var callable|null */
    private static $isMerged;
    /** @var callable|null */
    private static $markReady;
    /** @var callable|null */
    private static $markDraft;
    /** @var callable|null */
    private static $readyAnchor;
    /** @var callable|null */
    private static $fetchReviews;
    /** @var callable|null */
    private static $evaluateReviews;
    /** @var callable|null */
    private static $rerunCi;

    public static function setCiStatus(?callable $fn): void
    {
        self::$ciStatus = $fn;
    }

    public static function setIsMerged(?callable $fn): void
    {
        self::$isMerged = $fn;
    }

    public static function setMarkReady(?callable $fn): void
    {
        self::$markReady = $fn;
    }

    public static function setMarkDraft(?callable $fn): void
    {
        self::$markDraft = $fn;
    }

    public static function setReadyAnchor(?callable $fn): void
    {
        self::$readyAnchor = $fn;
    }

    public static function setFetchReviews(?callable $fn): void
    {
        self::$fetchReviews = $fn;
    }

    public static function setEvaluateReviews(?callable $fn): void
    {
        self::$evaluateReviews = $fn;
    }

    public static function setRerunCi(?callable $fn): void
    {
        self::$rerunCi = $fn;
    }

    /** @param list<string> $ignoreChecks */
    public static function ciStatus(string $repoSlug, int $prNumber, array $ignoreChecks = []): string
    {
        if (null !== self::$ciStatus) {
            return (self::$ciStatus)($repoSlug, $prNumber, $ignoreChecks);
        }
        $out = Proc::run([
            'gh', 'pr', 'view', (string) $prNumber, '--repo', $repoSlug,
            '--json', 'statusCheckRollup',
        ], timeout: self::GH_CALL_TIMEOUT_S);
        /** @var array<string, mixed> $data */
        $data = json_decode($out, true);

        return self::evaluateCi($data['statusCheckRollup'] ?? [], $ignoreChecks);
    }

    public static function readyAnchor(string $repoSlug, int $prNumber): \DateTimeImmutable
    {
        if (null !== self::$readyAnchor) {
            return (self::$readyAnchor)($repoSlug, $prNumber);
        }
        $ownerRepo = explode('/', $repoSlug, 2);
        $out = Proc::run([
            'gh', 'api', 'graphql',
            '-f', 'query='.self::TIMELINE_QUERY,
            '-F', 'owner='.$ownerRepo[0], '-F', 'repo='.$ownerRepo[1], '-F', 'pr='.$prNumber,
        ], timeout: self::GH_CALL_TIMEOUT_S);
        /** @var array<string, mixed> $data */
        $data = json_decode($out, true);
        $pr = $data['data']['repository']['pullRequest'];
        $nodes = array_values(array_filter($pr['timelineItems']['nodes'] ?? [], static fn ($n) => null !== $n));
        if ([] !== $nodes) {
            return Proc::parseTs((string) $nodes[\count($nodes) - 1]['createdAt']);
        }

        return Proc::parseTs((string) $pr['createdAt']);
    }

    /** @return array{0: string, 1: list<array<string, mixed>>} (pr_author_login, raw review nodes) */
    public static function fetchReviews(string $repoSlug, int $prNumber): array
    {
        if (null !== self::$fetchReviews) {
            return (self::$fetchReviews)($repoSlug, $prNumber);
        }
        $ownerRepo = explode('/', $repoSlug, 2);
        $out = Proc::run([
            'gh', 'api', 'graphql',
            '-f', 'query='.self::REVIEWS_QUERY,
            '-F', 'owner='.$ownerRepo[0], '-F', 'repo='.$ownerRepo[1], '-F', 'pr='.$prNumber,
        ], timeout: self::GH_CALL_TIMEOUT_S);
        /** @var array<string, mixed> $data */
        $data = json_decode($out, true);
        $pr = $data['data']['repository']['pullRequest'];

        return [
            (string) $pr['author']['login'],
            array_values(array_filter($pr['reviews']['nodes'] ?? [], static fn ($n) => null !== $n)),
        ];
    }

    /**
     * @param list<array<string, mixed>> $reviews
     * @param list<string>               $botWhitelist
     */
    public static function evaluateReviews(array $reviews, \DateTimeImmutable $anchor, string $author, array $botWhitelist): ?string
    {
        if (null !== self::$evaluateReviews) {
            return (self::$evaluateReviews)($reviews, $anchor, $author, $botWhitelist);
        }
        $latest = [];
        foreach ($reviews as $node) {
            $reviewAuthor = $node['author']['login'] ?? null;
            if (null === $reviewAuthor || $reviewAuthor === $author) {
                continue;
            }
            $isBot = ($node['author']['__typename'] ?? null) === 'Bot' || str_ends_with($reviewAuthor, '[bot]');
            if ($isBot && !\in_array($reviewAuthor, $botWhitelist, true)) {
                continue;
            }
            if (!\in_array($node['state'] ?? null, ['APPROVED', 'CHANGES_REQUESTED', 'COMMENTED'], true)) {
                continue;
            }
            $submitted = $node['submittedAt'] ?? null;
            if (null === $submitted || Proc::parseTs((string) $submitted) <= $anchor) {
                continue;
            }
            $current = $latest[$reviewAuthor] ?? null;
            if (null === $current || Proc::parseTs((string) $submitted) > Proc::parseTs((string) $current['submittedAt'])) {
                $latest[$reviewAuthor] = $node;
            }
        }
        $verdicts = [];
        foreach ($latest as $node) {
            $verdicts[$node['state']] = true;
        }
        if (isset($verdicts['CHANGES_REQUESTED']) || isset($verdicts['COMMENTED'])) {
            return 'changes';
        }
        if (isset($verdicts['APPROVED'])) {
            return 'approved';
        }

        return null;
    }

    public static function markReady(string $repoSlug, int $prNumber): void
    {
        if (null !== self::$markReady) {
            (self::$markReady)($repoSlug, $prNumber);

            return;
        }
        Proc::run(['gh', 'pr', 'ready', (string) $prNumber, '--repo', $repoSlug], timeout: self::GH_CALL_TIMEOUT_S);
    }

    public static function markDraft(string $repoSlug, int $prNumber): void
    {
        if (null !== self::$markDraft) {
            (self::$markDraft)($repoSlug, $prNumber);

            return;
        }
        Proc::run(['gh', 'pr', 'ready', (string) $prNumber, '--repo', $repoSlug, '--undo'], timeout: self::GH_CALL_TIMEOUT_S);
    }

    public static function isMerged(string $repoSlug, int $prNumber): bool
    {
        if (null !== self::$isMerged) {
            return (self::$isMerged)($repoSlug, $prNumber);
        }
        $out = Proc::run([
            'gh', 'pr', 'view', (string) $prNumber, '--repo', $repoSlug,
            '--json', 'state,mergedAt',
        ], timeout: self::GH_CALL_TIMEOUT_S);
        /** @var array<string, mixed> $data */
        $data = json_decode($out, true);

        return 'MERGED' === $data['state'] || !empty($data['mergedAt']);
    }

    /** @return array<int, string> */
    public static function rerunCi(string $repoSlug, string $branch): array
    {
        if (null !== self::$rerunCi) {
            return (self::$rerunCi)($repoSlug, $branch);
        }
        $out = Proc::run([
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
            Proc::run(['gh', 'run', 'rerun', $runId, '--repo', $repoSlug], timeout: self::GH_CALL_TIMEOUT_S);
            $rerunIds[] = $runId;
        }

        return $rerunIds;
    }
}
