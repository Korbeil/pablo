<?php

declare(strict_types=1);

namespace Pablo\Tests;

use Pablo\Provider\Gh\GhPr;
use Pablo\Support\PabloError;
use PHPUnit\Framework\TestCase;

final class GhPrTest extends TestCase
{
    private FakeProcessRunner $runner;

    /** Registers a canned runner and returns it. */
    private function startRunner(callable $fn): FakeProcessRunner
    {
        $r = new FakeProcessRunner();
        $r->onRun = $fn;
        $this->runner = $r;

        return $r;
    }
    private const ANCHOR = '2026-07-20T12:00:00+00:00';

    private static function review(string $login, string $state, string $when, string $typename = 'User', string $id = '', string $body = ''): \Pablo\Provider\Gh\ReviewNode
    {
        return new \Pablo\Provider\Gh\ReviewNode($login, $typename, $state, $when, $id, $body);
    }

    private static function thread(bool $resolved, string ...$reviewIds): \Pablo\Provider\Gh\ReviewThread
    {
        return new \Pablo\Provider\Gh\ReviewThread($resolved, array_values($reviewIds));
    }

    public function testPrsForBranchesMatchesMultipleBranchesFromOneCall(): void
    {
        $calls = [];
        $this->startRunner(static function (array $argv, bool $check = true, ?float $timeout = null) use (&$calls): string {
            $calls[] = $argv;

            return json_encode([
                ['number' => 7, 'title' => 'Fix callbacks', 'state' => 'OPEN', 'isDraft' => false, 'mergedAt' => null, 'url' => 'u7', 'headRefName' => 'wk-45'],
                ['number' => 8, 'title' => 'Add exports', 'state' => 'MERGED', 'isDraft' => false, 'mergedAt' => '2026-07-20T00:00:00Z', 'url' => 'u8', 'headRefName' => 'wk-46'],
                ['number' => 9, 'title' => 'Unrelated', 'state' => 'OPEN', 'isDraft' => false, 'mergedAt' => null, 'url' => 'u9', 'headRefName' => 'some-other-branch'],
            ], \JSON_THROW_ON_ERROR);
        });

        $result = $this->svc()->prsForBranches('acme/wallet-kit', ['wk-45', 'wk-46', 'wk-47']);

        $this->assertCount(1, $calls); // one gh call for all branches
        $this->assertSame(['wk-45', 'wk-46'], array_keys($result)); // wk-47 has no PR
        $this->assertSame(7, $result['wk-45']->number);
        $this->assertSame('MERGED', $result['wk-46']->state);
    }

    public function testPrsForBranchesEmptyListMakesNoCall(): void
    {
        $this->startRunner(function (): string {
            $this->fail('must not be called for an empty branch list');
        });
        $this->assertSame([], $this->svc()->prsForBranches('acme/wallet-kit', []));
    }

    public function testCiRedOnFailure(): void
    {
        $rollup = array_map(\Pablo\Provider\Gh\CiCheck::fromRollup(...), [
            ['status' => 'COMPLETED', 'conclusion' => 'SUCCESS'],
            ['status' => 'COMPLETED', 'conclusion' => 'FAILURE'],
        ]);
        $this->assertSame('red', $this->svc()->evaluateCi($rollup));
    }

    public function testCiRedWinsOverPending(): void
    {
        $rollup = array_map(\Pablo\Provider\Gh\CiCheck::fromRollup(...), [
            ['status' => 'IN_PROGRESS', 'conclusion' => null],
            ['status' => 'COMPLETED', 'conclusion' => 'FAILURE'],
        ]);
        $this->assertSame('red', $this->svc()->evaluateCi($rollup));
    }

    public function testCiPendingNotGreen(): void
    {
        $rollup = array_map(\Pablo\Provider\Gh\CiCheck::fromRollup(...), [
            ['status' => 'COMPLETED', 'conclusion' => 'SUCCESS'],
            ['status' => 'QUEUED', 'conclusion' => null],
        ]);
        $this->assertSame('pending', $this->svc()->evaluateCi($rollup));
    }

