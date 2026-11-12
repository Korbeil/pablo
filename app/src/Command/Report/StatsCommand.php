<?php

declare(strict_types=1);

namespace Pablo\Command\Report;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Analytics\AnalyticsAggregator;
use Pablo\Analytics\AnalyticsEvent;
use Pablo\Analytics\AnalyticsReader;
use Pablo\Analytics\JsonlAnalytics;
use Pablo\Command\Command;
use Pablo\Command\FormatsOutput;
use Pablo\Config\Config;
use Pablo\Dashboard\Dashboard;
use Pablo\Domain\Time;
use Pablo\Listing\Listing;
use Pablo\Store\Store;
use Pablo\Support\Numbers;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Aggregates the analytics event log: task lifetimes, per-agent run counts,
 * token usage (with cache hit rate) and state dwell times. Aggregation lives
 * in AnalyticsAggregator, shared with the dashboard's /analytics page.
 */
#[AsCommand(name: 'stats', description: 'aggregate analytics: task lifetimes, agent runs/tokens/cost, state dwell times')]
final class StatsCommand extends Command
{
    use FormatsOutput;

    private const STATS_FORMATS = [...self::FORMATS, 'csv'];

    public function __construct(
        private readonly AnalyticsReader $reader,
        private readonly AnalyticsAggregator $aggregator,
        private readonly Listing $listing,
        private readonly Time $time,
        Store $store,
        Config $projectsLoader,
        AgentLauncherFactory $agentLaunchers,
        AgentLauncherInterface $agents,
    ) {
        parent::__construct($store, $projectsLoader, $agentLaunchers, $agents);
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'look back N days (0 = all time)', '30')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'restrict to one project')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'restrict to one project type (work/open-source/personal)', '', Dashboard::TYPES)
            ->addOption('agent', null, InputOption::VALUE_REQUIRED, 'restrict the agents table to one agent')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'output format: table, json or csv (csv: a --by breakdown)', 'table', self::STATS_FORMATS)
            ->addOption('by', null, InputOption::VALUE_REQUIRED, 'break agent runs down by agent, project, model or task (csv defaults to agent)', null, AnalyticsAggregator::BREAKDOWNS)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'with --by: keep the N groups with the most tokens (0 = all)', '20');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $days = max(0, (int) $input->getOption('days'));
        // The shared table|json convention (FormatsOutput), plus csv for a breakdown.
        $format = (string) $input->getOption('format');
        if (!\in_array($format, self::STATS_FORMATS, true)) {
            throw new PabloError(\sprintf("invalid --format '%s'; expected one of: %s", $format, implode(', ', self::STATS_FORMATS)));
        }
        $json = 'json' === $format;
        $by = (string) ($input->getOption('by') ?? '');
        if ('' !== $by && !\in_array($by, AnalyticsAggregator::BREAKDOWNS, true)) {
            throw new PabloError(\sprintf("invalid --by '%s'; expected one of: %s", $by, implode(', ', AnalyticsAggregator::BREAKDOWNS)));
        }
        if ('csv' === $format && '' === $by) {
            $by = 'agent';
        }
        $cutoffTs = 0 === $days ? 0 : $this->time->parseTs($this->time->utcnow())->getTimestamp() - $days * 86_400;
        $projectFilter = (string) ($input->getOption('project') ?: '') ?: null;
        $typeFilter = (string) $input->getOption('type');
        if (!\in_array($typeFilter, Dashboard::TYPES, true)) {
            throw new PabloError(\sprintf("invalid --type '%s'; expected one of: %s", $typeFilter, implode(', ', array_filter(Dashboard::TYPES))));
        }

        /** @var list<AnalyticsEvent> $events */
        $events = [];
        $since = $cutoffTs > 0 ? new \DateTimeImmutable('@'.$cutoffTs) : null;
        foreach ($this->reader->read($projectFilter, $since) as $payload) {
            $event = AnalyticsEvent::fromEvent($payload);
            if ('' !== $event->ts() && $cutoffTs > 0 && $this->tsOf($event) < $cutoffTs) {
                continue;
            }
            if ('' !== $typeFilter && !$this->projectHasType($event->project(), $typeFilter)) {
                continue;
            }
            $events[] = $event;
        }

        if ('' !== $by) {
            $this->renderBreakdown($output, $format, $days, $by, $this->aggregator->runBreakdown(
                $events,
                $by,
                (string) ($input->getOption('agent') ?: ''),
            ), max(0, (int) $input->getOption('limit')));

            return self::SUCCESS;
        }

        if ([] === $events && !$json) {
            $output->writeln(\sprintf('no analytics events recorded yet (log: %s)', JsonlAnalytics::root()));

            return self::SUCCESS;
        }

        $tasks = $this->aggregator->taskStats($events);
        $agents = $this->aggregator->agentStats($events, (string) ($input->getOption('agent') ?: ''));
        $states = $this->aggregator->stateStats($events);

        if ($json) {
            $this->writeJson($output, [
                'days' => $days,
                'tasks' => $tasks,
                'agents' => $agents,
                'states' => $states,
            ]);

            return self::SUCCESS;
        }

        $output->writeln(\sprintf('<info>PABLO analytics — last %s</info>', 0 === $days ? 'all time' : "{$days}d"));
        $output->writeln('');
        $this->renderTasks($output, $tasks);
        $this->renderAgents($output, $agents);
        $this->renderStates($output, $states);

        return self::SUCCESS;
    }

    // --------------------------------------------------------- rendering ---

    /**
     * One row per group of finished agent runs (--by), as a table, JSON or
     * CSV (header line first). Groups arrive sorted by total tokens.
     *
     * @param array<string, array{runs: int, duration_s: int, avg_run_s: int, tokens_in: int, tokens_out: int, cache_read: int, cache_write: int, tokens_total: int, cost: float|null}> $groups
     */
    private function renderBreakdown(OutputInterface $output, string $format, int $days, string $by, array $groups, int $limit): void
    {
        if ($limit > 0) {
            $groups = \array_slice($groups, 0, $limit, true);
        }
        if ('json' === $format) {
            $this->writeJson($output, ['days' => $days, 'by' => $by, 'groups' => $groups]);

            return;
        }
        if ('csv' === $format) {
            $columns = ['runs', 'duration_s', 'avg_run_s', 'tokens_in', 'tokens_out', 'cache_read', 'cache_write', 'tokens_total', 'cost'];
            $buffer = fopen('php://temp', 'w+');
            if (false === $buffer) {
                throw new PabloError('cannot open a buffer for the CSV output');
            }
            fputcsv($buffer, [$by, ...$columns], escape: '');
            foreach ($groups as $key => $row) {
                fputcsv($buffer, [$key, ...array_map(static fn (string $c) => $row[$c] ?? '', $columns)], escape: '');
            }
            rewind($buffer);
            $output->write((string) stream_get_contents($buffer), false, OutputInterface::OUTPUT_RAW);
            fclose($buffer);

            return;
        }

        $output->writeln(\sprintf('<info>PABLO analytics — agent runs by %s, %s</info>', $by, 0 === $days ? 'all time' : "last {$days}d"));
        $output->writeln('');
        if ([] === $groups) {
            $output->writeln('  (no agent runs recorded)');

            return;
        }
        $rows = [];
        foreach ($groups as $key => $g) {
            $rows[] = [
                $key,
                (string) $g['runs'],
                self::humanDuration($g['duration_s'], true),
                self::humanDuration($g['avg_run_s']),
                self::humanTokens($g['tokens_in']),
                self::humanTokens($g['tokens_out']),
                self::humanTokens($g['cache_read']),
                self::humanTokens($g['cache_write']),
                self::humanTokens($g['tokens_total']),
                null !== $g['cost'] && $g['cost'] > 0.0 ? \sprintf('%.2f', $g['cost']) : '-',
            ];
        }
        $output->write($this->listing->render(
            [ucfirst($by), 'Runs', 'Time', 'Avg time', 'In', 'Out', 'Cache R', 'Cache W', 'Total', 'Cost'],
            $rows,
        ));
    }

    /**
     * @param array<string, array<string, float|int|string>> $tasks
     */
    private function renderTasks(OutputInterface $output, array $tasks): void
    {
        $output->writeln('Tasks');
        if ([] === $tasks) {
            $output->writeln('  (no tasks recorded)');

            return;
        }
        $rows = [];
        foreach ($tasks as $project => $t) {
            $rows[] = [
                $project,
                (string) $t['opened'],
                (string) $t['closed'],
                $t['closed'] > 0 ? $t['merge_rate_pct'].'%' : '-',
                self::humanDuration(Numbers::int($t['avg_life_s']), true),
                self::humanDuration(Numbers::int($t['p50_life_s']), true),
                self::humanDuration(Numbers::int($t['p90_life_s']), true),
            ];
        }
        $output->write($this->listing->render(
            ['Project', 'Opened', 'Closed', 'Merged', 'Avg life', 'p50', 'p90'],
            $rows,
        ));
        $output->writeln('');
    }

    /**
     * @param array<string, array<string, float|int|string>> $agents
     */
    private function renderAgents(OutputInterface $output, array $agents): void
    {
        $output->writeln('Agent runs');
        if ([] === $agents) {
            $output->writeln('  (no agent runs recorded)');

            return;
        }
        $rows = [];
        foreach ($agents as $name => $a) {
            $rows[] = [
                $name,
                (string) $a['runs'],
                (string) $a['starts'],
                self::humanDuration((int) $a['avg_run_s']),
                self::humanTokens((int) $a['tokens_in']),
                self::humanTokens((int) $a['tokens_out']),
                self::humanTokens((int) $a['context_tokens']),
                $a['context_tokens'] > 0 ? $a['cache_hit_pct'].'%' : '-',
                (float) $a['cost'] > 0.0 ? \sprintf('%.2f', $a['cost']) : '-',
                self::humanDuration((int) $a['avg_user_wait_s'], false, (int) $a['avg_user_wait_s'] < 0),
            ];
        }
        $output->write($this->listing->render(
            ['Agent', 'Runs', 'Starts', 'Avg time', 'In', 'Out', 'Ctx', 'Hit%', 'Cost', 'User wait'],
            $rows,
        ));
        $output->writeln('');
    }

    /**
     * @param array<string, array<string, float|int|string>> $states
     */
    private function renderStates(OutputInterface $output, array $states): void
    {
        $output->writeln('States (entries = rework-loop counter)');
        if ([] === $states) {
            $output->writeln('  (no transitions recorded)');

            return;
        }
        $rows = [];
        foreach ($states as $state => $s) {
            $rows[] = [
                $state,
                (string) $s['entries'],
                self::humanDuration((int) $s['avg_dwell_s'], false, (int) $s['avg_dwell_s'] < 0),
            ];
        }
        $output->write($this->listing->render(['State', 'Entries', 'Avg dwell'], $rows));
    }

    // ------------------------------------------------------------ helpers ---

    private function tsOf(AnalyticsEvent $event): int
    {
        try {
            return $this->time->parseTs($event->ts())->getTimestamp();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Same predicate as the task board's type tabs (Dashboard::projectsOfType),
     * so the CLI and the web page can never disagree about what a filter
     * includes. Projects removed from config match no type — their history
     * stays visible only without --type.
     */
    private function projectHasType(string $project, string $type): bool
    {
        foreach ($this->projectsLoader->loadProjects() as $name => $cfg) {
            if ($name === $project && $cfg->type === $type) {
                return true;
            }
        }

        return false;
    }

    /** Compact human duration; -1 input renders as "-" (no data). */
    public static function humanDuration(int $seconds, bool $allowDays = false, bool $noData = false): string
    {
        if ($noData || $seconds < 0) {
            return '-';
        }
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        $minutes = (int) floor($seconds / 60);
        if ($minutes < 60) {
            return "{$minutes}m";
        }
        $hours = (int) floor($minutes / 60);
        $remMinutes = $minutes % 60;
        if ($hours < 48 || !$allowDays) {
            return 0 === $remMinutes ? "{$hours}h" : "{$hours}h{$remMinutes}m";
        }
        $days = (int) floor($hours / 24);

        return $days.'d'.($hours % 24).'h';
    }

    public static function humanTokens(int $tokens): string
    {
        if ($tokens >= 1_000_000) {
            return \sprintf('%.1fM', $tokens / 1_000_000);
        }
        if ($tokens >= 10_000) {
            return \sprintf('%.0fk', $tokens / 1_000);
        }
        if ($tokens >= 1_000) {
            return \sprintf('%.1fk', $tokens / 1_000);
        }

        return (string) $tokens;
    }
}
