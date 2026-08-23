<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\Report;

use Pablo\Analytics\AnalyticsAggregator;
use Pablo\Analytics\AnalyticsReader;
use Pablo\Command\Report\StatsCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class StatsCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/pablo-stats-'.uniqid();
        mkdir($this->root.'/proj', 0o777, true);
        putenv('PABLO_ANALYTICS_DIR='.$this->root);
    }

    protected function tearDown(): void
    {
        putenv('PABLO_ANALYTICS_DIR');
        exec('rm -rf '.escapeshellarg($this->root));
    }

    /**
     * One complete task lifecycle in project "proj", offsets are seconds
     * relative to now (negative = past).
     */
    private function seedLifecycle(int $baseOffsetS = -400_000): void
    {
        $iso = static fn (int $offset): string => gmdate('Y-m-d\TH:i:sP', time() + $offset);
        $t0 = $baseOffsetS;
        $t1 = $t0 + 600; // in-progress → draft
        $t2 = $t0 + 1200; // agent run started
        $t3 = $t0 + 2400; // agent run finished (1200s)
        $t4 = $t0 + 4800; // draft → ready-to-review (user waited 2400s)
        $t5 = $t0 + 9600; // closed

        $events = [
            ['ts' => $iso($t0), 'type' => 'task_opened', 'project' => 'proj', 'branch' => 'wk-1', 'initial_state' => 'in-progress'],
            // task:start's enterState(InProgress) emits the initial entry too.
            ['ts' => $iso($t0), 'type' => 'state_entered', 'project' => 'proj', 'branch' => 'wk-1', 'from' => 'in-progress', 'to' => 'in-progress'],
            ['ts' => $iso($t1), 'type' => 'state_entered', 'project' => 'proj', 'branch' => 'wk-1', 'from' => 'in-progress', 'to' => 'draft'],
            ['ts' => $iso($t2), 'type' => 'agent_run_started', 'project' => 'proj', 'branch' => 'wk-1', 'run_id' => 'r1', 'agent' => 'task-analyst', 'backend' => 'orca', 'launched_at' => $iso($t2)],
            [
                'ts' => $iso($t3), 'type' => 'agent_run_finished', 'project' => 'proj', 'branch' => 'wk-1',
                'run_id' => 'r1', 'agent' => 'task-analyst', 'backend' => 'orca',
                'started_at' => $iso($t2), 'finished_at' => $iso($t3), 'duration_s' => 1200,
                'input' => 1000, 'output' => 200, 'reasoning' => 50, 'cache_read' => 500,
                'cache_write' => 100, 'tokens_total' => 1850, 'cost' => 0.25,
                'models' => ['anthropic/claude'], 'session_id' => 'ses_a', 'harvest' => 'exact',
            ],
            ['ts' => $iso($t4), 'type' => 'state_entered', 'project' => 'proj', 'branch' => 'wk-1', 'from' => 'draft', 'to' => 'ready-to-review'],
            [
                'ts' => $iso($t5), 'type' => 'task_closed', 'project' => 'proj', 'branch' => 'wk-1',
                'opened_at' => $iso($t0), 'closed_at' => $iso($t5), 'final_state' => 'waiting-review',
                'merged' => true, 'pr_number' => 7, 'agent_runs' => ['task-analyst' => 2], 'duration_s' => 9600,
            ],
        ];
        file_put_contents(
            $this->root.'/proj/'.gmdate('Y-m').'.jsonl',
            implode("\n", array_map(static fn (array $e) => json_encode($e, \JSON_UNESCAPED_SLASHES), $events))."\n",
        );
    }

    private function command(): StatsCommand
    {
        $graph = new \Pablo\Tests\EngineGraph();

        return new StatsCommand(
            new AnalyticsReader(),
            new AnalyticsAggregator($graph->time),
            $graph->listing,
            $graph->time,
            $graph->store,
            $graph->config,
            $graph->agentLaunchers,
            $graph->agents,
        );
    }

    public function testEmptyLogPrintsHint(): void
    {
        $tester = new CommandTester($this->command());
        $tester->execute([]);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('no analytics events recorded yet', $tester->getDisplay());
        $this->assertStringContainsString($this->root, $tester->getDisplay());
    }

    public function testTableRendersAllThreeSections(): void
    {
        $this->seedLifecycle();
        $tester = new CommandTester($this->command());
        $tester->execute([]);

        $display = $tester->getDisplay();
        // Tasks: one opened, one closed, merged, life 9600s = 2h40m.
        $this->assertStringContainsString('Tasks', $display);
        $this->assertStringContainsString('proj', $display);
        $this->assertStringContainsString('2h40m', $display);
        $this->assertStringContainsString('100%', $display);
        // Agents: cache hit = 500 / (1000+500+100) = 31.3%, avg run 20m, user wait 40m.
        $this->assertStringContainsString('Agent runs', $display);
        $this->assertStringContainsString('task-analyst', $display);
        $this->assertStringContainsString('31.3%', $display);
        $this->assertStringContainsString('20m', $display);
        $this->assertStringContainsString('40m', $display);
        $this->assertStringContainsString('Hit%', $display);
        // States: in-progress dwell 10m, draft dwell 1h.
        $this->assertStringContainsString('States', $display);
        $this->assertStringContainsString('in-progress', $display);
    }

    public function testJsonFormatIsDecodableAndAccurate(): void
    {
        $this->seedLifecycle();
        $tester = new CommandTester($this->command());
        $tester->execute(['--format' => 'json']);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($tester->getDisplay(), true);
        $this->assertSame(1, $payload['tasks']['proj']['opened']);
        $this->assertSame(1, $payload['tasks']['proj']['closed']);
        $this->assertSame(9600, $payload['tasks']['proj']['avg_life_s']);
        $agent = $payload['agents']['task-analyst'];
        $this->assertSame(1, $agent['runs']);
        $this->assertSame(1, $agent['starts']);
        $this->assertSame(1200, $agent['avg_run_s']);
        $this->assertSame(1600, $agent['context_tokens']);
        $this->assertEqualsWithDelta(31.3, $agent['cache_hit_pct'], 0.05);
        $this->assertEqualsWithDelta(0.25, $agent['cost'], 0.001);
        $this->assertSame(2400, $agent['avg_user_wait_s']);
        $this->assertSame(600, $payload['states']['in-progress']['avg_dwell_s']);
        // draft: entered at t1, left at t4 → 4200s
        $this->assertSame(4200, $payload['states']['draft']['avg_dwell_s']);
    }

    public function testProjectFilterRestrictsEvents(): void
    {
        $this->seedLifecycle();
        mkdir($this->root.'/other', 0o777, true);
        file_put_contents($this->root.'/other/'.gmdate('Y-m').'.jsonl', json_encode([
            'ts' => gmdate('Y-m-d\TH:i:sP', time() - 100), 'type' => 'task_opened',
            'project' => 'other', 'branch' => 'b', 'initial_state' => 'in-progress',
        ]).\PHP_EOL);

        $tester = new CommandTester($this->command());
        $tester->execute(['--format' => 'json', '--project' => 'other']);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($tester->getDisplay(), true);
        $this->assertArrayNotHasKey('proj', $payload['tasks']);
        $this->assertSame(1, $payload['tasks']['other']['opened']);
    }

    public function testDaysWindowExcludesOldEvents(): void
    {
        $this->seedLifecycle(-40 * 86_400); // whole lifecycle 40 days ago
        $tester = new CommandTester($this->command());
        $tester->execute(['--days' => '1']);

        $this->assertStringContainsString('no analytics events recorded yet', $tester->getDisplay());
    }

    public function testAgentFilterLimitsAgentsTable(): void
    {
        $this->seedLifecycle();
        file_put_contents(
            $this->root.'/proj/'.gmdate('Y-m').'.jsonl',
            json_encode([
                'ts' => gmdate('Y-m-d\TH:i:sP'), 'type' => 'agent_run_finished', 'project' => 'proj',
                'branch' => 'wk-1', 'run_id' => 'r2', 'agent' => 'ci-analyst', 'backend' => 'orca',
                'duration_s' => 30, 'input' => null, 'output' => null, 'reasoning' => null,
                'cache_read' => null, 'cache_write' => null, 'tokens_total' => null,
                'cost' => null, 'models' => [], 'session_id' => null, 'harvest' => 'none',
            ], \JSON_UNESCAPED_SLASHES).\PHP_EOL,
            \FILE_APPEND,
        );

        $tester = new CommandTester($this->command());
        $tester->execute(['--format' => 'json', '--agent' => 'ci-analyst']);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($tester->getDisplay(), true);
        $this->assertSame(['ci-analyst'], array_keys($payload['agents']));
        $this->assertSame(1, $payload['agents']['ci-analyst']['runs']);
        $this->assertSame(0, $payload['agents']['ci-analyst']['context_tokens']);
        $this->assertSame(-1, $payload['agents']['ci-analyst']['avg_user_wait_s']);
        // Tasks/states remain unfiltered.
        $this->assertSame(1, $payload['tasks']['proj']['closed']);
    }
}