    public function testCiGreenWhenAllPassOrSkipped(): void
    {
        $rollup = array_map(\Pablo\Provider\Gh\CiCheck::fromRollup(...), [
            ['status' => 'COMPLETED', 'conclusion' => 'SUCCESS'],
            ['status' => 'COMPLETED', 'conclusion' => 'SKIPPED'],
            ['status' => 'COMPLETED', 'conclusion' => 'NEUTRAL'],
        ]);
        $this->assertSame('green', $this->svc()->evaluateCi($rollup));
    }

    public function testCiGreenWhenNoChecks(): void
    {
        $this->assertSame('green', $this->svc()->evaluateCi([]));
    }

    public function testCiStatusContextShape(): void
    {
        $rollup = array_map(\Pablo\Provider\Gh\CiCheck::fromRollup(...), [
            ['state' => 'SUCCESS'],
            ['state' => 'FAILURE'],
        ]);
        $this->assertSame('red', $this->svc()->evaluateCi($rollup));
    }

    public function testCiIgnoresApprovalCheckByName(): void
    {
        $rollup = array_map(\Pablo\Provider\Gh\CiCheck::fromRollup(...), [
            ['status' => 'COMPLETED', 'conclusion' => 'SUCCESS'],
            ['status' => 'COMPLETED', 'conclusion' => 'ACTION_REQUIRED', 'name' => 'hold-for-approval'],
        ]);
        $this->assertSame('red', $this->svc()->evaluateCi($rollup));
        $this->assertSame('green', $this->svc()->evaluateCi($rollup, ['approval']));
    }

    public function testCiIgnoreChecksMatchesWorkflowNameAndContext(): void
    {
        $rollup = array_map(\Pablo\Provider\Gh\CiCheck::fromRollup(...), [
            ['status' => 'COMPLETED', 'conclusion' => 'ACTION_REQUIRED', 'workflowName' => 'Approval Gate'],
            ['state' => 'PENDING', 'context' => 'ci/circleci: approval-job'],
        ]);
        $this->assertSame('green', $this->svc()->evaluateCi($rollup, ['approval']));
    }

    public function testCiIgnoreChecksRealCircleciApprovalShape(): void
    {
        $rollup = array_map(\Pablo\Provider\Gh\CiCheck::fromRollup(...), [
            ['__typename' => 'CheckRun', 'status' => 'COMPLETED', 'conclusion' => 'SUCCESS', 'name' => 'Labeler'],
            ['__typename' => 'StatusContext', 'context' => 'ci/circleci: tests_workflow/deploy-code-approval-ppr', 'state' => 'PENDING'],
            ['__typename' => 'StatusContext', 'context' => 'ci/circleci: tests_workflow/deploy-code-approval-prod', 'state' => 'PENDING'],
            ['__typename' => 'StatusContext', 'context' => 'ci/circleci: build', 'state' => 'SUCCESS'],
            ['__typename' => 'StatusContext', 'context' => 'ci/circleci: tests', 'state' => 'SUCCESS'],
        ]);
        $this->assertSame('pending', $this->svc()->evaluateCi($rollup));
        $this->assertSame('green', $this->svc()->evaluateCi($rollup, ['approval']));
    }

    public function testCiIgnoreChecksCaseInsensitiveAndUnmatchedStillEvaluated(): void
    {
        $rollup = array_map(\Pablo\Provider\Gh\CiCheck::fromRollup(...), [
            ['status' => 'COMPLETED', 'conclusion' => 'ACTION_REQUIRED', 'name' => 'APPROVAL-hold'],
            ['status' => 'COMPLETED', 'conclusion' => 'FAILURE', 'name' => 'unit-tests'],
        ]);
        $this->assertSame('red', $this->svc()->evaluateCi($rollup, ['Approval']));
    }

