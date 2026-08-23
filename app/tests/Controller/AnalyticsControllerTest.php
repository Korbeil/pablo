<?php

declare(strict_types=1);

namespace Pablo\Tests\Controller;

use Pablo\Tests\RestoresErrorHandlers;
use Pablo\Tests\UsesGlobalConfig;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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
        $this->tmp = sys_get_temp_dir().'/pablo-web-analytics-'.uniqid();
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
    private function seedLifecycle(int $baseOffsetS = -2 * 86_400): void
    {
        $iso = static fn (int $offset): string => gmdate('Y-m-d\TH:i:sP', time() + $baseOffsetS + $offset);
        $events = [
            ['ts' => $iso(0), 'type' => 'task_opened', 'project' => 'proj', 'branch' => 'wk-1', 'initial_state' => 'in-progress'],
            ['ts' => $iso(600), 'type' => 'state_entered', 'project' => 'proj', 'branch' => 'wk-1', 'from' => 'in-progress', 'to' => 'draft'],
            ['ts' => $iso(1200), 'type' => 'agent_run_started', 'project' => 'proj', 'branch' => 'wk-1', 'run_id' => 'r1', 'agent' => 'task-analyst', 'backend' => 'orca', 'launched_at' => $iso(1200)],
            [
                'ts' => $iso(2400), 'type' => 'agent_run_finished', 'project' => 'proj', 'branch' => 'wk-1',
                'run_id' => 'r1', 'agent' => 'task-analyst', 'backend' => 'orca',
                'started_at' => $iso(1200), 'finished_at' => $iso(2400), 'duration_s' => 1200,
                'input' => 1000, 'output' => 200, 'reasoning' => 50, 'cache_read' => 500,
                'cache_write' => 100, 'tokens_total' => 1850, 'cost' => 0.25,
                'models' => ['anthropic/claude'], 'session_id' => 'ses_a', 'harvest' => 'exact',
            ],
            ['ts' => $iso(4800), 'type' => 'state_entered', 'project' => 'proj', 'branch' => 'wk-1', 'from' => 'draft', 'to' => 'ready-to-review'],
            ['ts' => $iso(9600), 'type' => 'task_closed', 'project' => 'proj', 'branch' => 'wk-1', 'final_state' => 'waiting-review', 'merged' => true],
        ];
        file_put_contents(
            $this->tmp.'/analytics/proj/'.gmdate('Y-m').'.jsonl',
            implode("\n", array_map(static fn (array $e) => json_encode($e, \JSON_UNESCAPED_SLASHES), $events))."\n",
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

    public function testRendersKpisAndAllSixCharts(): void
    {
        $this->seedLifecycle();

        $crawler = $this->browser()->request('GET', '/analytics');

        $this->assertResponseIsSuccessful();
        $text = $crawler->text();
        foreach (['Tasks opened vs closed', 'Tokens per agent', 'Cache hit %', 'Avg runtime per agent', 'Cost per agent', 'State dwell'] as $heading) {
            $this->assertStringContainsString($heading, $text);
        }
        // One canvas per chart; each carries its rendered config in the
        // stimulus view value.
        $canvases = $crawler->filter('canvas');
        $this->assertSame(6, $canvases->count());
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

    public function testDaysFilterExcludesOldEvents(): void
    {
        $this->seedLifecycle(-40 * 86_400); // whole lifecycle 40 days ago

        $client = $this->browser();
        $empty = $client->request('GET', '/analytics?days=7');
        $this->assertStringContainsString('No analytics events recorded yet', $empty->text());

        $all = $client->request('GET', '/analytics?days=all');
        $this->assertStringContainsString('Tasks opened vs closed', $all->text());
    }

    public function testInvalidDaysFallsBackToDefault(): void
    {
        $this->seedLifecycle();
        $crawler = $this->browser()->request('GET', '/analytics?days=bogus');
        $this->assertResponseIsSuccessful();
        $active = $crawler->filter('.pablo-tabs li.is-active');
        $this->assertSame(1, $active->count());
        $this->assertStringContainsString('30d', $active->text());
    }
}
