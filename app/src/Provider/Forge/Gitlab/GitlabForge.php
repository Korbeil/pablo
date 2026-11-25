<?php

declare(strict_types=1);

namespace Pablo\Provider\Forge\Gitlab;

use Pablo\Provider\Forge\CiCheck;
use Pablo\Provider\Forge\ForgeInterface;
use Pablo\Provider\Forge\PrInfo;
use Pablo\Provider\Forge\PrSnapshot;
use Pablo\Provider\Forge\RepoRef;
use Pablo\Support\Json;
use Pablo\Support\PabloError;
use Pablo\Support\ProcessRunnerInterface;

/**
 * GitLab merge-request plumbing via the glab CLI (`glab api`, REST and
 * GraphQL): CI status from the head pipeline's jobs, review evaluation
 * (GitlabReviewVerdict), draft/ready toggling, merge detection. A PR number
 * here is the merge request's IID.
 *
 * Bound to one GitLab host (onHost(), done by ForgeRegistry); the service
 * itself is bound to gitlab.com.
 */
final class GitlabForge implements ForgeInterface
{
    /**
     * Merge requests per pollSnapshot() query. GitLab caps a query's
     * complexity: a merge request with its discussions weighs far more than
     * a GitHub PR does, so the batches are smaller. A query GitLab refuses
     * leaves its merge requests to the per-PR calls.
     */
    public const SNAPSHOT_PRS_PER_QUERY = 5;

    /** Head-branch lookups per pollSnapshot() query. */
    public const SNAPSHOT_BRANCHES_PER_QUERY = 25;

    /** Upper bound on discussion pages per fetchReviews() (100 each). */
    public const MAX_DISCUSSION_PAGES = 20;

    /** Job statuses that are neither done nor failed: CI is still running. */
    private const PENDING_STATUSES = [
        'CREATED', 'WAITING_FOR_RESOURCE', 'PREPARING', 'WAITING_FOR_CALLBACK',
        'PENDING', 'RUNNING', 'SCHEDULED', 'CANCELING',
    ];

    private const MR_FIELDS = 'iid title state draft webUrl mergedAt targetBranch';

    /**
     * The head pipeline and its latest jobs. Retried jobs are left out: a
     * job retried after a failure only counts by its last run. A job
     * allowed to fail never fails CI; a manual or cancelled one says nothing
     * about the code (GitHub's CANCELLED rule).
     */
    private const STATUS_FIELDS = <<<'GQL'
state
mergedAt
headPipeline {
  status
  jobs(retried: false, first: 100) {
    pageInfo { hasNextPage }
    nodes { name status allowFailure }
  }
}
GQL;

    private const REVIEW_HEAD_FIELDS = <<<'GQL'
createdAt
author { username }
approvedBy { nodes { username bot } }
reviewers { nodes { username bot mergeRequestInteraction { reviewState } } }
GQL;

    private const DISCUSSION_FIELDS = <<<'GQL'
pageInfo { hasNextPage endCursor }
nodes {
  resolvable
  resolved
  notes(first: 100) { nodes { system body createdAt author { username bot } } }
}
GQL;

    private string $host = RepoRef::DEFAULT_HOSTS[RepoRef::GITLAB];

    private readonly GitlabApi $api;

    public function __construct(private readonly ProcessRunnerInterface $runner)
    {
        $this->api = new GitlabApi($runner);
    }

