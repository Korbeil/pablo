<?php

declare(strict_types=1);

namespace Pablo\Tests\Provider\Forge\Gitea;

use Pablo\Provider\Forge\Gitea\GiteaApi;
use Pablo\Provider\Forge\Gitea\GiteaForge;
use Pablo\Provider\Forge\Gitea\GiteaReviews;
use Pablo\Provider\Forge\ReviewVerdict;
use Pablo\Support\PabloError;
use Pablo\Tests\FakeProcessRunner;
use PHPUnit\Framework\TestCase;

/**
 * GiteaForge over a scripted gt: `gt api --host <host> --no-input [--method M]
 * [--raw-field k=v] <endpoint>` calls answering Gitea's documented REST
 * shapes, and `gt pr ready` for the draft toggling.
 */
final class GiteaForgeTest extends TestCase
{
    private FakeProcessRunner $runner;

    /** @var list<array<mixed>> */
    private array $argvs = [];

    /**
     * @param callable(string, array<mixed>): mixed $answer endpoint (with its query), argv => answer, JSON-encoded unless a string
     */
    private function forge(callable $answer, string $host = 'gitea.acme.io'): GiteaForge
    {
        $this->runner = new FakeProcessRunner();
        $this->runner->onRun = function (array $argv) use ($answer): string {
            $this->argvs[] = $argv;
            $out = $answer((string) end($argv), $argv);

            return \is_string($out) ? $out : (string) json_encode($out);
        };

        return (new GiteaForge($this->runner))->onHost($host);
    }