    public function testReviewsExcludeAuthor(): void
    {
        $reviews = [self::review('me', 'CHANGES_REQUESTED', '2026-07-21T10:00:00Z')];
        $this->assertNull($this->svc()->evaluateReviews($reviews, [], new \DateTimeImmutable(self::ANCHOR), 'me', []));
    }

    public function testReviewsExcludeBotUnlessWhitelisted(): void
    {
        $reviews = [self::review('sonar[bot]', 'COMMENTED', '2026-07-21T10:00:00Z', 'Bot')];
        $this->assertNull($this->svc()->evaluateReviews($reviews, [], new \DateTimeImmutable(self::ANCHOR), 'me', []));
        $this->assertSame('changes', $this->svc()->evaluateReviews($reviews, [], new \DateTimeImmutable(self::ANCHOR), 'me', ['sonar[bot]']));
    }

    public function testBotWhitelistAcceptsLoginWithOrWithoutBotSuffix(): void
    {
        // GraphQL reports Copilot as "copilot-pull-request-reviewer", the UI
        // and REST API as "copilot-pull-request-reviewer[bot]".
        $reviews = [self::review('copilot-pull-request-reviewer', 'COMMENTED', '2026-07-21T10:00:00Z', 'Bot', id: 'r1')];
        $threads = [self::thread(false, 'r1')];
        foreach (['copilot-pull-request-reviewer', 'copilot-pull-request-reviewer[bot]'] as $listed) {
            $this->assertSame('changes', $this->svc()->evaluateReviews($reviews, $threads, new \DateTimeImmutable(self::ANCHOR), 'me', [$listed]));
        }
    }

    public function testWhitelistedBotSummaryBodyAloneDoesNotCount(): void
    {
        // Copilot writes an overview on every review, even "approval
        // recommended": without an unresolved thread there is nothing to address.
        $reviews = [self::review('copilot-pull-request-reviewer', 'COMMENTED', '2026-07-21T10:00:00Z', 'Bot', id: 'r1', body: '### 🟢 Approval recommended')];
        $whitelist = ['copilot-pull-request-reviewer'];
        $this->assertNull($this->svc()->evaluateReviews($reviews, [], new \DateTimeImmutable(self::ANCHOR), 'me', $whitelist));
        $this->assertNull($this->svc()->evaluateReviews($reviews, [self::thread(true, 'r1')], new \DateTimeImmutable(self::ANCHOR), 'me', $whitelist));
    }

    public function testWhitelistedBotUnresolvedThreadCounts(): void
    {
        $reviews = [self::review('copilot-pull-request-reviewer', 'COMMENTED', '2026-07-21T10:00:00Z', 'Bot', id: 'r1', body: '### 🟡 Changes recommended')];
        $this->assertSame('changes', $this->svc()->evaluateReviews(
            $reviews,
            [self::thread(false, 'r1')],
            new \DateTimeImmutable(self::ANCHOR), 'me', ['copilot-pull-request-reviewer'],
        ));
    }

    public function testReviewsBeforeAnchorIgnored(): void
    {
        $reviews = [self::review('alice', 'CHANGES_REQUESTED', '2026-07-19T10:00:00Z')];
        $this->assertNull($this->svc()->evaluateReviews($reviews, [], new \DateTimeImmutable(self::ANCHOR), 'me', []));
    }

    public function testLatestPerReviewerWins(): void
    {
        $reviews = [
            self::review('alice', 'CHANGES_REQUESTED', '2026-07-21T10:00:00Z'),
            self::review('alice', 'APPROVED', '2026-07-22T10:00:00Z'),
        ];
        $this->assertSame('approved', $this->svc()->evaluateReviews($reviews, [], new \DateTimeImmutable(self::ANCHOR), 'me', []));
    }

