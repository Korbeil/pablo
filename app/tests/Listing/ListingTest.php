<?php

declare(strict_types=1);

namespace Pablo\Tests\Listing;

use Pablo\Agents\SessionInfo;
use Pablo\Config\ProjectConfig;
use Pablo\Domain\Issue;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Listing\Listing;
use Pablo\Provider\Gh\GhPr;
use Pablo\Provider\Gh\PrInfo;
use Pablo\Provider\Git\GitRepo;
use Pablo\Provider\Tracker\Provider;
use Pablo\Provider\Tracker\ProviderRegistry;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Pablo\Support\RepoSlug;
use Pablo\Tests\FakeAgents;
use PHPUnit\Framework\TestCase;

final class FakeProvider implements Provider
{
    /** @var list<Issue> */
    public array $issues = [];

    public string $status = 'In Progress';

    public function listAssigned(ProjectConfig $cfg): array
    {
        return $this->issues;
    }

    public function issueStatus(string $key, ProjectConfig $cfg): string
    {
        return $this->status;
    }

    public function batchIssueStatus(array $pairs): array
    {
        $result = [];
        foreach ($pairs as [$key]) {
            $result[$key] = $this->status;
        }

        return $result;
    }

    public function name(): string
    {
        return 'github';
    }

    public function supportsSignalViaStatus(): bool
    {
        return false;
    }

    public function matchUrl(string $url, ProjectConfig $cfg): ?string
    {
        return null;
    }

    public function getIssue(string $ref, ProjectConfig $cfg): Issue
    {
        throw new \LogicException('not used');
    }

    public function failureSignalEvents(Task $task, ProjectConfig $cfg): array
    {
        return [];
    }

    public function cliName(): string
    {
        return 'gh';
    }

    public function authCheckCmd(): array
    {
        return ['gh', 'auth', 'status'];
    }
}

final class ListingTest extends TestCase
{
    private string $tmp;
    private ProjectConfig $cfg;
    private Store $store;
    private FakeAgents $agents;
    private FakeProvider $provider;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-listing-'.uniqid();
        mkdir($this->tmp, 0o777, true);
        $this->cfg = new ProjectConfig(
            name: 'wallet-kit',
            type: 'open-source',
            repoPath: $this->tmp.'/repo',
            primaryBranch: 'main',
            worktreesRoot: $this->tmp.'/wt',
            provider: 'github',
            identity: 'korbeil',
            projectKey: 'WK',
            syncStrategy: 'rebase',
            syncAutoApply: false,
            syncInterval: 30,
            pollInterval: 10,
            failureSignal: null,
            botWhitelist: [],
            ciIgnoreChecks: [],
        );
        $this->store = new Store($this->tmp.'/state');
        $this->agents = new FakeAgents();
        $this->provider = new FakeProvider();
        $this->provider->issues = [
            new Issue('github', '45', 'u45', 'Fix callbacks', 'WK', 'To Do'),
            new Issue('github', '46', 'u46', 'Add exports', 'WK', 'Done'),
        ];
        $this->provider->status = 'In Progress';

