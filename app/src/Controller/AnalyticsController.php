<?php

declare(strict_types=1);

namespace Pablo\Controller;

use Pablo\Analytics\AnalyticsAggregator;
use Pablo\Analytics\AnalyticsEvent;
use Pablo\Analytics\AnalyticsReader;
use Pablo\Dashboard\AnalyticsCharts;
use Pablo\Dashboard\Dashboard;
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

    public function __construct(
        private readonly AnalyticsReader $reader,
        private readonly AnalyticsAggregator $aggregator,
        private readonly AnalyticsCharts $charts,
        private readonly Dashboard $dashboard,
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
        // Type resolution shares the task board's predicate
        // (Dashboard::projectsOfType), so the two filters can never disagree.
        // Events whose project has no config anymore cannot be attributed to
        // a type: they stay visible under All, never under a specific tab.
        $allowed = null;
        if ('' !== $type) {
            $allowed = array_fill_keys(array_keys($this->dashboard->projectsOfType($type)), true);
        }

        $since = null;
        if ('all' !== $days) {
            $since = new \DateTimeImmutable('@'.(time() - (int) $days * 86_400));
        }
        $cutoffTs = null === $since ? 0 : $since->getTimestamp();

        /** @var list<AnalyticsEvent> $events */
        $events = [];
        $anyEvents = false;
        foreach ($this->reader->read(null) as $payload) {
            $event = AnalyticsEvent::fromEvent($payload);
            $anyEvents = true;
            if ('' !== $event->ts() && $this->tsOf($event) < $cutoffTs) {
                continue;
            }
            if (null !== $allowed && !isset($allowed[$event->project()])) {
                continue;
            }
            $events[] = $event;
        }

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

        return $this->render('analytics/index.html.twig', [
            'active_days' => $days,
            'day_choices' => self::DAY_CHOICES,
            'active_type' => $type,
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
        ]);
    }

    private function tsOf(AnalyticsEvent $event): int
    {
        try {
            return (new \DateTimeImmutable($event->ts()))->getTimestamp();
        } catch (\Throwable) {
            return 0;
        }
    }
}
