<?php

declare(strict_types=1);

namespace Pablo\Tests\Provider\Forge\Gitlab;

use Pablo\Provider\Forge\Gitlab\GitlabForge;
use Pablo\Provider\Forge\Gitlab\GitlabReviews;
use Pablo\Provider\Forge\ReviewVerdict;
use Pablo\Support\PabloError;
use Pablo\Tests\FakeProcessRunner;
use PHPUnit\Framework\TestCase;

/**
 * GitlabForge over a scripted glab: every call is `glab api --hostname
 * <host> …`, REST or GraphQL, and its answers are GitLab's documented shapes.
 */
final class GitlabForgeTest extends TestCase
{
    private FakeProcessRunner $runner;

    /** @var list<array<mixed>> */
    private array $argvs = [];

    /** @param callable(array<mixed>): mixed $answer JSON-encoded unless a string */
    private function forge(callable $answer, string $host = 'gitlab.example.com'): GitlabForge
    {
        $this->runner = new FakeProcessRunner();
        $this->runner->onRun = function (array $argv) use ($answer): string {
            $this->argvs[] = $argv;
            $out = $answer($argv);

            return \is_string($out) ? $out : (string) json_encode($out);
        };

        return (new GitlabForge($this->runner))->onHost($host);
    }

    /** @param array<mixed> $argv */
    private static function query(array $argv): string
    {
        foreach ($argv as $arg) {
            if (\is_string($arg) && str_starts_with($arg, 'query=')) {
                return substr($arg, 6);
            }
        }

        return '';
    }

    public function testIdentity(): void
    {
        $forge = $this->forge(static fn (): array => []);
        $this->assertSame('gitlab', $forge->name());
        $this->assertSame('gitlab.example.com', $forge->host());
        $this->assertSame('https://gitlab.example.com/acme/platform/api/-/merge_requests/7', $forge->prUrl('acme/platform/api', 7));
        $this->assertSame('gitlab.com', (new GitlabForge(new FakeProcessRunner()))->host());
    }

    public function testPrForBranchReadsTheNewestMergeRequest(): void
    {
        $forge = $this->forge(static fn (): array => [[
            'iid' => 12, 'title' => 'Fix callbacks', 'state' => 'opened', 'draft' => true,
            'web_url' => 'https://gitlab.example.com/acme/api/-/merge_requests/12', 'merged_at' => null, 'target_branch' => 'main',
        ]]);

        $pr = $forge->prForBranch('acme/platform/api', 'wk-45');

        $this->assertNotNull($pr);
        $this->assertSame([12, 'OPEN', true, 'main'], [$pr->number, $pr->state, $pr->isDraft, $pr->baseRefName]);
        $this->assertSame(
            ['glab', 'api', '--hostname', 'gitlab.example.com', 'projects/acme%2Fplatform%2Fapi/merge_requests?source_branch=wk-45&state=all&order_by=created_at&sort=desc&per_page=1'],
            $this->argvs[0],
        );
    }

    public function testPrForBranchWithoutMergeRequest(): void
    {
        $this->assertNull($this->forge(static fn (): array => [])->prForBranch('acme/api', 'wk-45'));
    }

    public function testStateMapping(): void
    {
        $this->assertSame('OPEN', GitlabForge::stateOf('opened'));
        $this->assertSame('OPEN', GitlabForge::stateOf('locked'));
        $this->assertSame('CLOSED', GitlabForge::stateOf('closed'));
        $this->assertSame('MERGED', GitlabForge::stateOf('merged'));
        $this->assertSame('MERGED', GitlabForge::stateOf('closed', '2026-01-01T00:00:00Z'));
    }

    public function testBulkSkipsAFailedRepository(): void
    {
        $forge = $this->forge(static fn (array $argv): string => str_contains((string) end($argv), 'broken')
            ? 'glab: 404 Not Found'
            : (string) json_encode([['iid' => 3, 'title' => 't', 'state' => 'merged', 'draft' => false, 'web_url' => 'u', 'merged_at' => '2026-01-01T00:00:00Z']]));

        $all = $forge->prsForBranchesBulk([['acme/api', ['a', 'b']], ['acme/broken', ['c']]]);

        $this->assertSame(['a', 'b'], array_keys($all['acme/api']));
        $this->assertSame('MERGED', $all['acme/api']['a']->state);
        $this->assertSame([], $all['acme/broken']);
    }

    /**
     * @param list<array<string, mixed>> $jobs
     *
     * @return array<string, mixed>
     */
    private static function pipeline(string $status, array $jobs, bool $more = false): array
    {
        return ['data' => ['project' => ['mergeRequest' => [
            'state' => 'opened', 'mergedAt' => null,
            'headPipeline' => ['status' => $status, 'jobs' => ['pageInfo' => ['hasNextPage' => $more], 'nodes' => $jobs]],
        ]]]];
    }

