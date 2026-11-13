<?php

declare(strict_types=1);

namespace Pablo\Tests\Controller;

use Pablo\Support\ProcessRunner;
use Pablo\Tests\FakeProcessRunner;
use Pablo\Tests\RestoresErrorHandlers;
use Pablo\Tests\TempDir;
use Pablo\Tests\UsesGlobalConfig;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * End-to-end smoke test for the /analytics page.
 *
 * Same fixture-tree approach as DashboardControllerTest, plus a sandboxed
 * PABLO_ANALYTICS_DIR seeded with a full task lifecycle so no test ever
 * reads — or writes — the developer's real analytics log.
 */
final class AnalyticsControllerTest extends WebTestCase
{
    use RestoresErrorHandlers;
    use UsesGlobalConfig;

    private string $tmp;

    protected function setUp(): void
    {
        $this->snapshotErrorHandlers();
        $this->tmp = TempDir::create('web-analytics');
        mkdir($this->tmp.'/projects', 0o777, true);
        mkdir($this->tmp.'/stamps', 0o777, true);
        mkdir($this->tmp.'/logs', 0o777, true);
        mkdir($this->tmp.'/analytics/proj', 0o777, true);

        putenv('PABLO_STATE_DIR='.$this->tmp.'/state');
        putenv('PABLO_PROJECTS_DIR='.$this->tmp.'/projects');
        putenv('PABLO_STAMPS_DIR='.$this->tmp.'/stamps');
        putenv('PABLO_LOGS_DIR='.$this->tmp.'/logs');
        putenv('PABLO_ANALYTICS_DIR='.$this->tmp.'/analytics');

        $this->writeGlobalConfig(<<<'YAML'
            sync:
                strategy: rebase
                auto_apply: false
                interval_minutes: 720
            state_polling:
                interval_minutes: 10
            review:
                bot_whitelist: []
            ci:
                ignore_checks: []
            default_model: openrouter/test/model
            pr_description_locale: en
            YAML);

        file_put_contents($this->tmp.'/projects/wallet-kit.yaml', <<<'YAML'
            name: wallet-kit
            type: work
            repo:
                path: /tmp/nowhere
                primary_branch: main
            issue_tracker:
                provider: github
                identity: octocat
                project_key: WK
            YAML);

        file_put_contents($this->tmp.'/projects/bookkeeper.yaml', <<<'YAML'
            name: bookkeeper
            type: open-source
            repo:
                path: /tmp/nowhere-bk
                primary_branch: main
            issue_tracker:
                provider: github
                identity: octocat
                project_key: BK
            YAML);
    }

    protected function tearDown(): void
    {
        foreach (['PABLO_STATE_DIR', 'PABLO_PROJECTS_DIR', 'PABLO_STAMPS_DIR', 'PABLO_LOGS_DIR', 'PABLO_ANALYTICS_DIR'] as $var) {
            putenv($var);
        }
        $this->unsetGlobalConfig();
        exec('rm -rf '.escapeshellarg($this->tmp));
        parent::tearDown();
        $this->restoreErrorHandlers();
    }

