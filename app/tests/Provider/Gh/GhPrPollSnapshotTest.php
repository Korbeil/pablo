<?php

declare(strict_types=1);

namespace Pablo\Tests\Provider\Gh;

use Pablo\Provider\Gh\CiCheck;
use Pablo\Provider\Gh\GhPr;
use Pablo\Provider\Gh\ReviewVerdict;
use Pablo\Support\PabloError;
use Pablo\Tests\FakeProcessRunner;
use PHPUnit\Framework\TestCase;

/**
 * GhPr::pollSnapshot(): one aliased GraphQL query per repository, whose
 * answers must equal what the per-PR calls (prForBranch(), prState(),
 * ciStatus(), reviewVerdict()) would have returned, and which leaves out
 * whatever it could not read in full.
 */
final class GhPrPollSnapshotTest extends TestCase
{
    private FakeProcessRunner $runner;

    /** @var list<array{argv: list<string>, check: bool, timeout: ?float}> */
    private array $calls = [];

    /** @param callable(string, array<string, string>): array<string, mixed> $respond (query, -f vars) => decoded response */
    private function respond(callable $respond): void
    {
        $this->runner = new FakeProcessRunner();
        $this->runner->onRun = function (array $argv, bool $check, ?float $timeout) use ($respond): string {
            $argv = array_values(array_map(strval(...), $argv));
            $this->calls[] = ['argv' => $argv, 'check' => $check, 'timeout' => $timeout];

            return json_encode($respond(self::query($argv), self::vars($argv)), \JSON_THROW_ON_ERROR);
        };
    }

    /** @param list<string> $argv */
    private static function query(array $argv): string
    {
        return self::vars($argv)['query'] ?? '';
    }

    /**
     * @param list<string> $argv
     *
     * @return array<string, string>
     */
    private static function vars(array $argv): array
    {
        $vars = [];
        foreach ($argv as $i => $arg) {
            if ('-f' === $arg) {
                [$k, $v] = explode('=', $argv[$i + 1], 2);
                $vars[$k] = $v;
            }
        }

        return $vars;
    }

    private function svc(): GhPr
    {
        return new GhPr($this->runner, new \Pablo\Domain\Time());
    }

    /**
     * @param list<array<string, mixed>> $contexts
     *
     * @return array<string, mixed> a pullRequest node with its rollup
     */
    private static function prNode(string $state, array $contexts = [], ?string $mergedAt = null, bool $morePages = false): array
    {
        return [
            'state' => $state,
            'mergedAt' => $mergedAt,
            'commits' => ['nodes' => [['commit' => ['statusCheckRollup' => [
                'contexts' => ['pageInfo' => ['hasNextPage' => $morePages], 'nodes' => $contexts],
            ]]]]],
        ];
    }

    /** @return array<string, mixed> */
    private static function checkRun(string $name, string $workflow, string $status, ?string $conclusion, ?string $startedAt): array
    {
        return [
            '__typename' => 'CheckRun', 'name' => $name, 'status' => $status, 'conclusion' => $conclusion, 'startedAt' => $startedAt,
            'checkSuite' => ['workflowRun' => '' === $workflow ? null : ['workflow' => ['name' => $workflow]]],
        ];
    }

    public function testOneQueryAnswersBranchesStatesAndCi(): void
    {
        $this->respond(static fn (): array => ['data' => ['repository' => [
            'b0' => ['nodes' => [['number' => 7, 'title' => 'Fix', 'state' => 'OPEN', 'isDraft' => true, 'mergedAt' => null, 'url' => 'u7', 'baseRefName' => 'main']]],
            'b1' => ['nodes' => []],
            'p7' => self::prNode('OPEN', [self::checkRun('test', 'CI', 'COMPLETED', 'FAILURE', '2026-07-22T10:00:00Z')]),
            'p8' => self::prNode('CLOSED', [], '2026-07-22T00:00:00Z'),
        ]]]);

        $snapshot = $this->svc()->pollSnapshot('acme/wallet-kit', ['wk-45', 'wk-46'], [7, 8], []);

        $this->assertCount(1, $this->calls);
        $this->assertFalse($this->calls[0]['check']); // an unresolvable alias must not lose the others
        $this->assertSame((float) GhPr::GH_CALL_TIMEOUT_S, (float) $this->calls[0]['timeout']);
        $vars = self::vars($this->calls[0]['argv']);
        $this->assertSame(['wk-45', 'wk-46', 'acme', 'wallet-kit'], [$vars['b0'], $vars['b1'], $vars['owner'], $vars['repo']]);
        // Same query shape as `gh pr list --head <b> --state all --limit 1`.
        $this->assertStringContainsString('b0: pullRequests(headRefName: $b0, states: [OPEN, CLOSED, MERGED], first: 1, orderBy: {field: CREATED_AT, direction: DESC})', $vars['query']);

        $this->assertSame(['wk-45', 'wk-46'], array_keys($snapshot->branchPrs));
        $this->assertSame(7, $snapshot->branchPrs['wk-45']?->number);
        $this->assertTrue($snapshot->branchPrs['wk-45']->isDraft);
        $this->assertSame('main', $snapshot->branchPrs['wk-45']->baseRefName);
        $this->assertNull($snapshot->branchPrs['wk-46']);
        $this->assertSame([7 => 'OPEN', 8 => 'MERGED'], $snapshot->states); // mergedAt wins, as in prState()
        $this->assertSame('red', GhPr::evaluateCi($snapshot->rollups[7]));
        $this->assertSame([], $snapshot->rollups[8]); // no checks: green, as gh exports none
        $this->assertSame([], $snapshot->reviews);
        // Fragments GraphQL would refuse as unused are not sent.
        $this->assertStringNotContainsString('fragment snapReviews', $vars['query']);
    }

