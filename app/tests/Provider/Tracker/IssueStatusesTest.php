<?php

declare(strict_types=1);

namespace Pablo\Tests\Provider\Tracker;

use Pablo\Config\ProjectConfig;
use Pablo\Provider\Tracker\IssueStatuses;
use Pablo\Support\ProbeResult;
use Pablo\Support\ProcessRunnerInterface;
use PHPUnit\Framework\TestCase;

final class IssueStatusesTest extends TestCase
{
    public function testNoIssuesRunsNothing(): void
    {
        $runner = $this->runner([]);

        $this->assertSame([], IssueStatuses::fetch($runner, [], self::viewCommand(...), self::statusOf(...)));
        $this->assertSame([], $runner->batches);
    }

    public function testOneParallelBatchUnreadableOutputMapsToQuestionMark(): void
    {
        $cfg = (new \ReflectionClass(ProjectConfig::class))->newInstanceWithoutConstructor();
        $runner = $this->runner(['{"s":"Open"}', 'not json', '{"other":1}']);

        $statuses = IssueStatuses::fetch(
            $runner,
            ['A-1' => $cfg, 'A-2' => $cfg, 'A-3' => $cfg],
            self::viewCommand(...),
            self::statusOf(...),
            12.0,
        );

        $this->assertSame(['A-1' => 'Open', 'A-2' => '?', 'A-3' => '?'], $statuses);
        $this->assertSame([[[['view', 'A-1'], ['view', 'A-2'], ['view', 'A-3']], false, 12.0]], $runner->batches);
    }

    /** @return list<string> */
    private static function viewCommand(string $key, ProjectConfig $cfg): array
    {
        return ['view', $key];
    }

    /** @param array<string, mixed> $data */
    private static function statusOf(array $data): string
    {
        if (!isset($data['s'])) {
            throw new \RuntimeException('no status');
        }

        return (string) $data['s'];
    }

    /** @param list<string> $outputs */
    private function runner(array $outputs): RecordingRunner
    {
        return new RecordingRunner($outputs);
    }
}

/** Records each runParallel() batch and answers it with scripted outputs. */
final class RecordingRunner implements ProcessRunnerInterface
{
    /** @var list<array{list<list<string>>, bool, ?float}> */
    public array $batches = [];

    /** @param list<string> $outputs */
    public function __construct(private readonly array $outputs)
    {
    }

    public function run(array $argv, bool $check = true, ?float $timeout = null, ?string $cwd = null): string
    {
        throw new \LogicException('IssueStatuses must only run in parallel');
    }

    public function runParallel(array $commandGroups, bool $check = true, ?float $timeout = null): array
    {
        $this->batches[] = [$commandGroups, $check, $timeout];

        return \array_slice($this->outputs, 0, \count($commandGroups));
    }

    public function probe(array $argv, ?float $timeout = null, ?string $cwd = null): ProbeResult
    {
        throw new \LogicException('IssueStatuses must only run in parallel');
    }
}