    /** Seeds one complete lifecycle 2 days ago (within ?days=7 and =30). */
    private function seedLifecycle(int $baseOffsetS = -2 * 86_400, string $project = 'proj', string $agent = 'task-analyst', string $branch = 'wk-1', string $model = 'anthropic/claude', int $input = 1000): void
    {
        $iso = static fn (int $offset): string => gmdate('Y-m-d\TH:i:sP', time() + $baseOffsetS + $offset);
        $events = [['ts' => $iso(0), 'type' => 'task_opened', 'project' => $project, 'branch' => $branch, 'initial_state' => 'in-progress'],
            ['ts' => $iso(600), 'type' => 'state_entered', 'project' => $project, 'branch' => $branch, 'from' => 'in-progress', 'to' => 'draft'],
            ['ts' => $iso(1200), 'type' => 'agent_run_started', 'project' => $project, 'branch' => $branch, 'run_id' => 'r1', 'agent' => $agent, 'backend' => 'orca', 'launched_at' => $iso(1200)],
            [
                'ts' => $iso(2400), 'type' => 'agent_run_finished', 'project' => $project, 'branch' => $branch,
                'run_id' => 'r1', 'agent' => $agent, 'backend' => 'orca',
                'started_at' => $iso(1200), 'finished_at' => $iso(2400), 'duration_s' => 1200,
                'input' => $input, 'output' => 200, 'reasoning' => 50, 'cache_read' => 500,
                'cache_write' => 100, 'tokens_total' => 1850, 'cost' => 0.25,
                'models' => [$model], 'session_id' => 'ses_a', 'harvest' => 'exact',
            ],
            ['ts' => $iso(4800), 'type' => 'state_entered', 'project' => $project, 'branch' => $branch, 'from' => 'draft', 'to' => 'ready-to-review'],
            ['ts' => $iso(9600), 'type' => 'task_closed', 'project' => $project, 'branch' => $branch, 'final_state' => 'waiting-review', 'merged' => true],
        ];
        $dir = $this->tmp.'/analytics/'.($project ?: 'proj');
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        file_put_contents(
            $dir.'/'.gmdate('Y-m').'.jsonl',
            implode("\n", array_map(static fn (array $e) => json_encode($e, \JSON_UNESCAPED_SLASHES), $events))."\n",
            \FILE_APPEND,
        );
    }

    private function browser(): KernelBrowser
    {
        return static::createClient();
    }

    public function testEmptyStateIsFriendly(): void
    {
        $crawler = $this->browser()->request('GET', '/analytics');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('No analytics events recorded yet', $crawler->text());
    }

    public function testRendersKpisAndAllCharts(): void
    {
        $this->seedLifecycle();

        $crawler = $this->browser()->request('GET', '/analytics');

        $this->assertResponseIsSuccessful();
        $text = $crawler->text();
        foreach (['Tasks opened vs closed', 'Agent runs by agent', 'Top tasks by tokens', 'Tokens & cost per model', 'Tokens per agent', 'Cache hit %', 'Avg runtime per agent', 'Cost per agent', 'State dwell'] as $heading) {
            $this->assertStringContainsString($heading, $text);
        }
        // One canvas per chart; each carries its rendered config in the
        // stimulus view value.
        $canvases = $crawler->filter('canvas');
        // Six agent/task charts + the breakdown and per-model charts.
        $this->assertSame(8, $canvases->count());
        $rendered = '';
        foreach ($canvases as $node) {
            $rendered .= $node->C14N();
        }
        $this->assertStringContainsString('symfony--ux-chartjs--chart', $rendered);
        $this->assertStringContainsString('task-analyst', $rendered);
    }

    public function testKpiTilesShowLifecycleNumbers(): void
    {
        $this->seedLifecycle();

        $crawler = $this->browser()->request('GET', '/analytics');

        $values = $crawler->filter('.pablo-kpi')->each(static fn ($n) => trim($n->text()));
        $joined = implode(' | ', $values);
        $this->assertStringContainsString('Tasks opened', $joined);
        $this->assertStringContainsString('1', $joined);
        $this->assertStringContainsString('Merged', $joined);
        $this->assertStringContainsString('100%', $joined);
    }

    /** The tasks board must link here, and this page back to the board. */
    public function testNavigationLinksBothWays(): void
    {
        $client = $this->browser();
        $home = $client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $linkFromBoard = $home->filter('a[href="/analytics"]');
        $this->assertSame(1, $linkFromBoard->count());

        $this->seedLifecycle();
        $analytics = $client->request('GET', '/analytics');
        $this->assertResponseIsSuccessful();
        $backLinks = $analytics->filter('a[href="/"]');
        $this->assertGreaterThanOrEqual(1, $backLinks->count());
    }