    /** @return list<array<mixed>> the gt api calls */
    private function apiCalls(): array
    {
        return array_values(array_filter($this->argvs, static fn (array $argv): bool => 'api' === $argv[1]));
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private static function pr(int $number, string $branch, array $extra = []): array
    {
        return $extra + [
            'number' => $number, 'title' => "PR {$number}", 'state' => 'open', 'merged' => false, 'merged_at' => null,
            'html_url' => "https://gitea.acme.io/acme/web/pulls/{$number}",
            'head' => ['ref' => $branch, 'sha' => "sha{$number}", 'repo' => ['full_name' => 'acme/web']],
            'base' => ['ref' => 'main'],
            'user' => ['login' => 'author', 'type' => 'User'],
            'created_at' => '2026-01-01T10:00:00Z',
        ];
    }

    public function testIdentity(): void
    {
        $forge = $this->forge(static fn (): array => []);
        $this->assertSame('gitea', $forge->name());
        $this->assertSame('gitea.acme.io', $forge->host());
        $this->assertSame('https://gitea.acme.io/acme/web/pulls/7', $forge->prUrl('acme/web', 7));
        $this->assertSame('gitea.com', (new GiteaForge(new FakeProcessRunner()))->host());
    }

    public function testCallsNameTheHostAndNeverPrompt(): void
    {
        $forge = $this->forge(static fn (): array => self::pr(3, 'wk-1'));

        $forge->prState('acme/web', 3);

        $this->assertSame(['gt', 'api', '--host', 'gitea.acme.io', '--no-input', 'repos/acme/web/pulls/3'], $this->apiCalls()[0]);
    }

    public function testGiteaErrorBodiesAreErrors(): void
    {
        // what a read run with check: false gets from a non-2xx answer
        $forge = $this->forge(static fn (): array => ['errors' => ['not found'], 'message' => 'The target couldn\'t be found.', 'url' => 'https://gitea.acme.io/api/swagger']);

        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('The target couldn\'t be found.');
        $forge->prState('acme/web', 404);
    }

    public function testErrorOf(): void
    {
        $this->assertSame('nope', GiteaApi::errorOf(['message' => 'nope', 'url' => 'x']));
        $this->assertNull(GiteaApi::errorOf(['number' => 1, 'message' => 'a PR field']));
        $this->assertNull(GiteaApi::errorOf([['message' => 'a list item']]));
        $this->assertNull(GiteaApi::errorOf([]));
    }

    public function testStateMapping(): void
    {
        $this->assertSame('OPEN', GiteaForge::stateOf(['state' => 'open']));
        $this->assertSame('CLOSED', GiteaForge::stateOf(['state' => 'closed', 'merged' => false]));
        $this->assertSame('MERGED', GiteaForge::stateOf(['state' => 'closed', 'merged' => true]));
        $this->assertSame('MERGED', GiteaForge::stateOf(['state' => 'closed', 'merged_at' => '2026-01-01T00:00:00Z']));
    }

    public function testDraftTitles(): void
    {
        $this->assertTrue(GiteaForge::isDraftTitle('WIP: Fix'));
        $this->assertTrue(GiteaForge::isDraftTitle('[wip] Fix'));
        $this->assertFalse(GiteaForge::isDraftTitle('Fix WIP: later'));
    }

    public function testPrForBranchWalksThePullListForTheNewestOwnPullRequest(): void
    {
        $forge = $this->forge(static fn (string $endpoint): array => match (true) {
            str_contains($endpoint, 'page=1') => [
                self::pr(9, 'other'),
                self::pr(8, 'wk-45', ['head' => ['ref' => 'wk-45', 'sha' => 'f', 'repo' => ['full_name' => 'fork/web']]]),
                ...array_map(static fn (int $n): array => self::pr(100 + $n, "filler-{$n}"), range(1, 48)),
            ],
            default => [self::pr(4, 'wk-45', ['state' => 'closed']), self::pr(6, 'wk-45', ['title' => 'WIP: Fix callbacks'])],
        });

        $pr = $forge->prForBranch('acme/web', 'wk-45');

        $this->assertNotNull($pr);
        $this->assertSame([6, 'OPEN', true, 'main', 'WIP: Fix callbacks'], [$pr->number, $pr->state, $pr->isDraft, $pr->baseRefName, $pr->title]);
        $this->assertSame('repos/acme/web/pulls?state=all&sort=recentupdate&limit=50&page=1', end($this->apiCalls()[0]));
        $this->assertCount(2, $this->apiCalls()); // the fork's PR does not stop the walk; a found one does
    }

    public function testPrForBranchWithoutPullRequest(): void
    {
        $this->assertNull($this->forge(static fn (): array => [self::pr(1, 'other')])->prForBranch('acme/web', 'wk-45'));
    }

    public function testCiStatusEvaluatesTheHeadCommitsCombinedStatus(): void
    {
        $statuses = [];
        $forge = $this->forge(static function (string $endpoint) use (&$statuses): array {
            return str_contains($endpoint, '/status') ? ['state' => 'x', 'statuses' => $statuses] : self::pr(5, 'wk-1');
        });

        $statuses = [['context' => 'CI / test (pull_request)', 'status' => 'success'], ['context' => 'CI / lint (pull_request)', 'status' => 'failure']];
        $this->assertSame('red', $forge->ciStatus('acme/web', 5));
        $this->assertSame('green', $forge->ciStatus('acme/web', 5, ['lint']));
        $statuses = [['context' => 'build', 'status' => 'pending'], ['context' => 'docs', 'status' => 'warning']];
        $this->assertSame('pending', $forge->ciStatus('acme/web', 5));
        $statuses = [];
        $this->assertSame('green', $forge->ciStatus('acme/web', 5));
        $this->assertContains('repos/acme/web/commits/sha5/status?limit=50&page=1', array_map(static fn (array $a): string => (string) end($a), $this->apiCalls()));
    }

    /**
     * @param list<array<mixed>> $reviews
     * @param list<array<mixed>> $timeline
     * @param array<int, true>   $unresolved
     * @param list<string>       $whitelist
     */
    private static function verdict(array $reviews, array $timeline = [], array $unresolved = [], array $whitelist = []): ?string
    {
        $snapshot = GiteaReviews::fromApi(self::pr(1, 'b'), $reviews, $timeline, $unresolved);
        self::assertNotNull($snapshot);

        return $snapshot->verdict($whitelist);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private static function review(int $id, string $login, string $state, string $at, string $body = '', array $extra = []): array
    {
        return $extra + ['id' => $id, 'user' => ['login' => $login, 'type' => 'User'], 'state' => $state, 'body' => $body, 'submitted_at' => $at, 'dismissed' => false, 'comments_count' => 0];
    }

    public function testReviewVerdicts(): void
    {
        $this->assertNull(self::verdict([]));
        $this->assertSame(ReviewVerdict::APPROVED, self::verdict([self::review(1, 'rev', 'APPROVED', '2026-01-02T00:00:00Z')]));
        $this->assertSame(ReviewVerdict::CHANGES, self::verdict([self::review(1, 'rev', 'REQUEST_CHANGES', '2026-01-02T00:00:00Z')]));
        // changes beat approvals; each reviewer's latest review decides
        $this->assertSame(ReviewVerdict::CHANGES, self::verdict([
            self::review(1, 'a', 'APPROVED', '2026-01-02T00:00:00Z'),
            self::review(2, 'b', 'REQUEST_CHANGES', '2026-01-02T00:00:00Z'),
        ]));
        $this->assertSame(ReviewVerdict::APPROVED, self::verdict([
            self::review(1, 'rev', 'REQUEST_CHANGES', '2026-01-02T00:00:00Z'),
            self::review(2, 'rev', 'APPROVED', '2026-01-03T00:00:00Z'),
        ]));
    }

    public function testReviewsThatNeverCount(): void
    {
        $this->assertNull(self::verdict([self::review(1, 'author', 'REQUEST_CHANGES', '2026-01-02T00:00:00Z')]));
        $this->assertNull(self::verdict([self::review(1, 'rev', 'REQUEST_CHANGES', '2026-01-02T00:00:00Z', extra: ['dismissed' => true])]));
        $this->assertNull(self::verdict([self::review(1, 'rev', 'PENDING', '2026-01-02T00:00:00Z', 'draft')]));
        $bot = self::review(1, 'renovate', 'REQUEST_CHANGES', '2026-01-02T00:00:00Z', extra: ['user' => ['login' => 'renovate', 'type' => 'Bot']]);
        $this->assertNull(self::verdict([$bot]));
        $this->assertSame(ReviewVerdict::CHANGES, self::verdict([$bot], whitelist: ['renovate']));
    }

    public function testMarkingTheDraftReadyStartsANewRound(): void
    {
        $ready = [['type' => 'change_title', 'created_at' => '2026-01-05T00:00:00Z', 'old_title' => 'WIP: Fix', 'new_title' => 'Fix']];
        $this->assertNull(self::verdict([self::review(1, 'rev', 'APPROVED', '2026-01-04T00:00:00Z')], $ready));
        $this->assertSame(ReviewVerdict::APPROVED, self::verdict([self::review(1, 'rev', 'APPROVED', '2026-01-06T00:00:00Z')], $ready));
        // a title change that keeps (or adds) the prefix is no ready event
        $retitled = [['type' => 'change_title', 'created_at' => '2026-01-05T00:00:00Z', 'old_title' => 'Fix', 'new_title' => 'WIP: Fix']];
        $this->assertSame(ReviewVerdict::APPROVED, self::verdict([self::review(1, 'rev', 'APPROVED', '2026-01-04T00:00:00Z')], $retitled));
    }

    public function testACommentReviewCountsWhileItCarriesFeedback(): void
    {
        $comment = self::review(7, 'rev', 'COMMENT', '2026-01-02T00:00:00Z', extra: ['comments_count' => 2]);
        $this->assertNull(self::verdict([$comment]));
        $this->assertSame(ReviewVerdict::CHANGES, self::verdict([$comment], unresolved: [7 => true]));
        $this->assertSame(ReviewVerdict::CHANGES, self::verdict([self::review(7, 'rev', 'COMMENT', '2026-01-02T00:00:00Z', 'Please rename')]));
        // a bot's body is its summary, never feedback
        $botSummary = self::review(7, 'ai', 'COMMENT', '2026-01-02T00:00:00Z', 'Looks fine overall', ['user' => ['login' => 'ai', 'type' => 'Bot']]);
        $this->assertNull(self::verdict([$botSummary], whitelist: ['ai']));
        $this->assertSame(ReviewVerdict::CHANGES, self::verdict([$botSummary], unresolved: [7 => true], whitelist: ['ai']));
    }

    public function testReviewVerdictReadsTheUnresolvedCommentsOfCommentReviews(): void
    {
        $forge = $this->forge(static fn (string $endpoint): array => match (true) {
            str_contains($endpoint, '/reviews/7/comments') => [['id' => 1, 'body' => 'rename', 'resolver' => null]],
            str_contains($endpoint, '/reviews/8/comments') => [['id' => 2, 'body' => 'ok', 'resolver' => ['login' => 'author']]],
            str_contains($endpoint, '/reviews') => [
                self::review(7, 'a', 'COMMENT', '2026-01-02T00:00:00Z', extra: ['comments_count' => 1]),
                self::review(8, 'b', 'COMMENT', '2026-01-02T00:00:00Z', extra: ['comments_count' => 1]),
            ],
            str_contains($endpoint, '/timeline') => [],
            default => self::pr(3, 'wk-1'),
        });

        $this->assertSame(ReviewVerdict::CHANGES, $forge->reviewVerdict('acme/web', 3, []));
        $endpoints = array_map(static fn (array $a): string => (string) end($a), $this->apiCalls());
        $this->assertContains('repos/acme/web/pulls/3/reviews?limit=50&page=1', $endpoints);
        $this->assertContains('repos/acme/web/issues/3/timeline?limit=50&page=1', $endpoints);
    }

    public function testMarkReadyAndMarkDraftGoThroughGtPrReady(): void
    {
        $forge = $this->forge(static fn (): string => '');

        $forge->markReady('acme/web', 3);
        $forge->markDraft('acme/web', 3);

        $this->assertSame([
            ['gt', 'pr', 'ready', '3', '--host', 'gitea.acme.io', '--repo', 'acme/web', '--no-input'],
            ['gt', 'pr', 'ready', '3', '--undo', '--host', 'gitea.acme.io', '--repo', 'acme/web', '--no-input'],
        ], $this->argvs);
    }

    public function testRerunCiRerunsTheLatestCompletedRunOfEachWorkflow(): void
    {
        $forge = $this->forge(static fn (string $endpoint): array => str_contains($endpoint, '/rerun')
            ? ['id' => 99]
            : ['total_count' => 4, 'workflow_runs' => [
                ['id' => 12, 'path' => 'ci.yml@refs/heads/wk-1', 'status' => 'completed'],
                ['id' => 11, 'path' => 'ci.yml@refs/heads/wk-1', 'status' => 'completed'],
                ['id' => 13, 'path' => 'lint.yml@refs/heads/wk-1', 'status' => 'in_progress'],
                ['id' => 10, 'path' => 'lint.yml@refs/heads/wk-1', 'status' => 'completed'],
            ]]);

        $this->assertSame(['12', '10'], $forge->rerunCi('acme/web', 'wk-1'));
        $endpoints = array_map(static fn (array $a): string => (string) end($a), $this->apiCalls());
        $this->assertSame(['gt', 'api', '--host', 'gitea.acme.io', '--no-input', '--method', 'POST', 'repos/acme/web/actions/runs/12/rerun'], $this->apiCalls()[1]);
        $this->assertSame([
            'repos/acme/web/actions/runs?branch=wk-1&limit=50',
            'repos/acme/web/actions/runs/12/rerun',
            'repos/acme/web/actions/runs/10/rerun',
        ], $endpoints);
    }

    public function testRerunCiWithoutCompletedRunFails(): void
    {
        $this->expectException(PabloError::class);
        $this->forge(static fn (): array => ['total_count' => 0, 'workflow_runs' => []])->rerunCi('acme/web', 'wk-1');
    }

    public function testPollSnapshot(): void
    {
        $forge = $this->forge(static fn (string $endpoint): array => match (true) {
            str_contains($endpoint, 'pulls?') => [self::pr(3, 'wk-1'), self::pr(4, 'wk-2', ['state' => 'closed', 'merged' => true])],
            str_contains($endpoint, 'pulls/404') => ['message' => 'The target couldn\'t be found.', 'url' => 'x'],
            str_contains($endpoint, 'commits/sha3/status') => ['statuses' => [['context' => 'ci', 'status' => 'failure']]],
            str_contains($endpoint, 'commits/sha4/status') => ['statuses' => []],
            str_contains($endpoint, '/reviews') => [self::review(1, 'rev', 'APPROVED', '2026-01-02T00:00:00Z')],
            str_contains($endpoint, '/timeline') => [],
            str_contains($endpoint, 'pulls/3') => self::pr(3, 'wk-1'),
            str_contains($endpoint, 'pulls/4') => self::pr(4, 'wk-2', ['state' => 'closed', 'merged' => true]),
            default => throw new \LogicException($endpoint),
        });

        $snapshot = $forge->pollSnapshot('acme/web', ['wk-1', 'wk-9'], [3, 4, 404], [3]);

        $this->assertSame(3, $snapshot->branchPrs['wk-1']?->number);
        $this->assertArrayHasKey('wk-9', $snapshot->branchPrs);
        $this->assertNull($snapshot->branchPrs['wk-9']);
        $this->assertSame([3 => 'OPEN', 4 => 'MERGED'], $snapshot->states);
        $this->assertTrue($snapshot->rollups[3][0]->failed);
        $this->assertSame([], $snapshot->rollups[4]);
        $this->assertSame([3], array_keys($snapshot->reviews));
        $this->assertSame(ReviewVerdict::APPROVED, $snapshot->reviews[3]->verdict([]));
    }

    public function testPollSnapshotNeverThrows(): void
    {
        $forge = $this->forge(static fn (): string => throw new PabloError('gt exploded'));

        $snapshot = $forge->pollSnapshot('acme/web', ['wk-1'], [3], [3]);

        $this->assertSame([[], [], [], []], [$snapshot->branchPrs, $snapshot->states, $snapshot->rollups, $snapshot->reviews]);
    }
}
