<?php

declare(strict_types=1);

namespace Pablo\Controller;

use Pablo\Analytics\AnalyticsAggregator;
use Pablo\Analytics\AnalyticsEvent;
use Pablo\Analytics\AnalyticsReader;
use Pablo\Analytics\JsonlAnalytics;
use Pablo\Dashboard\AnalyticsCharts;
use Pablo\Dashboard\Dashboard;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The /analytics page: the same aggregated numbers `pablo stats` prints,
 * rendered as UX Chart.js graphs.
 *
 * Strictly read-only like every other PABLO read surface, and cheaper than
 * the task board even: it reads only the local JSONL event log under
 * ~/.pablo/analytics/ — no git/gh/orca/tracker subprocess can ever run here.
 */
final class AnalyticsController extends AbstractController
{
    private const DAY_CHOICES = ['7', '30', '90', 'all'];

    /** Breakdown table rows shown (same default as `stats --by --limit`). */
    private const BREAKDOWN_LIMIT = 20;

    /** Tasks listed under "Top tasks by tokens". */
    private const TOP_TASKS = 10;

    public function __construct(
        private readonly AnalyticsReader $reader,
        private readonly AnalyticsAggregator $aggregator,
        private readonly AnalyticsCharts $charts,
        private readonly Dashboard $dashboard,
        private readonly Store $store,
    ) {
    }

    #[Route('/analytics', name: 'analytics', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $days = (string) $request->query->get('days', '30');
        if (!\in_array($days, self::DAY_CHOICES, true)) {
            $days = '30';
        }
        $type = (string) $request->query->get('type', '');
        if (!\in_array($type, Dashboard::TYPES, true)) {
            $type = '';
        }
        $by = (string) $request->query->get('by', 'agent');
        if (!\in_array($by, AnalyticsAggregator::BREAKDOWNS, true)) {
            $by = 'agent';
        }
        // Type resolution shares the task board's predicate
        // (Dashboard::projectsOfType), so the two filters can never disagree.
        // Events whose project has no config anymore cannot be attributed to
        // a type: they stay visible under All, never under a specific tab.
        $allowed = '' !== $type ? array_keys($this->dashboard->projectsOfType($type)) : null;

        $since = null;
        if ('all' !== $days) {
            $since = new \DateTimeImmutable('@'.(time() - (int) $days * 86_400));
        }
        $events = $this->reader->events(null, $since, $allowed);
        // Only months overlapping the window were read; when that yields
        // nothing, has_any_events is settled by a full read.
        $anyEvents = [] !== $events || [] !== $this->reader->read(null);

        $tasks = $this->aggregator->taskStats($events);
        $agents = $this->aggregator->agentStats($events);
        $states = $this->aggregator->stateStats($events);
        $daily = $this->aggregator->dailySeries($events, $since);

        $opened = array_sum(array_map(static fn (array $t) => (int) $t['opened'], $tasks));
        $closed = array_sum(array_map(static fn (array $t) => (int) $t['closed'], $tasks));
        $merged = array_sum(array_map(
            static fn (array $t) => (int) round(((float) $t['merge_rate_pct']) * (int) $t['closed'] / 100),
            $tasks,
        ));
        $runs = array_sum(array_map(static fn (array $a) => (int) $a['runs'], $agents));
        $cost = array_sum(array_map(static fn (array $a) => (float) $a['cost'], $agents));

        $hasAgents = [] !== $agents;
        // Same grouping as `pablo stats --by`, so the two cannot drift.
        $breakdown = $this->aggregator->runBreakdown($events, $by);
        $byTask = 'task' === $by ? $breakdown : $this->aggregator->runBreakdown($events, 'task');
        $byModel = 'model' === $by ? $breakdown : $this->aggregator->runBreakdown($events, 'model');
        $topTasks = [];
        foreach (\array_slice($byTask, 0, self::TOP_TASKS, true) as $key => $row) {
            // The task key is "<project>/<branch>"; a project name is a
            // config file name, so the first slash ends it.
            [$project, $branch] = explode('/', (string) $key, 2) + [1 => ''];
            $topTasks[] = ['project' => $project, 'branch' => $branch] + $row;
        }

