<?php

declare(strict_types=1);

namespace Pablo\Tests;

use Pablo\Provider\Gh\GhPr;
use Pablo\Support\PabloError;
use Pablo\Support\Proc;
use PHPUnit\Framework\TestCase;

final class GhPrTest extends TestCase
{
    private const ANCHOR = '2026-07-20T12:00:00+00:00';

    protected function tearDown(): void
    {
        Proc::setRunner(null);
    }

    /**
     * @return array{author: array{login: string, __typename: string}, state: string, submittedAt: string}
     */
    private static function review(string $login, string $state, string $when, string $typename = 'User'): array
    {
        return [
            'author' => ['login' => $login, '__typename' => $typename],
            'state' => $state,
            'submittedAt' => $when,
        ];
    }

    public function testPrsForBranchesMatchesMultipleBranchesFromOneCall(): void
    {
        $calls = [];
        Proc::setRunner(static function (array $argv, bool $check = true, ?float $timeout = null) use (&$calls): string {
            $calls[] = $argv;

            return json_encode([
                ['number' => 7, 'title' => 'Fix callbacks', 'state' => 'OPEN', 'isDraft' => false, 'mergedAt' => null, 'url' => 'u7', 'headRefName' => 'wk-45'],
                ['number' => 8, 'title' => 'Add exports', 'state' => 'MERGED', 'isDraft' => false, 'mergedAt' => '2026-07-20T00:00:00Z', 'url' => 'u8', 'headRefName' => 'wk-46'],
                ['number' => 9, 'title' => 'Unrelated', 'state' => 'OPEN', 'isDraft' => false, 'mergedAt' => null, 'url' => 'u9', 'headRefName' => 'some-other-branch'],
            ], \JSON_THROW_ON_ERROR);
        });

        $result = GhPr::prsForBranches('acme/wallet-kit', ['wk-45', 'wk-46', 'wk-47']);

        $this->assertCount(1, $calls); // one gh call for all branches
        $this->assertSame(['wk-45', 'wk-46'], array_keys($result)); // wk-47 has no PR
        $this->assertSame(7, $result['wk-45']->number);
        $this->assertSame('MERGED', $result['wk-46']->state);
    }

    public function testPrsForBranchesEmptyListMakesNoCall(): void
    {
        Proc::setRunner(function (): string {
            $this->fail('must not be called for an empty branch list');
        });
        $this->assertSame([], GhPr::prsForBranches('acme/wallet-kit', []));
    }

    public function testCiRedOnFailure(): void
    {
        $rollup = [
            ['status' => 'COMPLETED', 'conclusion' => 'SUCCESS'],
            ['status' => 'COMPLETED', 'conclusion' => 'FAILURE'],
        ];
        $this->assertSame('red', GhPr::evaluateCi($rollup));
    }

    public function testCiRedWinsOverPending(): void
    {
        $rollup = [
            ['status' => 'IN_PROGRESS', 'conclusion' => null],
            ['status' => 'COMPLETED', 'conclusion' => 'FAILURE'],
        ];
        $this->assertSame('red', GhPr::evaluateCi($rollup));
    }

    public function testCiPendingNotGreen(): void
    {
        $rollup = [
            ['status' => 'COMPLETED', 'conclusion' => 'SUCCESS'],
            ['status' => 'QUEUED', 'conclusion' => null],
        ];
        $this->assertSame('pending', GhPr::evaluateCi($rollup));
    }

    public function testCiGreenWhenAllPassOrSkipped(): void
    {
        $rollup = [
            ['status' => 'COMPLETED', 'conclusion' => 'SUCCESS'],
            ['status' => 'COMPLETED', 'conclusion' => 'SKIPPED'],
            ['status' => 'COMPLETED', 'conclusion' => 'NEUTRAL'],
        ];
        $this->assertSame('green', GhPr::evaluateCi($rollup));
    }

    public function testCiGreenWhenNoChecks(): void
    {
        $this->assertSame('green', GhPr::evaluateCi([]));
    }

    public function testCiStatusContextShape(): void
    {
        $rollup = [['state' => 'SUCCESS'], ['state' => 'FAILURE']];
        $this->assertSame('red', GhPr::evaluateCi($rollup));
    }

    public function testCiIgnoresApprovalCheckByName(): void
    {
        $rollup = [
            ['status' => 'COMPLETED', 'conclusion' => 'SUCCESS'],
            ['status' => 'COMPLETED', 'conclusion' => 'ACTION_REQUIRED', 'name' => 'hold-for-approval'],
        ];
        $this->assertSame('red', GhPr::evaluateCi($rollup));
        $this->assertSame('green', GhPr::evaluateCi($rollup, ['approval']));
    }

    public function testCiIgnoreChecksMatchesWorkflowNameAndContext(): void
    {
        $rollup = [
            ['status' => 'COMPLETED', 'conclusion' => 'ACTION_REQUIRED', 'workflowName' => 'Approval Gate'],
            ['state' => 'PENDING', 'context' => 'ci/circleci: approval-job'],
        ];
        $this->assertSame('green', GhPr::evaluateCi($rollup, ['approval']));
    }

