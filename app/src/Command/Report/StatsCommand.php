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
use Pablo\Config\Config;
use Pablo\Domain\Time;
use Pablo\Listing\Listing;
use Pablo\Store\Store;
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
            ->addOption('agent', null, InputOption::VALUE_REQUIRED, 'restrict the agents table to one agent')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'output format', 'table', ['table', 'json']);
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $days = max(0, (int) $input->getOption('days'));
        $cutoffTs = 0 === $days ? 0 : $this->time->parseTs($this->time->utcnow())->getTimestamp() - $days * 86_400;
        $projectFilter = (string) ($input->getOption('project') ?: '') ?: null;

        /** @var list<AnalyticsEvent> $events */
        $events = [];
        foreach ($this->reader->read($projectFilter) as $payload) {
            $event = AnalyticsEvent::fromEvent($payload);
            if ('' !== $event->ts() && $cutoffTs > 0 && $this->tsOf($event) < $cutoffTs) {
                continue;
            }
            $events[] = $event;
        }

        if ([] === $events) {
            $output->writeln(\sprintf('no analytics events recorded yet (log: %s)', JsonlAnalytics::root()));

            return self::SUCCESS;
        }

        $tasks = $this->aggregator->taskStats($events);
        $agents = $this->aggregator->agentStats($events, (string) ($input->getOption('agent') ?: ''));
        $states = $this->aggregator->stateStats($events);

        if ('json' === $input->getOption('format')) {
            $json = json_encode([
                'days' => $days,
                'tasks' => $tasks,
                'agents' => $agents,
                'states' => $states,
            ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            $output->writeln(false !== $json ? $json : '{}');

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
                self::humanDuration(self::intOf($t['avg_life_s']), true),
                self::humanDuration(self::intOf($t['p50_life_s']), true),
                self::humanDuration(self::intOf($t['p90_life_s']), true),
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

    private static function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
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