        ProviderRegistry::setResolver($this->providerResolve(...));
        RepoSlug::setFor(static fn () => 'acme/wallet-kit');
        GitRepo::setAllBranchNames(static fn () => ['wk-45', 'main']);
        GhPr::setPrForBranch(static fn () => null);
        GhPr::setPrsForBranches(static fn () => []);
    }

    protected function tearDown(): void
    {
        ProviderRegistry::setResolver(null);
        RepoSlug::setFor(null);
        GitRepo::setAllBranchNames(null);
        GhPr::setPrForBranch(null);
        GhPr::setPrsForBranches(null);
        $this->removeDir($this->tmp);
    }

    private function providerResolve(string $name): Provider
    {
        return $this->provider;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $p = $dir.'/'.$item;
            if (is_dir($p)) {
                $this->removeDir($p);
            } else {
                unlink($p);
            }
        }
        rmdir($dir);
    }

    private function task(string $branch, State $state, ?int $prNumber = null, ?Issue $issue = null, ?string $summary = null, string $wt = '/tmp/x'): Task
    {
        $t = new Task('wallet-kit', $branch, $wt, $state);
        $t->prNumber = $prNumber;
        $t->issue = $issue;
        $t->summary = $summary;

        return $t;
    }

    /** @return array<string, ProjectConfig> */
    private function projects(): array
    {
        return ['wallet-kit' => $this->cfg];
    }

    public function testIssuesTableShowsBranchAndBlankCells(): void
    {
        GhPr::setPrForBranch(static function (string $slug, string $branch): ?PrInfo {
            return 'wk-45' === $branch ? new PrInfo(7, 'PR', 'OPEN', false, 'u', null) : null;
        });
        $table = Listing::issuesTable($this->cfg, $this->store);
        $lines = explode("\n", $table);
        $row45 = null;
        foreach ($lines as $line) {
            if (str_contains($line, '45') && str_contains($line, 'Fix callbacks')) {
                $row45 = $line;
            }
        }
        $this->assertNotNull($row45);
        $this->assertStringContainsString('wk-45', $row45);
        $this->assertStringContainsString('#7', $row45);
        $row46 = null;
        foreach ($lines as $line) {
            if (str_contains($line, 'Add exports')) {
                $row46 = $line;
            }
        }
        $this->assertNotNull($row46);
        $this->assertStringContainsString('-', $row46);
    }

    public function testTasksRowForIssueTask(): void
    {
        $this->store->save($this->task('wk-45', State::NeedsTesting, 7, new Issue('github', '45', 'u', 'Fix callbacks', 'WK')));
        $table = Listing::tasksTable($this->projects(), $this->store, $this->agents);
        $this->assertStringContainsString('🧪 needs-testing', $table);
        $this->assertStringContainsString('wk-45', $table);
        $this->assertStringContainsString('Fix callbacks', $table);
        $this->assertStringContainsString('In Progress', $table);
    }

    public function testTasksRowPromptTaskShowsSummaryAndNa(): void
    {
        $this->store->save($this->task('wk-fix-hooks', State::InProgress, null, null, 'fix flaky webhooks', '/tmp/y'));
        $table = Listing::tasksTable($this->projects(), $this->store, $this->agents);
        $this->assertStringContainsString('fix flaky webhooks', $table);
        $this->assertStringContainsString('N/A', $table);
    }

    public function testQueueTasksEmptyStoreReturnsEmptyList(): void
    {
        $this->assertSame([], Listing::queueTasks($this->projects(), $this->store, State::NeedsTesting->value));
    }

    public function testQueueTasksUnknownStateRaises(): void
    {
        $this->expectException(PabloError::class);
        Listing::queueTasks($this->projects(), $this->store, 'bogus-state');
    }

    public function testQueueTasksIncludesMatchingTaskWithPr(): void
    {
        GhPr::setPrForBranch(static fn () => new PrInfo(7, 'Fix callbacks', 'OPEN', false, 'https://github.com/acme/wallet-kit/pull/7', null));
        $this->store->save($this->task('wk-45', State::NeedsTesting, 7, new Issue('github', '45', 'u', 'Fix callbacks', 'WK')));
        $this->store->save($this->task('wk-46', State::WaitingReview));

        $rows = Listing::queueTasks($this->projects(), $this->store, State::NeedsTesting->value);

        $this->assertCount(1, $rows);
        $this->assertSame('wallet-kit', $rows[0]['project']);
        $this->assertSame('wk-45', $rows[0]['branch']);
        $this->assertSame('45', $rows[0]['issue']['key']);
        $this->assertSame([
            'number' => 7,
            'title' => 'Fix callbacks',
            'url' => 'https://github.com/acme/wallet-kit/pull/7',
            'is_draft' => false,
        ], $rows[0]['pr']);
    }

    public function testQueueTasksTaskWithNoPrYet(): void
    {
        $this->store->save($this->task('wk-fix-hooks', State::NeedsTesting, null, null, 'fix flaky webhooks', '/tmp/y'));
        $rows = Listing::queueTasks($this->projects(), $this->store, State::NeedsTesting->value);
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['pr']);
        $this->assertNull($rows[0]['issue']);
        $this->assertSame('fix flaky webhooks', $rows[0]['summary']);
    }

    public function testReadyToReviewRenderedAsWaitingReview(): void
    {
        $this->store->save($this->task('wk-45', State::ReadyToReview));
        $table = Listing::tasksTable($this->projects(), $this->store, $this->agents);
        $this->assertStringContainsString('👀 waiting-review', $table);
        $this->assertStringNotContainsString('ready-to-review', $table);
    }

    public function testMergedDeferredShowsCheckEmoji(): void
    {
        $t = $this->task('wk-45', State::NeedsTesting, 7);
        $t->merged = true;
        $this->store->save($t);
        $table = Listing::tasksTable($this->projects(), $this->store, $this->agents);
        $this->assertStringContainsString('✅ merged', $table);
    }

    public function testPrStatesRendered(): void
    {
        GhPr::setPrsForBranches(static fn ($slug, $branches) => [$branches[0] => new PrInfo(7, 'PR', 'OPEN', true, 'u', null)]);
        $this->store->save($this->task('wk-45', State::InProgress, 7));
        $table = Listing::tasksTable($this->projects(), $this->store, $this->agents);
        $this->assertStringContainsString('📪 draft', $table);
    }

    public function testAgentColumns(): void
    {
        $this->agents->bulk = ['/tmp/x' => [new SessionInfo('a', 'running'), new SessionInfo('b', 'waiting')]];
        $this->store->save($this->task('wk-45', State::InProgress));
        $table = Listing::tasksTable($this->projects(), $this->store, $this->agents);
        $this->assertStringContainsString('🏃 1 · 💭 1', $table);
    }

    public function testCachedTaskRendersWithoutLiveCalls(): void
    {
        $boom = static function (): mixed {
            throw new \LogicException('must not be called when cache is populated');
        };
        $provider = new FakeProvider();
        ProviderRegistry::setResolver(static fn (string $name): Provider => $provider);
        GhPr::setPrForBranch(static fn () => $boom);
        GhPr::setPrsForBranches(static fn () => $boom);
        $this->agents->active = [new SessionInfo('x', 'running')];

        $t = $this->task('wk-45', State::NeedsTesting, 7, new Issue('github', '45', 'u', 'Fix callbacks', 'WK'));
        $t->displayCache = new \Pablo\Domain\DisplayCache('In Review', '📬 open #7', 2, '🏃 2', null);
        $this->store->save($t);

        $table = Listing::tasksTable($this->projects(), $this->store, $this->agents);
        $this->assertStringContainsString('In Review', $table);
        $this->assertStringContainsString('📬 open #7', $table);
        $this->assertStringContainsString('🏃 2', $table);
    }

    public function testLiveFlagBypassesCacheWithoutWritingIt(): void
    {
        $t = $this->task('wk-45', State::NeedsTesting, null, new Issue('github', '45', 'u', 'Fix callbacks', 'WK'));
        $t->displayCache = new \Pablo\Domain\DisplayCache('stale status', null, null, null, null);
        $this->store->save($t);

        $table = Listing::tasksTable($this->projects(), $this->store, $this->agents, live: true);
        $this->assertStringContainsString('In Progress', $table);
        $this->assertStringNotContainsString('stale status', $table);
        $reloaded = $this->store->get('wallet-kit', 'wk-45');
        $this->assertNotNull($reloaded);
        $this->assertSame('stale status', $reloaded->displayCache->trackerStatus);
    }

    public function testRefreshFlagFetchesLiveAndPersists(): void
    {
        $t = $this->task('wk-45', State::NeedsTesting, null, new Issue('github', '45', 'u', 'Fix callbacks', 'WK'));
        $t->displayCache = new \Pablo\Domain\DisplayCache('stale status', null, null, null, null);
        $this->store->save($t);

        Listing::tasksTable($this->projects(), $this->store, $this->agents, refresh: true);
        $reloaded = $this->store->get('wallet-kit', 'wk-45');
        $this->assertNotNull($reloaded);
        $this->assertSame('In Progress', $reloaded->displayCache->trackerStatus);
        $this->assertNotNull($reloaded->displayCache->at);
    }

    public function testTasksSplitWaitingFeedbackFirst(): void
    {
        $this->agents->bulk = ['/tmp/x' => [new SessionInfo('a', 'waiting')]];
        $this->store->save($this->task('wk-45', State::InProgress));
        $this->store->save($this->task('wk-46', State::NeedsTesting, null, null, null, '/tmp/y'));

        $table = Listing::tasksTable($this->projects(), $this->store, $this->agents);
        $this->assertStringContainsString('💭 Waiting for feedback', $table);
        $this->assertStringContainsString('Other tasks', $table);
        $otherIdx = strpos($table, 'Other tasks');
        $this->assertLessThan($otherIdx, strpos($table, 'wk-45'));
        $this->assertGreaterThan($otherIdx, strpos($table, 'wk-46'));
    }

    public function testTasksSortedByState(): void
    {
        $this->store->save($this->task('wk-in-progress', State::InProgress, null, null, null, '/tmp/a'));
        $this->store->save($this->task('wk-testing-failed', State::TestingFailed, null, null, null, '/tmp/b'));

        $table = Listing::tasksTable($this->projects(), $this->store, $this->agents);
        $lines = array_values(array_filter(explode("\n", $table), static fn ($l) => str_contains($l, 'wk-')));
        $this->assertStringContainsString('wk-testing-failed', $lines[0]);
        $this->assertStringContainsString('wk-in-progress', $lines[1]);
    }

    public function testTasksNoWaitingHeaderWhenNoWaitingAgents(): void
    {
        $this->store->save($this->task('wk-45', State::InProgress));
        $table = Listing::tasksTable($this->projects(), $this->store, $this->agents);
        $this->assertStringNotContainsString('💭 Waiting for feedback', $table);
        $this->assertStringNotContainsString('Other tasks', $table);
        $this->assertStringContainsString('wk-45', $table);
    }

    public function testTasksWaitingAgentExcludedForNonEligibleState(): void
    {
        $this->agents->bulk = ['/tmp/x' => [new SessionInfo('a', 'waiting')]];
        $this->store->save($this->task('wk-45', State::NeedsTesting));
        $table = Listing::tasksTable($this->projects(), $this->store, $this->agents);
        $this->assertStringNotContainsString('💭 Waiting for feedback', $table);
        $this->assertStringNotContainsString('Other tasks', $table);
        $this->assertStringContainsString('wk-45', $table);
    }

    public function testRenderSlackEmptyRowsWaitingReview(): void
    {
        $this->assertSame('No PRs waiting for review right now 🎉', Listing::renderSlack([], State::WaitingReview->value));
    }

    public function testRenderSlackEmptyRowsNeedsTesting(): void
    {
        $this->assertSame('Nothing needs testing right now 🎉', Listing::renderSlack([], State::NeedsTesting->value));
    }

    public function testRenderSlackEmptyRowsUnknownStateFallsBack(): void
    {
        $this->assertStringContainsString('🎉', Listing::renderSlack([], 'request-changes'));
    }

    /** @param array<string, mixed>|null $pr
     * @return array<string, mixed>
     */
    private function row(string $project, ?array $pr): array
    {
        return ['project' => $project, 'branch' => 'b', 'issue' => null, 'summary' => null, 'pr' => $pr];
    }

    public function testRenderSlackGroupsByProjectWithMrkdwnLinks(): void
    {
        $rows = [
            $this->row('wallet-kit', ['number' => 7, 'title' => 'Fix callbacks', 'url' => 'https://github.com/acme/wallet-kit/pull/7', 'is_draft' => false]),
            $this->row('acme-pim', ['number' => 12, 'title' => 'Add exports', 'url' => 'https://github.com/acme/pim/pull/12', 'is_draft' => false]),
            $this->row('wallet-kit', ['number' => 9, 'title' => 'Tidy tests', 'url' => 'https://github.com/acme/wallet-kit/pull/9', 'is_draft' => false]),
        ];
        $out = Listing::renderSlack($rows, State::WaitingReview->value);
        $this->assertSame(
            "*wallet-kit*\n• https://github.com/acme/wallet-kit/pull/7 #7 Fix callbacks\n• https://github.com/acme/wallet-kit/pull/9 #9 Tidy tests\n*acme-pim*\n• https://github.com/acme/pim/pull/12 #12 Add exports",
            $out,
        );
    }

    public function testRenderSlackSkipsTasksWithoutPrAndDropsEmptyGroups(): void
    {
        $rows = [
            $this->row('wallet-kit', null),
            $this->row('acme-pim', ['number' => 12, 'title' => 'Add exports', 'url' => 'https://github.com/acme/pim/pull/12', 'is_draft' => false]),
        ];
        $out = Listing::renderSlack($rows, State::NeedsTesting->value);
        $this->assertSame("*acme-pim*\n• https://github.com/acme/pim/pull/12 #12 Add exports", $out);
    }

    public function testRenderSlackUsesIssueKeyAndTitleWhenIssuePresent(): void
    {
        $rows = [[
            'project' => 'wallet-kit',
            'branch' => 'wk-45',
            'issue' => ['key' => 'WK-45', 'title' => 'Fix callbacks', 'url' => 'https://acme.atlassian.net/browse/WK-45'],
            'summary' => null,
            'pr' => ['number' => 7, 'title' => 'PR title ignored', 'url' => 'https://github.com/acme/wallet-kit/pull/7', 'is_draft' => false],
        ]];
        $out = Listing::renderSlack($rows, State::NeedsTesting->value);
        $this->assertSame("*wallet-kit*\n• https://github.com/acme/wallet-kit/pull/7 WK-45 Fix callbacks", $out);
    }

    public function testRenderSlackAllPrsMissingReturnsEmptyMessage(): void
    {
        $rows = [$this->row('wallet-kit', null)];
        $this->assertSame('No PRs waiting for review right now 🎉', Listing::renderSlack($rows, State::WaitingReview->value));
    }

    public function testDisplayWidthAscii(): void
    {
        $this->assertSame(5, Listing::displayWidth('hello'));
        $this->assertSame(0, Listing::displayWidth(''));
    }

    public function testDisplayWidthEmoji(): void
    {
        $this->assertSame(2, Listing::displayWidth('🔨'));
        $this->assertSame(2, Listing::displayWidth('✅'));
        $this->assertSame(2, Listing::displayWidth('💭'));
    }

    public function testDisplayWidthMixed(): void
    {
        $this->assertSame(14, Listing::displayWidth('🔨 in-progress'));
        $this->assertSame(11, Listing::displayWidth('🏃 1 · 💭 1'));
    }

    public function testPadRightEmojiCell(): void
    {
        $this->assertSame('hello   ', Listing::padRight('hello', 8));
        $this->assertSame('🔨 x  ', Listing::padRight('🔨 x', 6));
        $this->assertSame('✅ merged #7   ', Listing::padRight('✅ merged #7', 15));
    }

    public function testAllTaskRowsAligned(): void
    {
        $this->agents->bulk = ['/tmp/x' => [new SessionInfo('a', 'running'), new SessionInfo('b', 'waiting')]];
        GhPr::setPrsForBranches(static fn ($slug, $branches) => [$branches[0] => new PrInfo(7, 'PR', 'OPEN', true, 'u', null)]);
        $this->store->save($this->task('wk-45', State::InProgress, 7, new Issue('github', '45', 'u', 'Fix callbacks', 'WK')));
        $this->store->save($this->task('wk-fix-hooks', State::NeedsTesting, null, null, 'fix flaky webhooks', '/tmp/y'));

        $table = Listing::tasksTable($this->projects(), $this->store, $this->agents);
        $lines = explode("\n", $table);

        $this->assertStringContainsString('wk-45', $table);
        $this->assertStringContainsString('wk-fix-hooks', $table);
        $dataLines = array_values(array_filter(explode("\n", $table), static function (string $l): bool {
            return str_contains($l, '|') && !str_contains($l, 'Waiting for feedback') && !str_contains($l, 'Other tasks');
        }));
        $this->assertNotEmpty($dataLines);
        $widths = array_map(static fn (string $l) => substr_count($l, '|'), $dataLines);
        $this->assertCount(1, array_unique($widths), 'all rows must have the same column count');
    }
}