    public function testMixedVerdictsChangesWins(): void
    {
        $reviews = [
            self::review('alice', 'APPROVED', '2026-07-21T10:00:00Z'),
            self::review('bob', 'CHANGES_REQUESTED', '2026-07-21T11:00:00Z'),
        ];
        $this->assertSame('changes', $this->svc()->evaluateReviews($reviews, [], new \DateTimeImmutable(self::ANCHOR), 'me', []));
    }

    public function testCommentReviewCountsAsChanges(): void
    {
        $reviews = [self::review('alice', 'COMMENTED', '2026-07-21T10:00:00Z')];
        $this->assertSame('changes', $this->svc()->evaluateReviews($reviews, [], new \DateTimeImmutable(self::ANCHOR), 'me', []));
    }

    public function testResolvedOnlyCommentReviewStoppedCounting(): void
    {
        // #974: alice reviewed with inline comments, every thread got resolved
        // afterwards — the review no longer counts as a changes signal.
        $reviews = [self::review('alice', 'COMMENTED', '2026-07-21T10:00:00Z', id: 'r1')];
        $this->assertNull($this->svc()->evaluateReviews(
            $reviews,
            [self::thread(true, 'r1')],
            new \DateTimeImmutable(self::ANCHOR), 'me', [],
        ));
    }

    public function testUnresolvedThreadKeepsCommentReviewCounting(): void
    {
        $reviews = [self::review('alice', 'COMMENTED', '2026-07-21T10:00:00Z', id: 'r1')];
        $this->assertSame('changes', $this->svc()->evaluateReviews(
            $reviews,
            [self::thread(false, 'r1')],
            new \DateTimeImmutable(self::ANCHOR), 'me', [],
        ));
    }

    public function testBodyOnlyCommentReviewStillCounts(): void
    {
        // Top-level feedback ("please fix the naming") has no thread to
        // resolve: an empty thread story never clears it.
        $reviews = [self::review('alice', 'COMMENTED', '2026-07-21T10:00:00Z', id: 'r1', body: 'Please fix the naming.')];
        $this->assertSame('changes', $this->svc()->evaluateReviews(
            $reviews,
            [self::thread(true, 'r1')],
            new \DateTimeImmutable(self::ANCHOR), 'me', [],
        ));
    }

    public function testChangesRequestedKeepsCountingWhenThreadsResolved(): void
    {
        // CHANGES_REQUESTED is an explicit verdict of its own: resolving the
        // threads does not withdraw it, a new review from the reviewer does.
        $reviews = [self::review('alice', 'CHANGES_REQUESTED', '2026-07-21T10:00:00Z', id: 'r1')];
        $this->assertSame('changes', $this->svc()->evaluateReviews(
            $reviews,
            [self::thread(true, 'r1')],
            new \DateTimeImmutable(self::ANCHOR), 'me', [],
        ));
    }

    public function testDismissedReviewsIgnored(): void
    {
        $reviews = [self::review('alice', 'DISMISSED', '2026-07-21T10:00:00Z')];
        $this->assertNull($this->svc()->evaluateReviews($reviews, [], new \DateTimeImmutable(self::ANCHOR), 'me', []));
    }

    public function testNoReviewsReturnsNull(): void
    {
        $this->assertNull($this->svc()->evaluateReviews([], [], new \DateTimeImmutable(self::ANCHOR), 'me', []));
    }

    /**
     * @param list<int> $numbers review numbers, also their minute offset after the anchor
     *
     * @return list<array<string, mixed>>
     */
    private static function reviewNodes(array $numbers, string $login, string $state): array
    {
        return array_map(static fn (int $n): array => [
            'id' => 'r'.$n,
            'author' => ['login' => $login, '__typename' => 'User'],
            'state' => $state,
            'submittedAt' => (new \DateTimeImmutable(self::ANCHOR))->modify("+{$n} minutes")->format('Y-m-d\TH:i:s\Z'),
            'body' => '',
        ], $numbers);
    }