    public function testCiIgnoreChecksRealCircleciApprovalShape(): void
    {
        $rollup = [
            ['__typename' => 'CheckRun', 'status' => 'COMPLETED', 'conclusion' => 'SUCCESS', 'name' => 'Labeler'],
            ['__typename' => 'StatusContext', 'context' => 'ci/circleci: tests_workflow/deploy-code-approval-ppr', 'state' => 'PENDING'],
            ['__typename' => 'StatusContext', 'context' => 'ci/circleci: tests_workflow/deploy-code-approval-prod', 'state' => 'PENDING'],
            ['__typename' => 'StatusContext', 'context' => 'ci/circleci: build', 'state' => 'SUCCESS'],
            ['__typename' => 'StatusContext', 'context' => 'ci/circleci: tests', 'state' => 'SUCCESS'],
        ];
        $this->assertSame('pending', GhPr::evaluateCi($rollup));
        $this->assertSame('green', GhPr::evaluateCi($rollup, ['approval']));
    }

    public function testCiIgnoreChecksCaseInsensitiveAndUnmatchedStillEvaluated(): void
    {
        $rollup = [
            ['status' => 'COMPLETED', 'conclusion' => 'ACTION_REQUIRED', 'name' => 'APPROVAL-hold'],
            ['status' => 'COMPLETED', 'conclusion' => 'FAILURE', 'name' => 'unit-tests'],
        ];
        $this->assertSame('red', GhPr::evaluateCi($rollup, ['Approval']));
    }

    public function testReviewsExcludeAuthor(): void
    {
        $reviews = [self::review('me', 'CHANGES_REQUESTED', '2026-07-21T10:00:00Z')];
        $this->assertNull(GhPr::evaluateReviews($reviews, new \DateTimeImmutable(self::ANCHOR), 'me', []));
    }

    public function testReviewsExcludeBotUnlessWhitelisted(): void
    {
        $reviews = [self::review('sonar[bot]', 'COMMENTED', '2026-07-21T10:00:00Z', 'Bot')];
        $this->assertNull(GhPr::evaluateReviews($reviews, new \DateTimeImmutable(self::ANCHOR), 'me', []));
        $this->assertSame('changes', GhPr::evaluateReviews($reviews, new \DateTimeImmutable(self::ANCHOR), 'me', ['sonar[bot]']));
    }

    public function testReviewsBeforeAnchorIgnored(): void
    {
        $reviews = [self::review('alice', 'CHANGES_REQUESTED', '2026-07-19T10:00:00Z')];
        $this->assertNull(GhPr::evaluateReviews($reviews, new \DateTimeImmutable(self::ANCHOR), 'me', []));
    }

    public function testLatestPerReviewerWins(): void
    {
        $reviews = [
            self::review('alice', 'CHANGES_REQUESTED', '2026-07-21T10:00:00Z'),
            self::review('alice', 'APPROVED', '2026-07-22T10:00:00Z'),
        ];
        $this->assertSame('approved', GhPr::evaluateReviews($reviews, new \DateTimeImmutable(self::ANCHOR), 'me', []));
    }

    public function testMixedVerdictsChangesWins(): void
    {
        $reviews = [
            self::review('alice', 'APPROVED', '2026-07-21T10:00:00Z'),
            self::review('bob', 'CHANGES_REQUESTED', '2026-07-21T11:00:00Z'),
        ];
        $this->assertSame('changes', GhPr::evaluateReviews($reviews, new \DateTimeImmutable(self::ANCHOR), 'me', []));
    }

    public function testCommentReviewCountsAsChanges(): void
    {
        $reviews = [self::review('alice', 'COMMENTED', '2026-07-21T10:00:00Z')];
        $this->assertSame('changes', GhPr::evaluateReviews($reviews, new \DateTimeImmutable(self::ANCHOR), 'me', []));
    }

    public function testDismissedReviewsIgnored(): void
    {
        $reviews = [self::review('alice', 'DISMISSED', '2026-07-21T10:00:00Z')];
        $this->assertNull(GhPr::evaluateReviews($reviews, new \DateTimeImmutable(self::ANCHOR), 'me', []));
    }

    public function testNoReviewsReturnsNull(): void
    {
        $this->assertNull(GhPr::evaluateReviews([], new \DateTimeImmutable(self::ANCHOR), 'me', []));
    }

    public function testRerunCiRerunsLatestPerWorkflow(): void
    {
        $calls = [];
        Proc::setRunner(static function (array $argv) use (&$calls): string {
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

        $result = GhPr::rerunCi('acme/wallet-kit', 'wk-45');

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
        Proc::setRunner(static fn (): string => '[]');

        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('no completed workflow runs');
        GhPr::rerunCi('acme/wallet-kit', 'wk-45');
    }
}
