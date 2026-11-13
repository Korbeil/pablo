<?php

declare(strict_types=1);

namespace Pablo\Command\Report;

use Pablo\Analytics\AnalyticsAggregator;
use Pablo\Analytics\AnalyticsReader;
use Pablo\Analytics\JsonlAnalytics;
use Pablo\Command\Command;
use Pablo\Command\FormatsOutput;
use Pablo\Listing\Listing;
use Pablo\Support\Format;
use Pablo\Support\Numbers;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One task's timeline out of the analytics log: state transitions with how
 * long each lasted, and every finished agent run with its tokens and cost.
 * Works for closed tasks too — it only reads ~/.pablo/analytics/.
 */
#[AsCommand(name: 'task:history', description: 'one task\'s timeline from analytics: state transitions and agent runs')]
final class TaskHistoryCommand extends Command
{
    use FormatsOutput;

    public function __construct(
        private readonly AnalyticsReader $reader,
        private readonly AnalyticsAggregator $aggregator,
        private readonly Listing $listing,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('branch', InputArgument::REQUIRED, 'the task\'s branch')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'the task\'s project (needed when several projects have that branch)')
            ->addFormatOption(description: 'output format');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $json = $this->wantsJson($input);
        $branch = (string) $input->getArgument('branch');
        $projectFilter = (string) ($input->getOption('project') ?: '') ?: null;

        $events = [];
        $projects = [];
        foreach ($this->reader->events($projectFilter) as $event) {
            if ($event->branch() !== $branch) {
                continue;
            }
            $events[] = $event;
            $projects[$event->project()] = true;
        }
        if ([] === $projects) {
            throw new PabloError(\sprintf("no analytics recorded for branch '%s'%s (log: %s)", $branch, null !== $projectFilter ? " in project '{$projectFilter}'" : '', JsonlAnalytics::root()));
        }
        if (\count($projects) > 1) {
            $names = array_keys($projects);
            sort($names);
            throw new PabloError(\sprintf("branch '%s' exists in several projects (%s); pass --project", $branch, implode(', ', $names)));
        }
        $project = (string) array_key_first($projects);
        $timeline = $this->aggregator->taskTimeline($events, $project, $branch);
        $totals = AnalyticsAggregator::timelineTotals($timeline);

        if ($json) {
            $this->writeJson($output, ['project' => $project, 'branch' => $branch, 'totals' => $totals, 'timeline' => $timeline]);

            return self::SUCCESS;
        }

        $output->writeln("<info>{$project}/{$branch}</info>");
        $output->writeln('');
        $rows = [];
        foreach ($timeline as $entry) {
            $rows[] = self::row($entry);
        }
        $output->write($this->listing->render(['When (UTC)', 'Event', 'Detail', 'Duration', 'In', 'Out', 'Cache R/W', 'Cost'], $rows));
        $output->writeln('');
        $output->writeln(\sprintf(
            'Agent runs: %d · time %s · tokens in %s, out %s, cache %s/%s · cost %s',
            $totals['runs'],
            Format::duration($totals['duration_s'], true),
            Format::tokens($totals['tokens_in']),
            Format::tokens($totals['tokens_out']),
            Format::tokens($totals['cache_read']),
            Format::tokens($totals['cache_write']),
            null !== $totals['cost'] && $totals['cost'] > 0.0 ? \sprintf('%.2f', $totals['cost']) : '-',
        ));

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return list<string>
     */
    private static function row(array $entry): array
    {
        $when = str_replace('T', ' ', substr((string) $entry['ts'], 0, 19));
        $event = (string) $entry['event'];
        $str = static fn (string $key): string => \is_scalar($entry[$key] ?? null) ? (string) $entry[$key] : '';

        return match ($event) {
            'task_opened' => [$when, 'opened', 'in '.$str('initial_state'), '', '', '', '', ''],
            'state_entered' => [$when, 'state', $str('from').' → '.$str('to'), Format::duration(Numbers::int($entry['dwell_s'] ?? -1), true), '', '', '', ''],
            'agent_run_finished' => [
                $when,
                'agent run',
                $str('agent').' ('.$str('models').')',
                Format::duration(Numbers::int($entry['duration_s'] ?? 0)),
                Format::tokens(Numbers::int($entry['tokens_in'] ?? 0)),
                Format::tokens(Numbers::int($entry['tokens_out'] ?? 0)),
                Format::tokens(Numbers::int($entry['cache_read'] ?? 0)).'/'.Format::tokens(Numbers::int($entry['cache_write'] ?? 0)),
                null !== ($entry['cost'] ?? null) && Numbers::float($entry['cost']) > 0.0 ? \sprintf('%.2f', Numbers::float($entry['cost'])) : '-',
            ],
            'task_closed' => [$when, 'closed', $str('final_state').(true === ($entry['merged'] ?? false) ? ', merged' : ', not merged'), Format::duration(Numbers::int($entry['duration_s'] ?? -1), true), '', '', '', ''],
            default => [$when, $event, '', '', '', '', '', ''],
        };
    }
}
