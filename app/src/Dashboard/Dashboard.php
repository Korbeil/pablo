<?php

declare(strict_types=1);

namespace Pablo\Dashboard;

use Pablo\Config\Config;
use Pablo\Config\ProjectConfig;
use Pablo\Domain\AgentActivity;
use Pablo\Domain\PrBadge;
use Pablo\Domain\Task;
use Pablo\Listing\Listing;
use Pablo\StateMachine\StateMachine;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Pablo\Support\RepoSlug;

/**
 * Everything the dashboard renders, read straight off disk.
 *
 * The two tables use Listing::isWaitingForFeedback() — the same predicate the
 * terminal listing splits on — so `pablo tasks` and this page can never
 * disagree about where a task belongs.
 *
 * Deliberately cache-only: task rows come from the poller-written DisplayCache,
 * never from a live provider lookup, so the page paints instantly and a browser
 * refresh can never trigger a gh/orca/tracker call. The single exception is the
 * per-project repo slug used to build PR links — one local
 * `git remote get-url origin` per project that actually has a PR, memoised for
 * the request and degraded to "no link" on failure.
 *
 * Strictly read-only: nothing here writes to the store.
 */
final class Dashboard
{
    /**
     * Project types the analytics/task tabs offer; '' means "All projects".
     * Mirrors Config::PROJECT_TYPES with the All sentinel prepended.
     */
    public const TYPES = ['', 'work', 'open-source', 'personal'];

    /** @var array<string, ?string> memoised repo slugs, keyed by project name */
    private array $slugs = [];

    public function __construct(
        private readonly Store $store,
        private readonly PollSchedule $pollSchedule,
        private readonly Config $config,
        private readonly Listing $listing,
        private readonly RepoSlug $repoSlug,
        private readonly StateMachine $stateMachine,
    ) {
    }

    /** @return array<string, ProjectConfig> */
    public function projects(): array
    {
        return $this->config->loadProjects();
    }

    /**
     * Projects narrowed to one type; '' means all of them.
     *
     * @return array<string, ProjectConfig>
     */
    public function projectsOfType(string $type): array
    {
        if ('' === $type) {
            return $this->projects();
        }

        return array_filter(
            $this->projects(),
            static fn (ProjectConfig $cfg): bool => $cfg->type === $type,
        );
    }

    /**
     * The two tables: tasks needing attention, and everything else.
     *
     * @param array<string, ProjectConfig>|null $projects
     */
    public function board(?array $projects = null): Board
    {
        $projects ??= $this->projects();

        $attention = [];
        $rest = [];
        foreach ($this->store->allTasks() as $task) {
            $cfg = $projects[$task->project] ?? null;
            if (null === $cfg) {
                continue; // task whose project config was removed
            }
            $view = $this->viewFor($task, $cfg);
            if ($view->needsAttention) {
                $attention[] = $view;
            } else {
                $rest[] = $view;
            }
        }

        $this->sort($attention);
        $this->sort($rest);

        return new Board($attention, $rest);
    }

    /**
     * @param array<string, ProjectConfig>|null $projects
     *
     * @return list<PollWindow>
     */
    public function pollWindows(?array $projects = null): array
    {
        return $this->pollSchedule->windows($projects ?? $this->projects());
    }

    /**
     * The window driving the shared countdown bar, anchored to the most recent
     * poll across all projects.
     *
     * @param array<string, ProjectConfig>|null $projects
     */
    public function barWindow(?array $projects = null): ?PollWindow
    {
        return $this->pollSchedule->barWindow($projects ?? $this->projects());
    }

    /** @param list<TaskView> $views */
    private function sort(array &$views): void
    {
        usort($views, static function (TaskView $a, TaskView $b): int {
            return [$a->rank(), $a->stateEnteredAt] <=> [$b->rank(), $b->stateEnteredAt];
        });
    }

    private function viewFor(Task $task, ProjectConfig $cfg): TaskView
    {
        $cache = $task->displayCache;
        $def = $this->stateMachine->states()[$task->state->value] ?? null;

        $agents = AgentActivity::fromDisplay($cache->agentCount, $cache->agentActivity);
        $pr = null !== $cache->prState
            ? PrBadge::fromDisplay($cache->prState)
            : PrBadge::fromTask($task, null);

        return new TaskView(
            project: $task->project,
            branch: $task->branch,
            state: $task->state,
            stateEmoji: $def['emoji'] ?? '',
            stateLabel: $def['label'] ?? $task->state->value,
            agents: $agents,
            pr: $pr,
            prUrl: $pr->url($this->slugFor($cfg)),
            issue: $task->issue,
            summary: $task->summary,
            trackerStatus: $cache->trackerStatus,
            stateEnteredAt: $task->stateEnteredAt,
            since: $this->listing->timeSince($task->stateEnteredAt),
            worktreePath: $task->worktreePath,
            needsAttention: $this->listing->isWaitingForFeedback($task, $agents),
            polledAt: $cache->at,
        );
    }

    /** One local `git remote get-url origin` per project, memoised. */
    private function slugFor(ProjectConfig $cfg): ?string
    {
        if (\array_key_exists($cfg->name, $this->slugs)) {
            return $this->slugs[$cfg->name];
        }

        try {
            return $this->slugs[$cfg->name] = $this->repoSlug->for($cfg);
        } catch (PabloError) {
            return $this->slugs[$cfg->name] = null;
        }
    }
}