    /**
     * @param array<string, mixed>|null $reviews
     * @param array<string, mixed>|null $threads
     */
    private static function reviewsPage(?array $reviews, ?array $threads): string
    {
        $pr = ['author' => ['login' => 'me']];
        if (null !== $reviews) {
            $pr['reviews'] = $reviews;
        }
        if (null !== $threads) {
            $pr['reviewThreads'] = $threads;
        }

        return json_encode(['data' => ['repository' => ['pullRequest' => $pr]]], \JSON_THROW_ON_ERROR);
    }

    public function testFetchReviewsPaginatesBackwardsSoTheLatestReviewsAreSeen(): void
    {
        // A long-lived PR: 149 inline replies by the author, then alice's
        // approval as the 150th review. `first: 100` never saw it.
        $calls = [];
        $this->startRunner(static function (array $argv) use (&$calls): string {
            $calls[] = $argv;
            $query = (string) $argv[4];
            self::assertStringContainsString('reviews(last: 100, before: $reviewsBefore)', $query);
            self::assertStringContainsString('comments(last: 100)', $query);

            return match (\count($calls)) {
                // Newest page: reviews 51..150, threads page 1 of 2.
                1 => self::reviewsPage(
                    ['pageInfo' => ['hasPreviousPage' => true, 'startCursor' => 'R51'],
                        'nodes' => [...self::reviewNodes(range(51, 149), 'me', 'COMMENTED'), ...self::reviewNodes([150], 'alice', 'APPROVED')]],
                    ['pageInfo' => ['hasNextPage' => true, 'endCursor' => 'T100'],
                        'nodes' => [['isResolved' => true, 'comments' => ['nodes' => [['pullRequestReview' => ['id' => 'r1']]]]]]],
                ),
                // Older reviews 1..50 (bob's change request first), last thread page.
                2 => self::reviewsPage(
                    ['pageInfo' => ['hasPreviousPage' => false, 'startCursor' => 'R1'],
                        'nodes' => [...self::reviewNodes([1], 'bob', 'CHANGES_REQUESTED'), ...self::reviewNodes(range(2, 50), 'me', 'COMMENTED')]],
                    ['pageInfo' => ['hasNextPage' => false, 'endCursor' => 'T101'],
                        'nodes' => [['isResolved' => false, 'comments' => ['nodes' => [['pullRequestReview' => ['id' => 'r150']]]]]]],
                ),
                default => self::fail('no third page expected'),
            };
        });

        $fetched = $this->svc()->fetchReviews('acme/wallet-kit', 45);

        $this->assertCount(2, $calls);
        $this->assertNotContains('reviewsBefore=R51', $calls[0]);
        $this->assertContains('reviewsBefore=R51', $calls[1]);
        $this->assertContains('threadsAfter=T100', $calls[1]);
        $this->assertSame('me', $fetched->author);
        $this->assertCount(150, $fetched->reviews);
        // Chronological order is restored across pages.
        $this->assertSame('r1', $fetched->reviews[0]->id);
        $this->assertSame('r150', $fetched->reviews[149]->id);
        $this->assertSame('alice', $fetched->reviews[149]->login);
        $this->assertCount(2, $fetched->threads);
        $this->assertSame(['r150'], $fetched->threads[1]->reviewIds);

        // Both bob's old change request and alice's newest approval are seen.
        $this->assertSame('changes', $this->svc()->evaluateReviews(
            $fetched->reviews, $fetched->threads, new \DateTimeImmutable(self::ANCHOR), $fetched->author, [],
        ));
        $withoutBob = array_values(array_filter($fetched->reviews, static fn ($r) => 'bob' !== $r->login));
        $this->assertSame('approved', $this->svc()->evaluateReviews(
            $withoutBob, $fetched->threads, new \DateTimeImmutable(self::ANCHOR), $fetched->author, [],
        ));
    }