    /** The theme switcher must be mounted here too, with its three state glyphs. */
    public function testThemeToggleIsMounted(): void
    {
        $crawler = $this->browser()->request('GET', '/analytics');

        $toggle = $crawler->filter('[data-controller="theme"]');
        $this->assertSame(1, $toggle->count());
        $this->assertSame('theme#toggle', $toggle->attr('data-action'));
        // The bare token powers the CSS hide-everything base rule; without it
        // the glyphs are unrestrained and all three show at once.
        $this->assertSame(3, $crawler->filter('.pablo-theme-icon')->count());
        $this->assertSame(1, $crawler->filter('.pablo-theme-icon-light')->count());
        $this->assertSame(1, $crawler->filter('.pablo-theme-icon-dark')->count());
        $this->assertSame(1, $crawler->filter('.pablo-theme-icon-system')->count());
    }

    public function testDaysFilterExcludesOldEvents(): void
    {
        $this->seedLifecycle(-40 * 86_400); // whole lifecycle 40 days ago

        $client = $this->browser();
        $empty = $client->request('GET', '/analytics?days=7');
        // Events exist in the log but none survive the window.
        $this->assertStringContainsString('No analytics events match this filter', $empty->text());

        $all = $client->request('GET', '/analytics?days=all');
        $this->assertStringContainsString('Tasks opened vs closed', $all->text());
    }

    public function testInvalidDaysFallsBackToDefault(): void
    {
        $this->seedLifecycle();
        $crawler = $this->browser()->request('GET', '/analytics?days=bogus');
        $this->assertResponseIsSuccessful();
        $active = $crawler->filter('[aria-label="Time range"] li.is-active');
        $this->assertSame(1, $active->count());
        $this->assertStringContainsString('30d', $active->text());
    }

    public function testTypeFilterIsolatesProjectsByConfigType(): void
    {
        // wallet-kit is configured as `work`, bookkeeper as `open-source`.
        $this->seedLifecycle(project: 'wallet-kit');
        $this->seedLifecycle(project: 'bookkeeper', agent: 'pr-feedback');

        $client = $this->browser();

        $all = $client->request('GET', '/analytics?days=all');
        $allViews = '';
        foreach ($all->filter('canvas') as $node) {
            /** @var \DOMElement $node */
            $allViews .= (string) $node->getAttribute('data-symfony--ux-chartjs--chart-view-value');
        }
        $this->assertStringContainsString('task-analyst', $allViews);
        $this->assertStringContainsString('pr-feedback', $allViews);

        $work = $client->request('GET', '/analytics?days=all&type=work');
        $rendered = '';
        foreach ($work->filter('canvas') as $node) {
            $rendered .= $node->C14N();
        }
        $this->assertStringContainsString('task-analyst', $rendered);
        $this->assertStringNotContainsString('pr-feedback', $rendered);
        $active = $work->filter('.pablo-tabs li.is-active');
        $this->assertSame(3, $active->count(), 'one active type, days and breakdown tab');

        $openSource = $client->request('GET', '/analytics?days=all&type=open-source');
        $rendered = '';
        foreach ($openSource->filter('canvas') as $node) {
            $rendered .= $node->C14N();
        }
        $this->assertStringNotContainsString('task-analyst', $rendered);
    }

    public function testOrphanEventsStayOnAllButNeverMatchAType(): void
    {
        // 'gone' has no project config anymore: its history survives under
        // All but is excluded from every specific type tab.
        $this->seedLifecycle(project: 'gone');
        $client = $this->browser();

        $all = $client->request('GET', '/analytics?days=all');
        $allViews = '';
        foreach ($all->filter('canvas') as $node) {
            /** @var \DOMElement $node */
            $allViews .= (string) $node->getAttribute('data-symfony--ux-chartjs--chart-view-value');
        }
        $this->assertStringContainsString('task-analyst', $allViews);

        $filtered = $client->request('GET', '/analytics?days=all&type=personal');
        $this->assertStringContainsString('No analytics events match this filter', $filtered->text());
    }