    public function testCiRedOnAFailedJob(): void
    {
        $forge = $this->forge(static fn (): array => self::pipeline('FAILED', [
            ['name' => 'phpunit', 'status' => 'FAILED', 'allowFailure' => false],
            ['name' => 'lint', 'status' => 'SUCCESS', 'allowFailure' => false],
        ]));

        $this->assertSame('red', $forge->ciStatus('acme/api', 12));
        $this->assertSame('green', $forge->ciStatus('acme/api', 12, ['PHPUnit']));
        $argv = $this->argvs[0];
        $this->assertSame(['glab', 'api', '--hostname', 'gitlab.example.com', 'graphql'], \array_slice($argv, 0, 5));
        $this->assertContains('path=acme/api', $argv);
        $this->assertContains('iid=12', $argv);
        $this->assertStringContainsString('jobs(retried: false, first: 100)', self::query($argv));
    }

    public function testAnAllowedFailureIsGreen(): void
    {
        $forge = $this->forge(static fn (): array => self::pipeline('SUCCESS', [
            ['name' => 'audit', 'status' => 'FAILED', 'allowFailure' => true],
            ['name' => 'phpunit', 'status' => 'SUCCESS', 'allowFailure' => false],
        ]));

        $this->assertSame('green', $forge->ciStatus('acme/api', 12));
    }

    public function testRunningAndCancelledJobs(): void
    {
        $forge = $this->forge(static fn (): array => self::pipeline('RUNNING', [
            ['name' => 'deploy', 'status' => 'MANUAL', 'allowFailure' => true],
            ['name' => 'e2e', 'status' => 'CANCELED', 'allowFailure' => false],
            ['name' => 'phpunit', 'status' => 'RUNNING', 'allowFailure' => false],
        ]));

        $this->assertSame('pending', $forge->ciStatus('acme/api', 12));
        $this->assertSame('green', $forge->ciStatus('acme/api', 12, ['phpunit']));
    }

    public function testNoPipelineIsGreen(): void
    {
        $forge = $this->forge(static fn (): array => ['data' => ['project' => ['mergeRequest' => ['state' => 'opened', 'mergedAt' => null, 'headPipeline' => null]]]]);

        $this->assertSame('green', $forge->ciStatus('acme/api', 12));
    }

    public function testMoreJobsThanOnePageFallBackOnThePipeline(): void
    {
        $forge = $this->forge(static fn (): array => self::pipeline('FAILED', [['name' => 'lint', 'status' => 'SUCCESS', 'allowFailure' => false]], more: true));

        $this->assertSame('red', $forge->ciStatus('acme/api', 12));
        $this->assertSame('red', $forge->ciStatus('acme/api', 12, ['lint']), 'the ignore list cannot apply to the pipeline as a whole');
    }

    public function testCiStatusOfAnUnknownMergeRequestThrows(): void
    {
        $forge = $this->forge(static fn (): array => ['data' => ['project' => ['mergeRequest' => null]]]);

        $this->expectException(PabloError::class);
        $forge->ciStatus('acme/api', 99);
    }

    public function testGraphqlErrorsWithoutDataThrow(): void
    {
        $forge = $this->forge(static fn (): array => ['errors' => [['message' => 'Query has complexity of 300, which exceeds max complexity of 250']]]);

        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('exceeds max complexity');
        $forge->ciStatus('acme/api', 12);
    }

    /**
     * @param list<array<string, mixed>> $discussions
     * @param list<array<string, mixed>> $reviewers
     * @param list<string>               $approvers
     *
     * @return array<string, mixed>
     */
    private static function mr(array $discussions, array $reviewers = [], array $approvers = [], bool $more = false, ?string $cursor = null): array
    {
        return [
            'createdAt' => '2026-03-01T10:00:00Z',
            'author' => ['username' => 'me'],
            'approvedBy' => ['nodes' => array_map(static fn (string $u): array => ['username' => $u, 'bot' => false], $approvers)],
            'reviewers' => ['nodes' => $reviewers],
            'discussions' => ['pageInfo' => ['hasNextPage' => $more, 'endCursor' => $cursor], 'nodes' => $discussions],
        ];
    }

    /** @return array<string, mixed> */
    private static function thread(string $author, string $at, bool $resolved = false, bool $bot = false): array
    {
        return ['resolvable' => true, 'resolved' => $resolved, 'notes' => ['nodes' => [
            ['system' => false, 'body' => 'please fix', 'createdAt' => $at, 'author' => ['username' => $author, 'bot' => $bot]],
        ]]];
    }

