<?php

declare(strict_types=1);

namespace Pablo\Provider\Forge\Gitea;

use Pablo\Provider\Forge\CiCheck;
use Pablo\Provider\Forge\ForgeInterface;
use Pablo\Provider\Forge\PrInfo;
use Pablo\Provider\Forge\PrSnapshot;
use Pablo\Provider\Forge\RepoRef;
use Pablo\Support\Json;
use Pablo\Support\PabloError;
use Pablo\Support\ProcessRunnerInterface;

/**
 * Gitea pull-request plumbing via the Gitea CLI, gt (`gt api`, REST only:
 * Gitea has no GraphQL): CI status from the head commit's combined status
 * (Gitea Actions and external CI both report commit statuses), review
 * evaluation (GiteaReviewVerdict), draft/ready toggling (`gt pr ready`:
 * Gitea's draft is a "WIP:" title prefix), merge detection, Actions re-runs.
 *
 * Bound to one Gitea host (onHost(), done by ForgeRegistry); the service
 * itself is bound to gitea.com.
 */
final class GiteaForge implements ForgeInterface
{
    /**
     * Pages of the pull request list a branch lookup reads, most recently
     * updated first (Gitea's list has no head-branch filter): a branch whose
     * pull requests are all older than that many updates is taken as having
     * none.
     */
    public const BRANCH_LOOKUP_PAGES = 6;

    /** What Gitea (and gt pr ready) take as a draft title. */
    private const DRAFT_PREFIX = '/^(wip:\s*|\[wip\]\s*)/i';

    /** Commit statuses that fail CI ("warning" does not). */
    private const FAILED_STATUSES = ['failure', 'error'];

    private string $host = RepoRef::DEFAULT_HOSTS[RepoRef::GITEA];

    private readonly GiteaApi $api;

    public function __construct(private readonly ProcessRunnerInterface $runner)
    {
        $this->api = new GiteaApi($runner);
    }

