<?php

declare(strict_types=1);

namespace Pablo\Tests\Listing;

use Pablo\Agents\SessionInfo;
use Pablo\Config\ProjectConfig;
use Pablo\Domain\Agent;
use Pablo\Domain\AgentLaunch;
use Pablo\Domain\Issue;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Domain\Time;
use Pablo\Listing\Listing;
use Pablo\Provider\Gh\PrInfo;
use Pablo\Provider\Tracker\Provider;
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

    public function batchIssueStatus(array $issues): array
    {
        $result = [];
        foreach ($issues as $key => $_) {
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
    private \Pablo\Tests\FakeGhPr $gh;
    private \Pablo\Tests\FakeGit $git;
    private Listing $listing;
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
            identity: 'octocat',
            projectKey: 'WK',
            syncStrategy: 'rebase',
            syncAutoApply: false,
            syncInterval: 30,
            pollInterval: 10,
            failureSignal: null,
            botWhitelist: [],
            ciIgnoreChecks: [],
            defaultModel: 'openrouter/test/model',
            prDescriptionLocale: 'en',
        );
        $this->store = new Store($this->tmp.'/state');
        $this->agents = new FakeAgents();
        $this->provider = new FakeProvider();
        $this->provider->issues = [
            new Issue('github', '45', 'u45', 'Fix callbacks', 'WK', 'To Do'),
            new Issue('github', '46', 'u46', 'Add exports', 'WK', 'Done'),
        ];
        $this->provider->status = 'In Progress';

        $this->gh = new \Pablo\Tests\FakeGhPr();
        $this->git = new \Pablo\Tests\FakeGit();
        $this->git->allBranchNames = ['wk-45', 'main'];
        $providers = new class($this->provider) implements \Pablo\Provider\Tracker\ProviderRegistryInterface {
            public function __construct(private readonly Provider $provider)
            {
            }

            public function get(string $name): Provider
            {
                return $this->provider;
            }
        };
        $slugGit = new \Pablo\Tests\FakeGit();
        $slugGit->originUrl = 'git@github.com:acme/wallet-kit.git';
        $repoSlug = new RepoSlug($slugGit);
        $time = new Time();
        $sm = new \Pablo\StateMachine\StateMachine($this->gh, $providers, $repoSlug, $time);
        $this->listing = new Listing(new \Pablo\Dispatch\Stamps(), $this->gh, $this->git, $providers, $repoSlug, $sm, $time);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
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

    private function finished(Task $t): Task
    {
        $t->agentLaunches[Agent::TaskAnalyst->value] = new AgentLaunch(Agent::TaskAnalyst, (new Time())->utcnow(), 1, (new Time())->utcnow());

        return $t;
    }

    /** @return array<string, ProjectConfig> */
    private function projects(): array
    {
        return ['wallet-kit' => $this->cfg];
    }

    public function testIssuesTableShowsBranchAndBlankCells(): void
    {
        $this->gh->onPrForBranch = static function (string $slug, string $branch): ?PrInfo {
            return 'wk-45' === $branch ? new PrInfo(7, 'PR', 'OPEN', false, 'u', null) : null;
        };
        $table = $this->listing->issuesTable($this->cfg, $this->store);
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
        $table = $this->listing->tasksTable($this->projects(), $this->store, $this->agents);
        $this->assertStringContainsString('🧪 needs-testing', $table);
        $this->assertStringContainsString('wk-45', $table);
        $this->assertStringContainsString('Fix callbacks', $table);
        $this->assertStringContainsString('In Progress', $table);
    }

    public function testTasksRowPromptTaskShowsSummaryAndNa(): void
    {
        $this->store->save($this->task('wk-fix-hooks', State::InProgress, null, null, 'fix flaky webhooks', '/tmp/y'));
        $table = $this->listing->tasksTable($this->projects(), $this->store, $this->agents);
        $this->assertStringContainsString('fix flaky webhooks', $table);
        $this->assertStringContainsString('N/A', $table);
    }

    public function testQueueTasksEmptyStoreReturnsEmptyList(): void
    {
        $this->assertSame([], $this->listing->queueTasks($this->projects(), $this->store, State::NeedsTesting->value));
    }

    public function testQueueTasksUnknownStateRaises(): void
    {
        $this->expectException(PabloError::class);
        $this->listing->queueTasks($this->projects(), $this->store, 'bogus-state');
    }

    public function testQueueTasksIncludesMatchingTaskWithPr(): void
    {
        $this->gh->onPrForBranch = static fn (): PrInfo => new PrInfo(7, 'Fix callbacks', 'OPEN', false, 'https://github.com/acme/wallet-kit/pull/7', null);
        $this->store->save($this->task('wk-45', State::NeedsTesting, 7, new Issue('github', '45', 'u', 'Fix callbacks', 'WK')));
        $this->store->save($this->task('wk-46', State::WaitingReview));

        $rows = $this->listing->queueTasks($this->projects(), $this->store, State::NeedsTesting->value);

        $this->assertCount(1, $rows);
        $this->assertSame('wallet-kit', $rows[0]->project);
        $this->assertSame('wk-45', $rows[0]->branch);
        $this->assertSame('45', $rows[0]->issue?->key);
        $pr = $rows[0]->pr;
        $this->assertNotNull($pr);
        $this->assertSame(7, $pr->number);
        $this->assertSame('Fix callbacks', $pr->title);
        $this->assertSame('https://github.com/acme/wallet-kit/pull/7', $pr->url);
        $this->assertFalse($pr->isDraft);
    }

    public function testQueueTasksTaskWithNoPrYet(): void
    {
        $this->store->save($this->task('wk-fix-hooks', State::NeedsTesting, null, null, 'fix flaky webhooks', '/tmp/y'));
        $rows = $this->listing->queueTasks($this->projects(), $this->store, State::NeedsTesting->value);
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->pr);
        $this->assertNull($rows[0]->issue);
        $this->assertSame('fix flaky webhooks', $rows[0]->summary);
    }

    public function testReadyToReviewRenderedAsWaitingReview(): void
    {
        $this->store->save($this->task('wk-45', State::ReadyToReview));
        $table = $this->listing->tasksTable($this->projects(), $this->store, $this->agents);
        $this->assertStringContainsString('👀 waiting-review', $table);
        $this->assertStringNotContainsString('ready-to-review', $table);
    }

    public function testMergedDeferredShowsCheckEmoji(): void
    {
        $t = $this->task('wk-45', State::NeedsTesting, 7);
        $t->merged = true;
        $this->store->save($t);
        $table = $this->listing->tasksTable($this->projects(), $this->store, $this->agents);
        $this->assertStringContainsString('✅ merged', $table);
    }

    public function testPrStatesRendered(): void
    {
        $this->gh->prByBranch = ['wk-45' => new PrInfo(7, 'PR', 'OPEN', true, 'u', null)];
        $this->store->save($this->task('wk-45', State::InProgress, 7));
        $table = $this->listing->tasksTable($this->projects(), $this->store, $this->agents);
        $this->assertStringContainsString('📝 draft', $table);
    }

    public function testAgentColumns(): void
    {
        $this->agents->bulk = ['/tmp/x' => [new SessionInfo('a', 'running'), new SessionInfo('b', 'waiting')]];
        $this->store->save($this->task('wk-45', State::InProgress));
        $table = $this->listing->tasksTable($this->projects(), $this->store, $this->agents);
        $this->assertStringContainsString('🏃 1 · 💭 1', $table);
    }

    public function testCachedTaskRendersWithoutLiveCalls(): void
    {
        $this->gh->onPrForBranch = static function (): never {
            throw new \LogicException('must not be called when cache is populated');
        };
        $this->agents->active = [new SessionInfo('x', 'running')];

        $t = $this->task('wk-45', State::NeedsTesting, 7, new Issue('github', '45', 'u', 'Fix callbacks', 'WK'));
        $t->displayCache = new \Pablo\Domain\DisplayCache('In Review', '📖 open #7', 2, '🏃 2', null);
        $this->store->save($t);

        $table = $this->listing->tasksTable($this->projects(), $this->store, $this->agents);
        $this->assertStringContainsString('In Review', $table);
        $this->assertStringContainsString('📖 open #7', $table);
        $this->assertStringContainsString('🏃 2', $table);
    }

    public function testLiveFlagBypassesCacheWithoutWritingIt(): void
    {
        $t = $this->task('wk-45', State::NeedsTesting, null, new Issue('github', '45', 'u', 'Fix callbacks', 'WK'));
        $t->displayCache = new \Pablo\Domain\DisplayCache('stale status', null, null, null, null);
        $this->store->save($t);

        $table = $this->listing->tasksTable($this->projects(), $this->store, $this->agents, live: true);
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

        $this->listing->tasksTable($this->projects(), $this->store, $this->agents, refresh: true);
        $reloaded = $this->store->get('wallet-kit', 'wk-45');
        $this->assertNotNull($reloaded);
        $this->assertSame('In Progress', $reloaded->displayCache->trackerStatus);
        $this->assertNotNull($reloaded->displayCache->at);
    }

    public function testTasksSplitWaitingFeedbackFirst(): void
    {
        $this->store->save($this->finished($this->task('wk-45', State::InProgress)));
        $this->store->save($this->task('wk-46', State::NeedsTesting, null, null, null, '/tmp/y'));

        $table = $this->listing->tasksTable($this->projects(), $this->store, $this->agents);
        $this->assertStringContainsString('💭 Waiting for feedback', $table);
        $this->assertStringContainsString('Other tasks', $table);
        $otherIdx = strpos($table, 'Other tasks');
        $this->assertLessThan($otherIdx, strpos($table, 'wk-45'));
        $this->assertGreaterThan($otherIdx, strpos($table, 'wk-46'));
    }

    public function testTasksSplitIntoTwoSections(): void
    {
        $this->store->save($this->finished($this->task('wk-45', State::InProgress)));
        $this->agents->bulk = ['/tmp/y' => [new SessionInfo('b', 'running')]];
        $this->store->save($this->task('wk-46', State::Draft, null, null, null, '/tmp/y'));
        $this->store->save($this->task('wk-47', State::NeedsTesting, null, null, null, '/tmp/z'));

        $table = $this->listing->tasksTable($this->projects(), $this->store, $this->agents);
        $waitingIdx = strpos($table, '💭 Waiting for feedback');
        $otherIdx = strpos($table, 'Other tasks');
        $this->assertNotFalse($waitingIdx);
        $this->assertNotFalse($otherIdx);
        $this->assertLessThan($otherIdx, $waitingIdx);
        $this->assertGreaterThan($waitingIdx, strpos($table, 'wk-45'));
        $this->assertGreaterThan($otherIdx, strpos($table, 'wk-46'));
        $this->assertGreaterThan($otherIdx, strpos($table, 'wk-47'));
    }

    public function testWorkingAgentLandsInOtherTasks(): void
    {
        $this->agents->bulk = ['/tmp/x' => [new SessionInfo('a', 'running')]];
        $this->store->save($this->task('wk-45', State::NeedsTesting));

        $table = $this->listing->tasksTable($this->projects(), $this->store, $this->agents);
        $this->assertStringNotContainsString('Working', $table);
        $this->assertStringContainsString('wk-45', $table);
        $this->assertStringNotContainsString('Other tasks', $table);
    }

    public function testTasksSortedByState(): void
    {
        $this->store->save($this->task('wk-in-progress', State::InProgress, null, null, null, '/tmp/a'));
        $this->store->save($this->task('wk-testing-failed', State::TestingFailed, null, null, null, '/tmp/b'));

        $table = $this->listing->tasksTable($this->projects(), $this->store, $this->agents);
        $lines = array_values(array_filter(explode("\n", $table), static fn ($l) => str_contains($l, 'wk-')));
        $this->assertStringContainsString('wk-testing-failed', $lines[0]);
        $this->assertStringContainsString('wk-in-progress', $lines[1]);
    }

    public function testTasksNoWaitingHeaderWhenNoCompletedAgent(): void
    {
        $this->store->save($this->task('wk-45', State::InProgress));
        $table = $this->listing->tasksTable($this->projects(), $this->store, $this->agents);
        $this->assertStringNotContainsString('💭 Waiting for feedback', $table);
        $this->assertStringNotContainsString('Other tasks', $table);
        $this->assertStringContainsString('wk-45', $table);
    }

    public function testTasksWaitingAgentExcludedForNonEligibleState(): void
    {
        $this->store->save($this->finished($this->task('wk-45', State::NeedsTesting)));
        $table = $this->listing->tasksTable($this->projects(), $this->store, $this->agents);
        $this->assertStringNotContainsString('💭 Waiting for feedback', $table);
        $this->assertStringNotContainsString('Other tasks', $table);
        $this->assertStringContainsString('wk-45', $table);
    }

    public function testRunningAgentKeepsTaskOutOfWaitingSection(): void
    {
        $this->agents->bulk = ['/tmp/x' => [new SessionInfo('a', 'running')]];
        $this->store->save($this->finished($this->task('wk-45', State::InProgress)));

        $table = $this->listing->tasksTable($this->projects(), $this->store, $this->agents);
        $this->assertStringNotContainsString('💭 Waiting for feedback', $table);
        $this->assertStringNotContainsString('Working', $table);
        $this->assertStringNotContainsString('Other tasks', $table);
        $this->assertStringContainsString('🏃 1', $table);
    }

    public function testRunningAgentWinsOverWaitingSession(): void
    {
        $this->agents->bulk = ['/tmp/x' => [new SessionInfo('a', 'waiting'), new SessionInfo('b', 'running')]];
        $this->store->save($this->finished($this->task('wk-45', State::RequestChanges)));

        $table = $this->listing->tasksTable($this->projects(), $this->store, $this->agents);
        $this->assertStringNotContainsString('💭 Waiting for feedback', $table);
        $this->assertStringNotContainsString('Working', $table);
        $this->assertStringNotContainsString('Other tasks', $table);
        $this->assertStringContainsString('🏃 1 · 💭 1', $table);
    }

    public function testWaitingAgentStillSurfacesWhenNothingRunning(): void
    {
        $this->agents->bulk = ['/tmp/x' => [new SessionInfo('a', 'waiting')]];
        $this->store->save($this->finished($this->task('wk-45', State::InProgress)));

        $table = $this->listing->tasksTable($this->projects(), $this->store, $this->agents);
        $this->assertStringContainsString('💭 Waiting for feedback', $table);
    }

    public function testRenderSlackEmptyRowsWaitingReview(): void
    {
        $this->assertSame('No PRs waiting for review right now 🎉', $this->listing->renderSlack([], State::WaitingReview->value));
    }

    public function testRenderSlackEmptyRowsNeedsTesting(): void
    {
        $this->assertSame('Nothing needs testing right now 🎉', $this->listing->renderSlack([], State::NeedsTesting->value));
    }

    public function testRenderSlackEmptyRowsUnknownStateFallsBack(): void
    {
        $this->assertStringContainsString('🎉', $this->listing->renderSlack([], 'request-changes'));
    }

    private function row(string $project, ?\Pablo\Listing\SlackPr $pr): \Pablo\Listing\SlackItem
    {
        return new \Pablo\Listing\SlackItem($project, 'b', null, null, $pr);
    }

    private function pr(int $number, string $title, string $url): \Pablo\Listing\SlackPr
    {
        return new \Pablo\Listing\SlackPr($number, $title, $url, false);
    }

    public function testRenderSlackGroupsByProjectWithMrkdwnLinks(): void
    {
        $rows = [
            $this->row('wallet-kit', $this->pr(7, 'Fix callbacks', 'https://github.com/acme/wallet-kit/pull/7')),
            $this->row('acme-pim', $this->pr(12, 'Add exports', 'https://github.com/acme/pim/pull/12')),
            $this->row('wallet-kit', $this->pr(9, 'Tidy tests', 'https://github.com/acme/wallet-kit/pull/9')),
        ];
        $out = $this->listing->renderSlack($rows, State::WaitingReview->value);
        $this->assertSame(
            "*wallet-kit*\n• https://github.com/acme/wallet-kit/pull/7 #7 Fix callbacks\n• https://github.com/acme/wallet-kit/pull/9 #9 Tidy tests\n*acme-pim*\n• https://github.com/acme/pim/pull/12 #12 Add exports",
            $out,
        );
    }

    public function testRenderSlackSkipsTasksWithoutPrAndDropsEmptyGroups(): void
    {
        $rows = [
            $this->row('wallet-kit', null),
            $this->row('acme-pim', $this->pr(12, 'Add exports', 'https://github.com/acme/pim/pull/12')),
        ];
        $out = $this->listing->renderSlack($rows, State::NeedsTesting->value);
        $this->assertSame("*acme-pim*\n• https://github.com/acme/pim/pull/12 #12 Add exports", $out);
    }

    public function testRenderSlackUsesIssueKeyAndTitleWhenIssuePresent(): void
    {
        $rows = [
            new \Pablo\Listing\SlackItem(
                'wallet-kit',
                'wk-45',
                new Issue('jira', 'WK-45', 'https://acme.atlassian.net/browse/WK-45', 'Fix callbacks', 'WK'),
                null,
                $this->pr(7, 'PR title ignored', 'https://github.com/acme/wallet-kit/pull/7'),
            ),
        ];
        $out = $this->listing->renderSlack($rows, State::NeedsTesting->value);
        $this->assertSame("*wallet-kit*\n• https://github.com/acme/wallet-kit/pull/7 WK-45 Fix callbacks", $out);
    }

    public function testRenderSlackAllPrsMissingReturnsEmptyMessage(): void
    {
        $rows = [$this->row('wallet-kit', null)];
        $this->assertSame('No PRs waiting for review right now 🎉', $this->listing->renderSlack($rows, State::WaitingReview->value));
    }

    public function testDisplayWidthAscii(): void
    {
        $this->assertSame(5, $this->listing->displayWidth('hello'));
        $this->assertSame(0, $this->listing->displayWidth(''));
    }

    public function testDisplayWidthEmoji(): void
    {
        $this->assertSame(2, $this->listing->displayWidth('🔨'));
        $this->assertSame(2, $this->listing->displayWidth('✅'));
        $this->assertSame(2, $this->listing->displayWidth('💭'));
    }

    public function testDisplayWidthMixed(): void
    {
        $this->assertSame(14, $this->listing->displayWidth('🔨 in-progress'));
        $this->assertSame(11, $this->listing->displayWidth('🏃 1 · 💭 1'));
    }

    public function testPadRightEmojiCell(): void
    {
        $this->assertSame('hello   ', $this->listing->padRight('hello', 8));
        $this->assertSame('🔨 x  ', $this->listing->padRight('🔨 x', 6));
        $this->assertSame('✅ merged #7   ', $this->listing->padRight('✅ merged #7', 15));
    }

    public function testAllTaskRowsAligned(): void
    {
        $this->agents->bulk = ['/tmp/x' => [new SessionInfo('a', 'running'), new SessionInfo('b', 'waiting')]];
        $this->gh->prByBranch = [];
        // prsForBranches derives from prByBranch; set per-branch below
        $this->store->save($this->task('wk-45', State::InProgress, 7, new Issue('github', '45', 'u', 'Fix callbacks', 'WK')));
        $this->store->save($this->task('wk-fix-hooks', State::NeedsTesting, null, null, 'fix flaky webhooks', '/tmp/y'));

        $table = $this->listing->tasksTable($this->projects(), $this->store, $this->agents);
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