    public function testFetchReviewsSkipsACompleteConnectionOnFollowUpPages(): void
    {
        $calls = [];
        $this->startRunner(static function (array $argv) use (&$calls): string {
            $calls[] = $argv;

            return match (\count($calls)) {
                1 => self::reviewsPage(
                    ['pageInfo' => ['hasPreviousPage' => true, 'startCursor' => 'R2'], 'nodes' => self::reviewNodes([2], 'alice', 'APPROVED')],
                    ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'nodes' => []],
                ),
                // Threads were complete: the follow-up page omits them.
                2 => self::reviewsPage(
                    ['pageInfo' => ['hasPreviousPage' => false, 'startCursor' => 'R1'], 'nodes' => self::reviewNodes([1], 'bob', 'COMMENTED')],
                    null,
                ),
                default => self::fail('no third page expected'),
            };
        });

        $fetched = $this->svc()->fetchReviews('acme/wallet-kit', 45);

        $this->assertCount(2, $calls);
        $this->assertNotContains('withThreads=false', $calls[0]);
        $this->assertContains('withThreads=false', $calls[1]);
        $this->assertNotContains('withReviews=false', $calls[1]);
        $this->assertSame(['r1', 'r2'], array_map(static fn ($r) => $r->id, $fetched->reviews));
        $this->assertSame([], $fetched->threads);
    }

    public function testFetchReviewsStopsAtThePageCap(): void
    {
        $calls = 0;
        $this->startRunner(static function () use (&$calls): string {
            ++$calls;

            return self::reviewsPage(
                ['pageInfo' => ['hasPreviousPage' => true, 'startCursor' => 'R'.$calls], 'nodes' => self::reviewNodes([1000 - $calls], 'alice', 'COMMENTED')],
                ['pageInfo' => ['hasNextPage' => false], 'nodes' => []],
            );
        });

        $fetched = $this->svc()->fetchReviews('acme/wallet-kit', 45);

        $this->assertSame(GhPr::MAX_REVIEW_PAGES, $calls);
        $this->assertCount(GhPr::MAX_REVIEW_PAGES, $fetched->reviews);
        // Still chronological: the oldest page fetched comes first.
        $this->assertSame('r'.(1000 - GhPr::MAX_REVIEW_PAGES), $fetched->reviews[0]->id);
    }

    public function testRerunCiRerunsLatestPerWorkflow(): void
    {
        $calls = [];
        $this->startRunner(static function (array $argv) use (&$calls): string {
            $calls[] = $argv;
            if (\in_array('list', $argv, true)) {
                return json_encode([
                    ['databaseId' => 1, 'workflowName' => 'CI'],
                    ['databaseId' => 2, 'workflowName' => 'Lint'],
                    ['databaseId' => 3, 'workflowName' => 'CI'],
                    ['databaseId' => 4, 'workflowName' => 'E2E'],
                ], \JSON_THROW_ON_ERROR);
            }

            return '';
        });

        $result = $this->svc()->rerunCi('acme/wallet-kit', 'wk-45');

        $this->assertSame(['1', '2', '4'], $result);
        $this->assertCount(4, $calls);
        $this->assertContains('list', $calls[0]);
        $this->assertContains('--branch', $calls[0]);
        $this->assertContains('wk-45', $calls[0]);
        $rerunIds = array_map(static fn (array $a) => $a[3], \array_slice($calls, 1));
        $this->assertSame(['1', '2', '4'], $rerunIds);
    }

    public function testRerunCiNoRunsRaises(): void
    {
        $this->startRunner(static fn (): string => '[]');

        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('no completed workflow runs');
        $this->svc()->rerunCi('acme/wallet-kit', 'wk-45');
    }

    private function svc(): GhPr
    {
        return new GhPr($this->runner ?? new FakeProcessRunner(), new \Pablo\Domain\Time());
    }
}