    /** This forge, on another Gitea host (a self-hosted instance). */
    public function onHost(string $host): self
    {
        $forge = clone $this;
        $forge->host = $host;

        return $forge;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function name(): string
    {
        return RepoRef::GITEA;
    }

    public function prUrl(string $repoSlug, int $prNumber): string
    {
        return (new RepoRef(RepoRef::GITEA, $this->host, $repoSlug))->prUrl($prNumber);
    }

    public static function isDraftTitle(string $title): bool
    {
        return 1 === preg_match(self::DRAFT_PREFIX, $title);
    }

    /**
     * OPEN | CLOSED | MERGED of a REST pull request (merged: closed with merged true).
     *
     * @param array<mixed> $pr
     */
    public static function stateOf(array $pr): string
    {
        $state = true === ($pr['merged'] ?? false) ? 'MERGED' : strtoupper((string) ($pr['state'] ?? ''));

        return PrInfo::normalizeState($state, $pr['merged_at'] ?? null);
    }

    /** @param array<mixed> $pr a REST pull request */
    private static function prFrom(array $pr): PrInfo
    {
        $title = (string) ($pr['title'] ?? '');

        return new PrInfo(
            number: (int) ($pr['number'] ?? 0),
            title: $title,
            state: self::stateOf($pr),
            isDraft: true === ($pr['draft'] ?? false) || self::isDraftTitle($title),
            url: (string) ($pr['html_url'] ?? ''),
            mergedAt: isset($pr['merged_at']) ? (string) $pr['merged_at'] : null,
            baseRefName: isset($pr['base']['ref']) ? (string) $pr['base']['ref'] : null,
        );
    }

    public function prForBranch(string $repoSlug, string $branch): ?PrInfo
    {
        return $this->prsForBranches($repoSlug, [$branch])[$branch] ?? null;
    }

    /**
     * The newest pull request of each branch, from one walk down the pull
     * request list (most recently updated first) until every branch has one
     * or BRANCH_LOOKUP_PAGES pages are read: the page where a branch's
     * first pull request shows is the last one read for it (a newer one
     * would have sorted before, unless an older one was updated since).
     * Only pull requests from the repository itself count, never a fork's
     * branch of the same name.
     */
    public function prsForBranches(string $repoSlug, array $branches): array
    {
        $wanted = array_fill_keys($branches, true);
        if ([] === $wanted) {
            return [];
        }
        /** @var array<string, array<mixed>> $found */
        $found = [];
        $endpoint = GiteaApi::repo($repoSlug).'/pulls';
        for ($page = 1; $page <= self::BRANCH_LOOKUP_PAGES; ++$page) {
            $items = GiteaApi::items($this->api->get($this->host, $endpoint, [
                'state' => 'all', 'sort' => 'recentupdate', 'limit' => GiteaApi::PER_PAGE, 'page' => $page,
            ]));
            foreach ($items as $pr) {
                $head = (string) ($pr['head']['ref'] ?? '');
                if (!isset($wanted[$head]) || !self::fromRepo($pr, $repoSlug)) {
                    continue;
                }
                if (!isset($found[$head]) || (int) ($pr['number'] ?? 0) > (int) ($found[$head]['number'] ?? 0)) {
                    $found[$head] = $pr;
                }
            }
            if (\count($items) < GiteaApi::PER_PAGE || \count($found) === \count($wanted)) {
                break;
            }
        }

        return array_map(self::prFrom(...), $found);
    }

    /** @param array<mixed> $pr */
    private static function fromRepo(array $pr, string $repoSlug): bool
    {
        $headRepo = $pr['head']['repo']['full_name'] ?? null;

        return !\is_string($headRepo) || 0 === strcasecmp($headRepo, $repoSlug);
    }

    public function prsForBranchesBulk(array $repoBranches): array
    {
        $all = [];
        foreach ($repoBranches as [$slug, $branches]) {
            $all[$slug] ??= [];
            try {
                $all[$slug] += $this->prsForBranches($slug, $branches);
            } catch (\Throwable) {
                // one repository's failure must not hide the others' pull requests
            }
        }

        return $all;
    }

    public function ciStatus(string $repoSlug, int $prNumber, array $ignoreChecks = []): string
    {
        $pr = $this->pull($repoSlug, $prNumber);
        $sha = (string) ($pr['head']['sha'] ?? '');
        if ('' === $sha) {
            throw new PabloError("pull request #{$prNumber} of {$repoSlug} has no head commit");
        }

        return CiCheck::evaluate($this->rollup($repoSlug, $sha), $ignoreChecks);
    }

    /**
     * The checks of a commit: its combined status, one entry per status
     * context (Gitea keeps only each context's latest), every page read.
     *
     * @return list<CiCheck>
     */
    private function rollup(string $repoSlug, string $sha): array
    {
        $endpoint = GiteaApi::repo($repoSlug).'/commits/'.rawurlencode($sha).'/status';
        $checks = [];
        for ($page = 1; $page <= GiteaApi::MAX_PAGES; ++$page) {
            $statuses = GiteaApi::items($this->api->get($this->host, $endpoint, ['limit' => GiteaApi::PER_PAGE, 'page' => $page]), 'statuses');
            array_push($checks, ...self::checksOf($statuses));
            if (\count($statuses) < GiteaApi::PER_PAGE) {
                break;
            }
        }

        return $checks;
    }

    /**
     * @param list<array<mixed>> $statuses REST commit statuses
     *
     * @return list<CiCheck>
     */
    public static function checksOf(array $statuses): array
    {
        $checks = [];
        foreach ($statuses as $status) {
            $state = strtolower((string) ($status['status'] ?? $status['state'] ?? ''));
            $checks[] = new CiCheck(
                strtolower((string) ($status['context'] ?? '')),
                \in_array($state, self::FAILED_STATUSES, true),
                'pending' === $state,
            );
        }

        return $checks;
    }

    public function reviewVerdict(string $repoSlug, int $prNumber, array $botWhitelist): ?string
    {
        return $this->fetchReviews($repoSlug, $prNumber)->verdict($botWhitelist);
    }

    /**
     * Every input of reviewVerdict(), every page included.
     *
     * @param array<mixed>|null $pr the REST pull request, when already read
     */
    public function fetchReviews(string $repoSlug, int $prNumber, ?array $pr = null): GiteaReviews
    {
        $pr ??= $this->pull($repoSlug, $prNumber);
        $repo = GiteaApi::repo($repoSlug);
        $reviews = $this->api->getAll($this->host, "{$repo}/pulls/{$prNumber}/reviews");
        $timeline = $this->api->getAll($this->host, "{$repo}/issues/{$prNumber}/timeline");

        $ids = GiteaReviews::threadedCommentReviews($reviews);
        $unresolved = [];
        if ([] !== $ids) {
            $commands = array_map(fn (int $id): array => GiteaApi::argv($this->host, "{$repo}/pulls/{$prNumber}/reviews/{$id}/comments"), $ids);
            foreach ($this->runner->runParallel($commands, timeout: GiteaApi::TIMEOUT_S) as $i => $out) {
                if (GiteaReviews::hasUnresolved(GiteaApi::items(GiteaApi::decode($out, 'review comments')))) {
                    $unresolved[$ids[$i]] = true;
                }
            }
        }

        $snapshot = GiteaReviews::fromApi($pr, $reviews, $timeline, $unresolved);
        if (null === $snapshot) {
            throw new PabloError("pull request #{$prNumber} of {$repoSlug}: unreadable review data");
        }

        return $snapshot;
    }

    /**
     * Gitea has no batched read: the pull requests are read concurrently,
     * then their head commits' statuses, then the reviews one pull request
     * at a time. Never throws; whatever failed is left to the per-PR calls.
     */
    public function pollSnapshot(string $repoSlug, array $branches, array $prNumbers, array $reviewPrNumbers): PrSnapshot
    {
        $branchPrs = [];
        $branches = array_values(array_unique($branches));
        if ([] !== $branches) {
            try {
                $found = $this->prsForBranches($repoSlug, $branches);
                foreach ($branches as $branch) {
                    $branchPrs[$branch] = $found[$branch] ?? null;
                }
            } catch (\Throwable) {
                $branchPrs = [];
            }
        }

        $prNumbers = array_values(array_unique($prNumbers));
        $prs = [];
        $states = [];
        $rollups = [];
        $reviews = [];
        try {
            $repo = GiteaApi::repo($repoSlug);
            $commands = array_map(fn (int $n): array => GiteaApi::argv($this->host, "{$repo}/pulls/{$n}"), $prNumbers);
            foreach ([] === $commands ? [] : $this->runner->runParallel($commands, check: false, timeout: GiteaApi::TIMEOUT_S) as $i => $out) {
                $pr = Json::tryDecodeArray($out);
                if (\is_array($pr) && null === GiteaApi::errorOf($pr) && isset($pr['state'])) {
                    $prs[$prNumbers[$i]] = $pr;
                    $states[$prNumbers[$i]] = self::stateOf($pr);
                }
            }

            $withSha = array_values(array_filter(array_keys($prs), static fn (int $n): bool => '' !== (string) ($prs[$n]['head']['sha'] ?? '')));
            $commands = array_map(fn (int $n): array => GiteaApi::argv(
                $this->host,
                "{$repo}/commits/".rawurlencode((string) $prs[$n]['head']['sha']).'/status',
                ['limit' => GiteaApi::PER_PAGE, 'page' => 1],
            ), $withSha);
            foreach ([] === $commands ? [] : $this->runner->runParallel($commands, check: false, timeout: GiteaApi::TIMEOUT_S) as $i => $out) {
                $status = Json::tryDecodeArray($out);
                if (!\is_array($status) || null !== GiteaApi::errorOf($status) || !\is_array($status['statuses'] ?? null)) {
                    continue;
                }
                $statuses = GiteaApi::items($status, 'statuses');
                if (\count($statuses) < GiteaApi::PER_PAGE) { // more pages: read by the per-PR call
                    $rollups[$withSha[$i]] = self::checksOf($statuses);
                }
            }
        } catch (\Throwable) {
            // gt missing, not logged in to the host…: everything is read one by one
        }

        foreach (array_unique($reviewPrNumbers) as $n) {
            if (!isset($prs[$n])) {
                continue;
            }
            try {
                $reviews[$n] = $this->fetchReviews($repoSlug, $n, $prs[$n]);
            } catch (\Throwable) {
                // read by the per-PR call
            }
        }

        return new PrSnapshot($branchPrs, $states, $rollups, $reviews);
    }

    /** Drops the draft prefix of the title (a no-op on a ready one). */
    public function markReady(string $repoSlug, int $prNumber): void
    {
        $this->api->command($this->host, $repoSlug, 'pr', 'ready', (string) $prNumber);
    }

    /** Adds the "WIP: " title prefix (a no-op on a draft). */
    public function markDraft(string $repoSlug, int $prNumber): void
    {
        $this->api->command($this->host, $repoSlug, 'pr', 'ready', (string) $prNumber, '--undo');
    }

    public function prState(string $repoSlug, int $prNumber): string
    {
        return self::stateOf($this->pull($repoSlug, $prNumber));
    }

    /**
     * Re-runs the latest completed Gitea Actions run of each workflow on the
     * branch (`POST …/actions/runs/<id>/rerun`, Gitea 1.25 and later).
     */
    public function rerunCi(string $repoSlug, string $branch): array
    {
        $repo = GiteaApi::repo($repoSlug);
        $runs = GiteaApi::items($this->api->get($this->host, "{$repo}/actions/runs", ['branch' => $branch, 'limit' => GiteaApi::PER_PAGE]), 'workflow_runs');
        $latest = [];
        foreach ($runs as $run) {
            if ('completed' !== ($run['status'] ?? null) || !\is_scalar($run['id'] ?? null)) {
                continue;
            }
            $workflow = (string) ($run['path'] ?? $run['display_title'] ?? '');
            $current = $latest[$workflow] ?? null;
            if (null === $current || (int) $run['id'] > (int) $current['id']) {
                $latest[$workflow] = $run;
            }
        }
        if ([] === $latest) {
            throw new PabloError("no completed workflow runs found on branch {$branch}");
        }
        $ids = [];
        foreach ($latest as $run) {
            $id = (string) $run['id'];
            $this->api->send($this->host, 'POST', "{$repo}/actions/runs/{$id}/rerun");
            $ids[] = $id;
        }

        return $ids;
    }

    /** @return array<mixed> */
    private function pull(string $repoSlug, int $prNumber): array
    {
        return $this->api->get($this->host, GiteaApi::repo($repoSlug)."/pulls/{$prNumber}");
    }
}