    public function testTheRollupIsTheOneGhPrViewExports(): void
    {
        $graphql = [
            self::checkRun('build', 'CI', 'COMPLETED', 'CANCELLED', '2026-07-22T10:00:00Z'),
            self::checkRun('build', 'CI', 'COMPLETED', 'SUCCESS', '2026-07-22T10:05:00Z'),
            self::checkRun('lint', '', 'QUEUED', null, null),
            ['__typename' => 'StatusContext', 'context' => 'ci/circleci: hold', 'state' => 'PENDING', 'createdAt' => '2026-07-22T10:00:00Z'],
        ];
        // What `gh pr view --json statusCheckRollup` prints for the same
        // contexts (api/export_pr.go: Go zero values for nulls).
        $ghExport = [
            ['__typename' => 'CheckRun', 'name' => 'build', 'workflowName' => 'CI', 'status' => 'COMPLETED', 'conclusion' => 'CANCELLED', 'startedAt' => '2026-07-22T10:00:00Z', 'completedAt' => '2026-07-22T10:01:00Z', 'detailsUrl' => 'u'],
            ['__typename' => 'CheckRun', 'name' => 'build', 'workflowName' => 'CI', 'status' => 'COMPLETED', 'conclusion' => 'SUCCESS', 'startedAt' => '2026-07-22T10:05:00Z', 'completedAt' => '2026-07-22T10:06:00Z', 'detailsUrl' => 'u'],
            ['__typename' => 'CheckRun', 'name' => 'lint', 'workflowName' => '', 'status' => 'QUEUED', 'conclusion' => '', 'startedAt' => '0001-01-01T00:00:00Z', 'completedAt' => '0001-01-01T00:00:00Z', 'detailsUrl' => ''],
            ['__typename' => 'StatusContext', 'context' => 'ci/circleci: hold', 'state' => 'PENDING', 'targetUrl' => 'u', 'startedAt' => '2026-07-22T10:00:00Z'],
        ];
        $this->respond(static fn (): array => ['data' => ['repository' => ['p7' => self::prNode('OPEN', $graphql)]]]);

        $snapshot = $this->svc()->pollSnapshot('acme/wallet-kit', [], [7], []);

        $this->assertEquals(array_map(CiCheck::fromRollup(...), $ghExport), $snapshot->rollups[7]);
        $this->assertSame('pending', GhPr::evaluateCi($snapshot->rollups[7]));
        $this->assertSame('green', GhPr::evaluateCi($snapshot->rollups[7], ['lint', 'hold']));
    }

    public function testAnythingIncompleteIsLeftToThePerPrCall(): void
    {
        $this->respond(static fn (): array => [
            'data' => ['repository' => [
                'b0' => ['nodes' => [['number' => 7, 'title' => 'Fix', 'state' => 'OPEN', 'isDraft' => false, 'mergedAt' => null, 'url' => 'u7', 'baseRefName' => 'main']]],
                'p7' => self::prNode('OPEN', [], null, morePages: true), // >100 contexts: gh view would page on
                'p8' => null,                                             // NOT_FOUND
                'p9' => ['state' => 'OPEN', 'mergedAt' => null, 'commits' => ['nodes' => [['commit' => ['statusCheckRollup' => null]]]]],
            ]],
            'errors' => [
                ['type' => 'NOT_FOUND', 'path' => ['repository', 'p8'], 'message' => 'Could not resolve to a PullRequest with the number of 8.'],
                // A failed nullable field: the null must not read as "no checks".
                ['path' => ['repository', 'p9', 'commits', 'nodes', 0, 'commit', 'statusCheckRollup'], 'message' => 'timeout'],
            ],
        ]);

        $snapshot = $this->svc()->pollSnapshot('acme/wallet-kit', ['wk-45'], [7, 8, 9], []);

        $this->assertSame(7, $snapshot->branchPrs['wk-45']?->number);
        $this->assertSame([7 => 'OPEN'], $snapshot->states);
        $this->assertSame([], $snapshot->rollups);
    }