    /** @return array<string, mixed> */
    private static function systemNote(string $author, string $body, string $at): array
    {
        return ['resolvable' => false, 'resolved' => false, 'notes' => ['nodes' => [
            ['system' => true, 'body' => $body, 'createdAt' => $at, 'author' => ['username' => $author, 'bot' => false]],
        ]]];
    }

    public function testReviewsArePagedThroughDiscussions(): void
    {
        $pages = [
            self::mr([self::systemNote('me', 'marked this merge request as **ready**', '2026-03-02T10:00:00Z')], more: true, cursor: 'c1'),
            self::mr([self::thread('alice', '2026-03-03T10:00:00Z')]),
        ];
        $forge = $this->forge(static function (array $argv) use (&$pages): array {
            return ['data' => ['project' => ['mergeRequest' => array_shift($pages)]]];
        });

        $reviews = $forge->fetchReviews('acme/api', 12);

        $this->assertCount(2, $reviews->discussions);
        $this->assertContains('after=c1', $this->argvs[1]);
        $this->assertNotContains('after=c1', $this->argvs[0]);
        $this->assertSame(ReviewVerdict::CHANGES, $reviews->verdict([]));
    }

    public function testApprovalAfterReady(): void
    {
        $mr = self::mr([], approvers: ['alice']);
        $discussions = [
            self::systemNote('me', 'marked this merge request as **ready**', '2026-03-02T10:00:00Z'),
            self::systemNote('alice', 'approved this merge request', '2026-03-03T10:00:00Z'),
        ];
        $this->assertSame(ReviewVerdict::APPROVED, GitlabReviews::fromGraphql($mr, $discussions)?->verdict([]));
    }

    public function testAnApprovalFromAPreviousRoundDoesNotCount(): void
    {
        $discussions = [
            self::systemNote('alice', 'approved this merge request', '2026-03-02T10:00:00Z'),
            self::systemNote('me', 'marked this merge request as **ready**', '2026-03-03T10:00:00Z'),
        ];

        $this->assertNull(GitlabReviews::fromGraphql(self::mr([], approvers: ['alice']), $discussions)?->verdict([]));
    }

    public function testAnApprovalWithoutItsNoteCounts(): void
    {
        $this->assertSame(ReviewVerdict::APPROVED, GitlabReviews::fromGraphql(self::mr([], approvers: ['alice']), [])?->verdict([]));
    }

    public function testTheAuthorsOwnSignalsNeverCount(): void
    {
        $verdict = GitlabReviews::fromGraphql(self::mr([], approvers: ['me']), [self::thread('me', '2026-03-03T10:00:00Z')])?->verdict([]);

        $this->assertNull($verdict);
    }

    public function testRequestedChangesBeatsApprovals(): void
    {
        $reviewers = [['username' => 'bob', 'bot' => false, 'mergeRequestInteraction' => ['reviewState' => 'REQUESTED_CHANGES']]];

        $this->assertSame(ReviewVerdict::CHANGES, GitlabReviews::fromGraphql(self::mr([], $reviewers, ['alice']), [])?->verdict([]));
    }

    public function testResolvedOrOldThreadsDoNotCount(): void
    {
        $discussions = [
            self::thread('alice', '2026-03-01T12:00:00Z'), // before the ready anchor
            self::systemNote('me', 'marked this merge request as **ready**', '2026-03-02T10:00:00Z'),
            self::thread('bob', '2026-03-03T10:00:00Z', resolved: true),
        ];

        $this->assertNull(GitlabReviews::fromGraphql(self::mr([]), $discussions)?->verdict([]));
    }

    public function testBotsCountOnlyWhenWhitelisted(): void
    {
        $discussions = [self::thread('review-bot', '2026-03-03T10:00:00Z', bot: true)];

        $this->assertNull(GitlabReviews::fromGraphql(self::mr([]), $discussions)?->verdict([]));
        $this->assertSame(ReviewVerdict::CHANGES, GitlabReviews::fromGraphql(self::mr([]), $discussions)?->verdict(['review-bot']));
    }