    /** Days and type filters combine; switching one preserves the other. */
    public function testTypeTabsPreserveDaysParameter(): void
    {
        $this->seedLifecycle(project: 'wallet-kit');
        $crawler = $this->browser()->request('GET', '/analytics?days=7&type=work');

        $typeTabs = $crawler->filter('[aria-label="Filter by project type"] a');
        $this->assertSame(4, $typeTabs->count());
        $hrefs = $typeTabs->each(static fn (Crawler $node): string => (string) $node->attr('href'));
        foreach ($hrefs as $href) {
            $this->assertStringContainsString('days=7', $href);
        }
        $active = $crawler->filter('[aria-label="Filter by project type"] li.is-active');
        $this->assertSame(1, $active->count());
        $this->assertStringContainsString('Work', $active->text());
    }

    /** @return list<string> the breakdown table's row headers */
    private static function breakdownGroups(Crawler $crawler): array
    {
        return $crawler->filter('.pablo-breakdown-table tbody th')->each(static fn (Crawler $n): string => trim($n->text()));
    }

    private function noSubprocess(KernelBrowser $client): FakeProcessRunner
    {
        $runner = new FakeProcessRunner();
        static::getContainer()->set(ProcessRunner::class, $runner);

        return $runner;
    }

    public function testBreakdownParameterSwitchesTheGrouping(): void
    {
        $this->seedLifecycle(project: 'wallet-kit', model: 'anthropic/claude');
        $this->seedLifecycle(project: 'bookkeeper', agent: 'pr-feedback', model: 'openai/gpt');
        $client = $this->browser();
        $runner = $this->noSubprocess($client);

        $byAgent = $client->request('GET', '/analytics?days=all');
        $this->assertResponseIsSuccessful();
        $this->assertEqualsCanonicalizing(['task-analyst', 'pr-feedback'], self::breakdownGroups($byAgent));
        $this->assertStringContainsString('Agent', $byAgent->filter('[aria-label="Break agent runs down by"] li.is-active')->text());

        $byProject = $client->request('GET', '/analytics?days=all&by=project');
        $this->assertEqualsCanonicalizing(['wallet-kit', 'bookkeeper'], self::breakdownGroups($byProject));
        // Same columns as `pablo stats --by`.
        $headers = $byProject->filter('.pablo-breakdown-table thead th')->each(static fn (Crawler $n): string => trim($n->text()));
        $this->assertSame(['Project', 'Runs', 'Time', 'Avg time', 'In', 'Out', 'Cache R', 'Cache W', 'Total', 'Cost'], $headers);

        $byModel = $client->request('GET', '/analytics?days=all&by=model');
        $this->assertEqualsCanonicalizing(['anthropic/claude', 'openai/gpt'], self::breakdownGroups($byModel));

        $byTask = $client->request('GET', '/analytics?days=all&by=task');
        $this->assertEqualsCanonicalizing(['wallet-kit/wk-1', 'bookkeeper/wk-1'], self::breakdownGroups($byTask));
        $this->assertSame(1, $byTask->filter('.pablo-breakdown-table a[href="/analytics/task/wallet-kit/wk-1"]')->count());

        $this->assertSame([], $runner->calls, 'the analytics page never runs a subprocess');
    }

    public function testInvalidBreakdownFallsBackToAgent(): void
    {
        $this->seedLifecycle();
        $crawler = $this->browser()->request('GET', '/analytics?by=bogus');

        $this->assertResponseIsSuccessful();
        $this->assertSame(['task-analyst'], self::breakdownGroups($crawler));
        $active = $crawler->filter('[aria-label="Break agent runs down by"] li.is-active');
        $this->assertSame(1, $active->count());
        $this->assertStringContainsString('Agent', $active->text());
    }

    /** by= survives the days/type tabs, and the by tabs keep days/type. */
    public function testBreakdownAndFiltersPreserveEachOther(): void
    {
        $this->seedLifecycle(project: 'wallet-kit');
        $crawler = $this->browser()->request('GET', '/analytics?days=7&type=work&by=model');

        foreach (['Filter by project type', 'Time range'] as $nav) {
            foreach ($crawler->filter("[aria-label=\"{$nav}\"] a")->each(static fn (Crawler $n): string => (string) $n->attr('href')) as $href) {
                $this->assertStringContainsString('by=model', $href);
            }
        }
        foreach ($crawler->filter('[aria-label="Time range"] a')->each(static fn (Crawler $n): string => (string) $n->attr('href')) as $href) {
            $this->assertStringContainsString('type=work', $href);
        }
        $byLinks = $crawler->filter('[aria-label="Break agent runs down by"] a')->each(static fn (Crawler $n): string => (string) $n->attr('href'));
        $this->assertCount(4, $byLinks);
        foreach ($byLinks as $href) {
            $this->assertStringContainsString('days=7', $href);
            $this->assertStringContainsString('type=work', $href);
        }
    }