    public function testAnErrorOutsideAnyAliasOrAFailedCallDiscardsTheBatch(): void
    {
        foreach ([
            static fn (): string => json_encode(['data' => ['repository' => ['p7' => self::prNode('OPEN')]], 'errors' => [['message' => 'something global']]], \JSON_THROW_ON_ERROR),
            static fn (): string => '<!DOCTYPE html>',
            static fn (): string => '',
            static function (): never {
                throw new PabloError('gh timed out after 20s');
            },
        ] as $reply) {
            $this->runner = new FakeProcessRunner();
            $this->runner->onRun = $reply;

            $snapshot = $this->svc()->pollSnapshot('acme/wallet-kit', ['wk-45'], [7], [7]);

            $this->assertSame([[], [], [], []], [$snapshot->branchPrs, $snapshot->states, $snapshot->rollups, $snapshot->reviews]);
        }
    }

    public function testReviewsAreReadOnlyWhereAskedAndOnlyWhenComplete(): void
    {
        $reviewed = static fn (bool $morePages): array => [
            'createdAt' => '2026-07-01T00:00:00Z',
            'author' => ['login' => 'me'],
            'timelineItems' => ['nodes' => [['createdAt' => '2026-07-20T12:00:00Z']]],
            'reviews' => ['pageInfo' => ['hasPreviousPage' => $morePages], 'nodes' => [
                // Before the ready event: a previous round, ignored.
                ['id' => 'r1', 'author' => ['login' => 'bob', '__typename' => 'User'], 'state' => 'CHANGES_REQUESTED', 'submittedAt' => '2026-07-10T00:00:00Z', 'body' => ''],
                ['id' => 'r2', 'author' => ['login' => 'alice', '__typename' => 'User'], 'state' => 'APPROVED', 'submittedAt' => '2026-07-21T00:00:00Z', 'body' => ''],
            ]],
            'reviewThreads' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => []],
        ];
        $this->respond(static fn (): array => ['data' => ['repository' => [
            'p7' => self::prNode('OPEN') + $reviewed(false),
            'p8' => self::prNode('OPEN') + $reviewed(true),
            'p9' => self::prNode('OPEN'),
        ]]]);

        $snapshot = $this->svc()->pollSnapshot('acme/wallet-kit', [], [7, 8, 9], [7, 8]);

        $query = self::query($this->calls[0]['argv']);
        $this->assertStringContainsString('p7: pullRequest(number: 7) { ...snapStatus ...snapReviews }', $query);
        $this->assertStringContainsString('p9: pullRequest(number: 9) { ...snapStatus }', $query);
        // The first page of the per-PR queries, nothing less.
        $this->assertStringContainsString('reviews(last: 100)', $query);
        $this->assertStringContainsString('reviewThreads(first: 100)', $query);
        $this->assertStringContainsString('comments(last: 100)', $query);
        $this->assertStringContainsString('timelineItems(itemTypes: [READY_FOR_REVIEW_EVENT], last: 1)', $query);

        $this->assertSame([7], array_keys($snapshot->reviews)); // #8 has older reviews: fetchReviews() pages
        $this->assertSame(ReviewVerdict::APPROVED, $snapshot->reviews[7]->verdict([]));
        $this->assertSame('me', $snapshot->reviews[7]->reviews->author);
    }

    public function testLargeProjectsAreSplitIntoBoundedQueries(): void
    {
        $this->respond(static fn (): array => ['data' => ['repository' => []]]);

        $branches = array_map(static fn (int $i): string => "b-{$i}", range(1, GhPr::SNAPSHOT_BRANCHES_PER_QUERY + 10));
        $prs = range(1, 2 * GhPr::SNAPSHOT_PRS_PER_QUERY + 5);
        $this->svc()->pollSnapshot('acme/wallet-kit', $branches, $prs, []);

        $this->assertCount(3, $this->calls);
        $counts = array_map(static fn (array $call): array => [
            substr_count(self::query($call['argv']), 'pullRequests(headRefName:'),
            substr_count(self::query($call['argv']), ': pullRequest(number:'),
        ], $this->calls);
        $this->assertSame([[GhPr::SNAPSHOT_BRANCHES_PER_QUERY, GhPr::SNAPSHOT_PRS_PER_QUERY], [10, GhPr::SNAPSHOT_PRS_PER_QUERY], [0, 5]], $counts);
    }

    public function testBranchNamesTravelAsRawStrings(): void
    {
        $this->respond(static fn (): array => ['data' => ['repository' => ['b0' => ['nodes' => []]]]]);

        $snapshot = $this->svc()->pollSnapshot('acme/wallet-kit', ['1234'], [], []);

        // -f, never -F: gh would turn "1234" into a number (and "@x" into a file read).
        $argv = $this->calls[0]['argv'];
        $this->assertSame('-f', $argv[array_search('b0=1234', $argv, true) - 1]);
        $this->assertNotContains('-F', $argv);
        $this->assertArrayHasKey('1234', $snapshot->branchPrs);
    }

    public function testNothingToReadMakesNoCall(): void
    {
        $this->respond(static fn (): array => self::fail('no call expected'));

        $snapshot = $this->svc()->pollSnapshot('acme/wallet-kit', [], [], []);

        $this->assertSame([], $this->calls);
        $this->assertSame([], $snapshot->branchPrs);
    }
}