    public function testPollSnapshotBatchesBranchesAndMergeRequests(): void
    {
        $forge = $this->forge(static fn (): array => ['data' => ['project' => [
            'b0' => ['nodes' => [['iid' => '12', 'title' => 'Fix', 'state' => 'opened', 'draft' => false, 'webUrl' => 'u', 'mergedAt' => null, 'targetBranch' => 'main']]],
            'b1' => ['nodes' => []],
            'p12' => self::pipeline('FAILED', [['name' => 'phpunit', 'status' => 'FAILED', 'allowFailure' => false]])['data']['project']['mergeRequest']
                + self::mr([self::thread('alice', '2026-03-03T10:00:00Z')]),
            'p13' => ['state' => 'merged', 'mergedAt' => '2026-03-04T00:00:00Z', 'headPipeline' => null],
        ]]]);

        $snapshot = $forge->pollSnapshot('acme/api', ['wk-45', 'wk-46'], [12, 13], [12]);

        $this->assertCount(1, $this->argvs, 'one query for the lot');
        $query = self::query($this->argvs[0]);
        $this->assertStringContainsString('b0: mergeRequests(sourceBranches: [$b0], state: all, sort: CREATED_DESC, first: 1)', $query);
        $this->assertStringContainsString('p12: mergeRequest(iid: "12")', $query);
        $this->assertContains('b1=wk-46', $this->argvs[0]);
        $this->assertSame(12, $snapshot->branchPrs['wk-45']?->number);
        $this->assertNull($snapshot->branchPrs['wk-46']);
        $this->assertSame(['12' => 'OPEN', '13' => 'MERGED'], array_combine(array_map(strval(...), array_keys($snapshot->states)), $snapshot->states));
        $this->assertSame([], $snapshot->rollups[13]);
        $this->assertSame([12], array_keys($snapshot->reviews), 'only the asked reviews');
        $this->assertSame(ReviewVerdict::CHANGES, $snapshot->reviews[12]->verdict([]));
    }

    public function testPollSnapshotDropsAnAliasInError(): void
    {
        $forge = $this->forge(static fn (): array => [
            'data' => ['project' => ['p12' => null, 'p13' => ['state' => 'opened', 'mergedAt' => null, 'headPipeline' => null]]],
            'errors' => [['message' => 'not found', 'path' => ['project', 'p12']]],
        ]);

        $snapshot = $forge->pollSnapshot('acme/api', [], [12, 13], []);

        $this->assertSame([13], array_keys($snapshot->states));
    }

    public function testPollSnapshotLeavesEverythingToPerPrCallsOnFailure(): void
    {
        $forge = $this->forge(static fn (): array => ['errors' => [['message' => 'Query has complexity of 400']]]);

        $snapshot = $forge->pollSnapshot('acme/api', ['wk-45'], [12], [12]);

        $this->assertSame([[], [], [], []], [$snapshot->branchPrs, $snapshot->states, $snapshot->rollups, $snapshot->reviews]);
    }

    public function testPollSnapshotLeavesPagedReviewsToFetchReviews(): void
    {
        $forge = $this->forge(static fn (): array => ['data' => ['project' => [
            'p12' => ['state' => 'opened', 'mergedAt' => null, 'headPipeline' => null] + self::mr([], more: true, cursor: 'c'),
        ]]]);

        $this->assertSame([], $forge->pollSnapshot('acme/api', [], [12], [12])->reviews);
    }

    public function testDraftAndReadyGoThroughTheSetDraftMutation(): void
    {
        $forge = $this->forge(static fn (): array => ['data' => ['mergeRequestSetDraft' => ['errors' => []]]]);

        $forge->markDraft('acme/api', 12);
        $forge->markReady('acme/api', 12);

        $this->assertStringContainsString('mergeRequestSetDraft(input: {projectPath: $path, iid: $iid, draft: $draft})', self::query($this->argvs[0]));
        $this->assertContains('draft=true', $this->argvs[0]);
        $this->assertContains('draft=false', $this->argvs[1]);
    }

    public function testDraftMutationErrorsAreRaised(): void
    {
        $forge = $this->forge(static fn (): array => ['data' => ['mergeRequestSetDraft' => ['errors' => ['Forbidden']]]]);

        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('Forbidden');
        $forge->markReady('acme/api', 12);
    }

    public function testPrState(): void
    {
        $forge = $this->forge(static fn (): array => ['iid' => 12, 'state' => 'merged', 'merged_at' => '2026-03-04T00:00:00Z']);

        $this->assertSame('MERGED', $forge->prState('acme/api', 12));
        $this->assertSame('projects/acme%2Fapi/merge_requests/12', end($this->argvs[0]));
    }

    public function testRerunCiStartsAMergeRequestPipeline(): void
    {
        $forge = $this->forge(static fn (array $argv): array => \in_array('POST', $argv, true) ? ['id' => 901] : [['iid' => 12]]);

        $this->assertSame(['901'], $forge->rerunCi('acme/api', 'wk-45'));
        $this->assertSame(['glab', 'api', '--hostname', 'gitlab.example.com', '--method', 'POST', 'projects/acme%2Fapi/merge_requests/12/pipelines'], $this->argvs[1]);
    }

    public function testRerunCiWithoutMergeRequestStartsABranchPipeline(): void
    {
        $forge = $this->forge(static fn (array $argv): array => \in_array('POST', $argv, true) ? ['id' => 902] : []);

        $this->assertSame(['902'], $forge->rerunCi('acme/api', 'wk-45'));
        $this->assertSame('projects/acme%2Fapi/pipeline?ref=wk-45', end($this->argvs[1]));
    }
}