    /** This forge, on another GitLab host (a self-hosted instance). */
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
        return RepoRef::GITLAB;
    }

    public function prUrl(string $repoSlug, int $prNumber): string
    {
        return (new RepoRef(RepoRef::GITLAB, $this->host, $repoSlug))->prUrl($prNumber);
    }

    /** OPEN | CLOSED | MERGED from a GitLab state (opened, closed, locked, merged). */
    public static function stateOf(string $state, mixed $mergedAt = null): string
    {
        $state = match (strtolower($state)) {
            'opened', 'locked' => 'OPEN',
            'closed' => 'CLOSED',
            'merged' => 'MERGED',
            default => strtoupper($state),
        };

        return PrInfo::normalizeState($state, $mergedAt);
    }

    /** @param array<mixed> $mr a REST merge request */
    private static function prFromRest(array $mr): PrInfo
    {
        return new PrInfo(
            number: (int) ($mr['iid'] ?? 0),
            title: (string) ($mr['title'] ?? ''),
            state: self::stateOf((string) ($mr['state'] ?? ''), $mr['merged_at'] ?? null),
            isDraft: true === ($mr['draft'] ?? $mr['work_in_progress'] ?? false),
            url: (string) ($mr['web_url'] ?? ''),
            mergedAt: isset($mr['merged_at']) ? (string) $mr['merged_at'] : null,
            baseRefName: isset($mr['target_branch']) ? (string) $mr['target_branch'] : null,
        );
    }

    /** @param array<mixed> $mr a GraphQL MergeRequest with MR_FIELDS */
    private static function prFromGraphql(array $mr): PrInfo
    {
        return new PrInfo(
            number: (int) ($mr['iid'] ?? 0),
            title: (string) ($mr['title'] ?? ''),
            state: self::stateOf((string) ($mr['state'] ?? ''), $mr['mergedAt'] ?? null),
            isDraft: true === ($mr['draft'] ?? false),
            url: (string) ($mr['webUrl'] ?? ''),
            mergedAt: isset($mr['mergedAt']) ? (string) $mr['mergedAt'] : null,
            baseRefName: isset($mr['targetBranch']) ? (string) $mr['targetBranch'] : null,
        );
    }

    /**
     * The newest merge request whose source branch is $branch.
     *
     * @return list<string>
     */
    private function branchArgv(string $repoSlug, string $branch): array
    {
        return GitlabApi::argv($this->host, GitlabApi::project($repoSlug).'/merge_requests', [
            'source_branch' => $branch, 'state' => 'all',
            'order_by' => 'created_at', 'sort' => 'desc', 'per_page' => 1,
        ]);
    }

    public function prForBranch(string $repoSlug, string $branch): ?PrInfo
    {
        $out = $this->runner->run($this->branchArgv($repoSlug, $branch), timeout: GitlabApi::TIMEOUT_S);
        $items = Json::decodeList($out, 'glab api merge_requests output');
        $first = $items[0] ?? null;

        return \is_array($first) ? self::prFromRest($first) : null;
    }

    public function prsForBranches(string $repoSlug, array $branches): array
    {
        if ([] === $branches) {
            return [];
        }
        $commands = array_map(fn (string $branch): array => $this->branchArgv($repoSlug, $branch), $branches);
        $result = [];
        foreach ($this->runner->runParallel($commands, timeout: GitlabApi::TIMEOUT_S) as $i => $out) {
            $first = Json::decodeList($out, 'glab api merge_requests output')[0] ?? null;
            if (\is_array($first)) {
                $result[$branches[$i]] = self::prFromRest($first);
            }
        }

        return $result;
    }

    public function prsForBranchesBulk(array $repoBranches): array
    {
        $pairs = [];
        $commands = [];
        $all = [];
        foreach ($repoBranches as [$slug, $branches]) {
            $all[$slug] ??= [];
            foreach ($branches as $branch) {
                $pairs[] = [$slug, $branch];
                $commands[] = $this->branchArgv($slug, $branch);
            }
        }
        if ([] === $commands) {
            return $all;
        }
        // check: false: one repository's failure must not hide the others' merge requests.
        foreach ($this->runner->runParallel($commands, check: false, timeout: GitlabApi::TIMEOUT_S) as $i => $out) {
            $items = Json::tryDecodeArray($out);
            $first = \is_array($items) ? ($items[0] ?? null) : null;
            if (\is_array($first)) {
                [$slug, $branch] = $pairs[$i];
                $all[$slug][$branch] = self::prFromRest($first);
            }
        }

        return $all;
    }

    public function ciStatus(string $repoSlug, int $prNumber, array $ignoreChecks = []): string
    {
        $data = $this->api->graphql(
            $this->host,
            "query(\$path: ID!, \$iid: String!) {\n  project(fullPath: \$path) {\n    mergeRequest(iid: \$iid) {\n".self::STATUS_FIELDS."\n    }\n  }\n}",
            ['path' => $repoSlug, 'iid' => (string) $prNumber],
        );
        $mr = $data['project']['mergeRequest'] ?? null;
        if (!\is_array($mr)) {
            throw new PabloError("merge request !{$prNumber} not found in {$repoSlug}");
        }

        return CiCheck::evaluate(self::rollupOf($mr), $ignoreChecks);
    }

    /**
     * The checks of a merge request's head pipeline: one per job, or the
     * pipeline as a whole when it has more jobs than one page (the ignore
     * list then cannot apply). No pipeline: no checks.
     *
     * @param array<mixed> $mr a GraphQL MergeRequest with STATUS_FIELDS
     *
     * @return list<CiCheck>
     */
    public static function rollupOf(array $mr): array
    {
        $pipeline = $mr['headPipeline'] ?? null;
        if (!\is_array($pipeline)) {
            return [];
        }
        $jobs = $pipeline['jobs'] ?? null;
        if (!\is_array($jobs) || true === ($jobs['pageInfo']['hasNextPage'] ?? false)) {
            return [self::check('pipeline', (string) ($pipeline['status'] ?? ''), false)];
        }
        $checks = [];
        foreach (GitlabReviews::nodes($jobs) as $job) {
            $checks[] = self::check((string) ($job['name'] ?? ''), (string) ($job['status'] ?? ''), true === ($job['allowFailure'] ?? false));
        }

        return $checks;
    }

    private static function check(string $name, string $status, bool $allowFailure): CiCheck
    {
        $status = strtoupper($status);

        return new CiCheck(
            strtolower($name),
            'FAILED' === $status && !$allowFailure,
            \in_array($status, self::PENDING_STATUSES, true),
        );
    }

    public function reviewVerdict(string $repoSlug, int $prNumber, array $botWhitelist): ?string
    {
        return $this->fetchReviews($repoSlug, $prNumber)->verdict($botWhitelist);
    }

    /** Every input of reviewVerdict(), every discussion page included. */
    public function fetchReviews(string $repoSlug, int $prNumber): GitlabReviews
    {
        $query = "query(\$path: ID!, \$iid: String!, \$after: String) {\n  project(fullPath: \$path) {\n    mergeRequest(iid: \$iid) {\n"
            .self::REVIEW_HEAD_FIELDS."\ndiscussions(first: 100, after: \$after) {\n".self::DISCUSSION_FIELDS."\n}\n    }\n  }\n}";
        /** @var array<mixed> $head the first page's merge request: its head fields */
        $head = [];
        $discussions = [];
        $after = null;
        for ($page = 0; $page < self::MAX_DISCUSSION_PAGES; ++$page) {
            $data = $this->api->graphql($this->host, $query, ['path' => $repoSlug, 'iid' => (string) $prNumber, 'after' => $after]);
            $mr = $data['project']['mergeRequest'] ?? null;
            if (!\is_array($mr)) {
                throw new PabloError("merge request !{$prNumber} not found in {$repoSlug}");
            }
            if (0 === $page) {
                $head = $mr;
            }
            array_push($discussions, ...GitlabReviews::nodes($mr['discussions'] ?? null));
            $info = $mr['discussions']['pageInfo'] ?? [];
            $after = true === ($info['hasNextPage'] ?? false) ? ($info['endCursor'] ?? null) : null;
            if (null === $after) {
                break;
            }
        }
        $reviews = GitlabReviews::fromGraphql($head, $discussions);
        if (null === $reviews) {
            throw new PabloError("merge request !{$prNumber} of {$repoSlug}: unreadable review data");
        }

        return $reviews;
    }

    public function pollSnapshot(string $repoSlug, array $branches, array $prNumbers, array $reviewPrNumbers): PrSnapshot
    {
        $branches = array_values(array_unique($branches));
        $prNumbers = array_values(array_unique($prNumbers));
        $withReviews = array_fill_keys($reviewPrNumbers, true);
        $branchChunks = array_chunk($branches, self::SNAPSHOT_BRANCHES_PER_QUERY);
        $prChunks = array_chunk($prNumbers, self::SNAPSHOT_PRS_PER_QUERY);

        $branchPrs = [];
        $states = [];
        $rollups = [];
        $reviews = [];
        for ($i = 0, $n = max(\count($branchChunks), \count($prChunks)); $i < $n; ++$i) {
            $project = $this->snapshotQuery($repoSlug, $branchChunks[$i] ?? [], $prChunks[$i] ?? [], $withReviews);
            if (null === $project) {
                continue; // this query failed as a whole: its merge requests are read one by one
            }
            foreach ($branchChunks[$i] ?? [] as $k => $branch) {
                $connection = $project["b{$k}"] ?? null;
                if (\is_array($connection)) {
                    $first = GitlabReviews::nodes($connection)[0] ?? null;
                    $branchPrs[$branch] = null === $first ? null : self::prFromGraphql($first);
                }
            }
            foreach ($prChunks[$i] ?? [] as $prNumber) {
                $mr = $project["p{$prNumber}"] ?? null;
                if (!\is_array($mr) || !isset($mr['state'])) {
                    continue; // unresolved (not found, no access…): read one by one
                }
                $states[$prNumber] = self::stateOf((string) $mr['state'], $mr['mergedAt'] ?? null);
                $rollups[$prNumber] = self::rollupOf($mr);
                if (isset($withReviews[$prNumber])
                    && false === ($mr['discussions']['pageInfo']['hasNextPage'] ?? true)) {
                    $review = GitlabReviews::fromGraphql($mr, GitlabReviews::nodes($mr['discussions']));
                    if (null !== $review) {
                        $reviews[$prNumber] = $review;
                    }
                }
            }
        }

        return new PrSnapshot($branchPrs, $states, $rollups, $reviews);
    }

    /**
     * One aliased query: `b<k>` per head branch, `p<iid>` per merge request.
     * Run with check: false: an alias that cannot be resolved fails glab
     * while the others' data is still there.
     *
     * @param list<string>     $branches
     * @param list<int>        $prNumbers
     * @param array<int, true> $withReviews
     *
     * @return array<string, mixed>|null data.project, null when the query failed
     */
    private function snapshotQuery(string $repoSlug, array $branches, array $prNumbers, array $withReviews): ?array
    {
        $vars = ['$path: ID!'];
        $fields = [];
        $variables = ['path' => $repoSlug];
        foreach ($branches as $k => $branch) {
            $vars[] = "\$b{$k}: String!";
            $fields[] = "b{$k}: mergeRequests(sourceBranches: [\$b{$k}], state: all, sort: CREATED_DESC, first: 1) { nodes { ".self::MR_FIELDS.' } }';
            $variables["b{$k}"] = $branch;
        }
        foreach ($prNumbers as $prNumber) {
            $selection = self::STATUS_FIELDS;
            if (isset($withReviews[$prNumber])) {
                $selection .= "\n".self::REVIEW_HEAD_FIELDS."\ndiscussions(first: 100) {\n".self::DISCUSSION_FIELDS."\n}";
            }
            $fields[] = "p{$prNumber}: mergeRequest(iid: \"{$prNumber}\") {\n{$selection}\n}";
        }
        $query = 'query('.implode(', ', $vars).") {\n  project(fullPath: \$path) {\n    ".implode("\n    ", $fields)."\n  }\n}";

        try {
            $out = $this->runner->run(GitlabApi::graphqlArgv($this->host, $query, $variables), check: false, timeout: GitlabApi::TIMEOUT_S);
        } catch (\Throwable) {
            return null; // timed out, glab missing…
        }
        $response = Json::tryDecodeArray($out);
        $project = $response['data']['project'] ?? null;
        if (!\is_array($project)) {
            return null;
        }
        // An error under an alias drops that alias (read one by one); an
        // error anywhere else discards the lot.
        foreach (\is_array($response['errors'] ?? null) ? $response['errors'] : [] as $error) {
            $path = \is_array($error) ? ($error['path'] ?? null) : null;
            if (!\is_array($path) || 'project' !== ($path[0] ?? null) || !\is_string($path[1] ?? null)) {
                return null;
            }
            unset($project[$path[1]]);
        }

        return $project;
    }

    public function markReady(string $repoSlug, int $prNumber): void
    {
        $this->setDraft($repoSlug, $prNumber, false);
    }

    public function markDraft(string $repoSlug, int $prNumber): void
    {
        $this->setDraft($repoSlug, $prNumber, true);
    }

    private function setDraft(string $repoSlug, int $prNumber, bool $draft): void
    {
        $data = $this->api->graphql(
            $this->host,
            'mutation($path: ID!, $iid: String!, $draft: Boolean!) { mergeRequestSetDraft(input: {projectPath: $path, iid: $iid, draft: $draft}) { errors } }',
            ['path' => $repoSlug, 'iid' => (string) $prNumber, 'draft' => $draft],
        );
        $errors = $data['mergeRequestSetDraft']['errors'] ?? [];
        if (\is_array($errors) && [] !== $errors) {
            throw new PabloError("cannot mark merge request !{$prNumber} as ".($draft ? 'draft' : 'ready').': '.implode('; ', array_map(strval(...), array_filter($errors, \is_scalar(...)))));
        }
    }

    public function prState(string $repoSlug, int $prNumber): string
    {
        $mr = $this->api->get($this->host, GitlabApi::project($repoSlug)."/merge_requests/{$prNumber}");

        return self::stateOf((string) ($mr['state'] ?? ''), $mr['merged_at'] ?? null);
    }

    /**
     * A new pipeline: for the branch's open merge request when it has one
     * (a merge request pipeline, which `rules: merge_request_event` jobs
     * need), else for the branch itself.
     */
    public function rerunCi(string $repoSlug, string $branch): array
    {
        $project = GitlabApi::project($repoSlug);
        $open = $this->api->get($this->host, "{$project}/merge_requests", [
            'source_branch' => $branch, 'state' => 'opened', 'order_by' => 'created_at', 'sort' => 'desc', 'per_page' => 1,
        ]);
        $iid = \is_array($open[0] ?? null) ? ($open[0]['iid'] ?? null) : null;
        $pipeline = null !== $iid
            ? $this->api->post($this->host, "{$project}/merge_requests/{$iid}/pipelines")
            : $this->api->post($this->host, "{$project}/pipeline", ['ref' => $branch]);
        $id = $pipeline['id'] ?? null;
        if (!\is_scalar($id)) {
            throw new PabloError("no pipeline could be started on branch {$branch}");
        }

        return [(string) $id];
    }
}
