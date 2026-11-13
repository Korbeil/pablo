<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\Report;

use Pablo\Analytics\AnalyticsAggregator;
use Pablo\Analytics\AnalyticsReader;
use Pablo\Command\Report\TaskHistoryCommand;
use Pablo\Tests\EngineGraph;
use Pablo\Tests\TempDir;
use Pablo\Tests\UsesGlobalConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class TaskHistoryCommandTest extends TestCase
{
    use UsesGlobalConfig;

    private string $root;

    protected function setUp(): void
    {
        $this->writeGlobalConfig("default_model: openrouter/test/model\n");
        $this->root = TempDir::create('history');
        putenv('PABLO_ANALYTICS_DIR='.$this->root);
        $this->seed('proj', 'wk-1', '2026-03');
    }

    protected function tearDown(): void
    {
        putenv('PABLO_ANALYTICS_DIR');
        $this->unsetGlobalConfig();
        exec('rm -rf '.escapeshellarg($this->root));
    }

    /** One task lifecycle spread over two month files. */
    private function seed(string $project, string $branch, string $month): void
    {
        $events = [
            '2026-02' => [
                ['ts' => '2026-02-28T23:00:00+00:00', 'type' => 'task_opened', 'initial_state' => 'in-progress'],
                ['ts' => '2026-02-28T23:00:00+00:00', 'type' => 'state_entered', 'from' => 'in-progress', 'to' => 'in-progress'],
            ],
            $month => [
                ['ts' => '2026-03-01T01:00:00+00:00', 'type' => 'state_entered', 'from' => 'in-progress', 'to' => 'draft'],
                ['ts' => '2026-03-01T01:05:00+00:00', 'type' => 'agent_run_started', 'agent' => 'ci-analyst', 'prompt_fingerprint' => 'abc', 'prompt_len' => 12],
                [
                    'ts' => '2026-03-01T01:25:00+00:00', 'type' => 'agent_run_finished', 'agent' => 'ci-analyst', 'backend' => 'orca',
                    'started_at' => '2026-03-01T01:05:00+00:00', 'finished_at' => '2026-03-01T01:25:00+00:00', 'duration_s' => 1200,
                    'input' => 12_000, 'output' => 800, 'reasoning' => 200, 'cache_read' => 40_000, 'cache_write' => 3000,
                    'cost' => 0.42, 'models' => ['anthropic/claude'], 'harvest' => 'exact',
                ],
                ['ts' => '2026-03-01T03:00:00+00:00', 'type' => 'state_entered', 'from' => 'draft', 'to' => 'ready-to-review'],
            ],
        ];
        foreach ($events as $file => $list) {
            if (!is_dir($this->root.'/'.$project)) {
                mkdir($this->root.'/'.$project, 0o777, true);
            }
            foreach ($list as $event) {
                file_put_contents(
                    "{$this->root}/{$project}/{$file}.jsonl",
                    json_encode($event + ['project' => $project, 'branch' => $branch], \JSON_UNESCAPED_SLASHES)."\n",
                    \FILE_APPEND,
                );
            }
        }
    }

    private function tester(): CommandTester
    {
        $graph = new EngineGraph();

        return new CommandTester(new TaskHistoryCommand(
            new AnalyticsReader(),
            new AnalyticsAggregator($graph->time),
            $graph->listing,
            $graph->store,
            $graph->config,
            $graph->agentLaunchers,
            $graph->agents,
        ));
    }

    public function testTableShowsTransitionsRunsAndTotals(): void
    {
        $tester = $this->tester();
        $tester->execute(['branch' => 'wk-1']);

        $this->assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('proj/wk-1', $display);
        $this->assertStringContainsString('in-progress → draft', $display);
        $this->assertStringContainsString('2h', $display); // in-progress dwell
        $this->assertStringContainsString('ci-analyst (anthropic/claude)', $display);
        $this->assertStringContainsString('12k', $display);
        $this->assertStringContainsString('40k/3.0k', $display);
        $this->assertStringContainsString('0.42', $display);
        $this->assertStringContainsString('Agent runs: 1', $display);
    }

    public function testJsonTimeline(): void
    {
        $tester = $this->tester();
        $tester->execute(['branch' => 'wk-1', '--project' => 'proj', '--format' => 'json']);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($tester->getDisplay(), true);
        $this->assertSame('proj', $payload['project']);
        $this->assertSame(['task_opened', 'state_entered', 'state_entered', 'agent_run_finished', 'state_entered'], array_column($payload['timeline'], 'event'));
        $this->assertSame(7200, $payload['timeline'][1]['dwell_s']);
        $this->assertSame(7200, $payload['timeline'][2]['dwell_s']);
        $this->assertSame(-1, $payload['timeline'][4]['dwell_s']);
        $this->assertSame(1000, $payload['timeline'][3]['tokens_out']);
        $this->assertSame(['runs' => 1, 'duration_s' => 1200, 'tokens_in' => 12_000, 'tokens_out' => 1000, 'cache_read' => 40_000, 'cache_write' => 3000, 'cost' => 0.42], $payload['totals']);
        $this->assertStringNotContainsString('abc', $tester->getDisplay()); // no prompt material is surfaced
    }

    public function testUnknownBranchFails(): void
    {
        $tester = $this->tester();
        $tester->execute(['branch' => 'nope']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString("no analytics recorded for branch 'nope'", $tester->getDisplay());
    }

    public function testAmbiguousBranchNeedsAProject(): void
    {
        $this->seed('other', 'wk-1', '2026-04');
        $tester = $this->tester();
        $tester->execute(['branch' => 'wk-1']);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('several projects (other, proj); pass --project', $tester->getDisplay());

        $tester->execute(['branch' => 'wk-1', '--project' => 'other', '--format' => 'json']);
        $this->assertSame(0, $tester->getStatusCode());
        /** @var array<string, mixed> $payload */
        $payload = json_decode($tester->getDisplay(), true);
        $this->assertSame('other', $payload['project']);
    }
}