        return $this->render('analytics/index.html.twig', [
            'active_days' => $days,
            'day_choices' => self::DAY_CHOICES,
            'active_type' => $type,
            'active_by' => $by,
            'by_choices' => AnalyticsAggregator::BREAKDOWNS,
            'type_choices' => Dashboard::TYPES,
            'has_events' => [] !== $events,
            'has_any_events' => $anyEvents,
            'kpis' => [
                'Tasks opened' => (string) $opened,
                'Tasks closed' => (string) $closed,
                'Merged' => $closed > 0 ? round(100 * $merged / $closed, 1).'%' : '-',
                'Agent runs' => (string) $runs,
                'Cost' => $cost > 0.0 ? \sprintf('%.2f', $cost) : '-',
            ],
            'throughput' => [] !== $daily ? $this->charts->throughputChart($daily) : null,
            'tokens' => $hasAgents ? $this->charts->tokensChart($agents) : null,
            'cache_hit' => $hasAgents ? $this->charts->cacheHitChart($agents) : null,
            'runtime' => $hasAgents ? $this->charts->runtimeChart($agents) : null,
            'cost_chart' => $hasAgents ? $this->charts->costChart($agents) : null,
            'dwell' => [] !== $states ? $this->charts->stateChart($states) : null,
            'breakdown' => \array_slice($breakdown, 0, self::BREAKDOWN_LIMIT, true),
            'breakdown_hidden' => max(0, \count($breakdown) - self::BREAKDOWN_LIMIT),
            'breakdown_chart' => [] !== $breakdown ? $this->charts->breakdownChart($breakdown) : null,
            'top_tasks' => $topTasks,
            'model_chart' => [] !== $byModel ? $this->charts->modelChart($byModel) : null,
        ]);
    }

    /**
     * One task's analytics timeline — the web twin of `pablo task:history`.
     * Works for closed tasks too: it reads only the project's JSONL log
     * (and, to tell "never recorded" from "unknown task", the local state
     * file). The project must be configured or have an analytics log, so a
     * crafted path segment can never reach the filesystem.
     */
    #[Route('/analytics/task/{project}/{branch}', name: 'analytics_task', requirements: ['project' => '[^/]+', 'branch' => '.+'], methods: ['GET'])]
    public function task(string $project, string $branch): Response
    {
        if (!isset($this->knownProjects()[$project])) {
            throw $this->createNotFoundException("unknown project '{$project}'");
        }

        $events = array_values(array_filter(
            $this->reader->events($project),
            static fn (AnalyticsEvent $event): bool => $event->branch() === $branch,
        ));
        $timeline = $this->aggregator->taskTimeline($events, $project, $branch);
        if ([] === $timeline && !$this->isStoredTask($project, $branch)) {
            throw $this->createNotFoundException("no task '{$branch}' in project '{$project}'");
        }

        return $this->render('analytics/task.html.twig', [
            'project' => $project,
            'branch' => $branch,
            'timeline' => $timeline,
            'totals' => AnalyticsAggregator::timelineTotals($timeline),
        ]);
    }

    /**
     * Configured projects plus every project with an analytics log (a
     * project whose config is gone keeps its history).
     *
     * @return array<string, true>
     */
    private function knownProjects(): array
    {
        $known = array_fill_keys(array_keys($this->dashboard->projects()), true);
        foreach (glob(JsonlAnalytics::root().'/*', \GLOB_ONLYDIR) ?: [] as $dir) {
            $known[basename($dir)] = true;
        }

        return $known;
    }

    private function isStoredTask(string $project, string $branch): bool
    {
        try {
            return null !== $this->store->get($project, $branch);
        } catch (PabloError) {
            return true; // a record exists, it is just unreadable
        }
    }
}