    public function testTopTasksLinkToTheirHistory(): void
    {
        $this->seedLifecycle(project: 'wallet-kit', input: 5000);
        $this->seedLifecycle(project: 'wallet-kit', branch: 'feat/slashed', input: 100);
        $crawler = $this->browser()->request('GET', '/analytics?days=all');

        $links = $crawler->filter('.pablo-top-tasks tbody a')->each(static fn (Crawler $n): string => (string) $n->attr('href'));
        // Most tokens first; a branch's slash stays a path separator.
        $this->assertSame(['/analytics/task/wallet-kit/wk-1', '/analytics/task/wallet-kit/feat/slashed'], $links);
    }

    public function testEmptyWindowShowsPerSectionEmptyStates(): void
    {
        // Only lifecycle events, no finished run: the breakdown, top tasks and
        // model sections each say so instead of drawing an empty chart.
        $dir = $this->tmp.'/analytics/proj';
        file_put_contents($dir.'/'.gmdate('Y-m').'.jsonl', json_encode(['ts' => gmdate('Y-m-d\TH:i:sP'), 'type' => 'task_opened', 'project' => 'proj', 'branch' => 'b', 'initial_state' => 'in-progress'])."\n");
        $crawler = $this->browser()->request('GET', '/analytics');

        $this->assertResponseIsSuccessful();
        $this->assertSame(3, substr_count($crawler->text(), 'No finished agent runs in this window.'));
    }

    public function testTaskHistoryRendersTheTimeline(): void
    {
        $this->seedLifecycle(project: 'wallet-kit', branch: 'feat/slashed');
        $client = $this->browser();
        $runner = $this->noSubprocess($client);

        $crawler = $client->request('GET', '/analytics/task/wallet-kit/feat/slashed');

        $this->assertResponseIsSuccessful();
        $rows = $crawler->filter('.pablo-history-table tbody tr')->each(static fn (Crawler $n): string => preg_replace('/\s+/', ' ', trim($n->text())) ?? '');
        $this->assertCount(5, $rows);
        $this->assertStringContainsString('opened', $rows[0]);
        $this->assertStringContainsString('in-progress → draft', $rows[1]);
        $this->assertStringContainsString('task-analyst (anthropic/claude)', $rows[2]);
        $this->assertStringContainsString('20m', $rows[2]);
        $this->assertStringContainsString('0.25', $rows[2]);
        $this->assertStringContainsString('closed', $rows[4]);
        $this->assertStringContainsString('merged', $rows[4]);
        $kpis = implode(' | ', $crawler->filter('.pablo-kpi')->each(static fn (Crawler $n): string => trim($n->text())));
        $this->assertStringContainsString('1 Agent runs', $kpis);
        $this->assertSame([], $runner->calls);
    }

    public function testTaskHistoryOfAnUnconfiguredProjectWithALogStillWorks(): void
    {
        $this->seedLifecycle(project: 'gone');
        $this->browser()->request('GET', '/analytics/task/gone/wk-1');

        $this->assertResponseIsSuccessful();
    }

    public function testTaskHistory404sForUnknownTaskOrProject(): void
    {
        $this->seedLifecycle(project: 'wallet-kit');
        $client = $this->browser();

        $client->request('GET', '/analytics/task/wallet-kit/nope');
        $this->assertResponseStatusCodeSame(404);
        $client->request('GET', '/analytics/task/nowhere/wk-1');
        $this->assertResponseStatusCodeSame(404);
        $client->request('GET', '/analytics/task/..%2F..%2Fetc/wk-1');
        $this->assertResponseStatusCodeSame(404);
    }
}
