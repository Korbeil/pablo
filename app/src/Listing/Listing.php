<?php

declare(strict_types=1);

namespace Pablo\Listing;

use Pablo\Agents\AgentLauncherInterface;
use Pablo\Agents\SessionInfo;
use Pablo\Config\ProjectConfig;
use Pablo\Dispatch\Stamps;
use Pablo\Domain\AgentActivity;
use Pablo\Domain\DisplayCache;
use Pablo\Domain\PrBadge;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Domain\Time;
use Pablo\Provider\Gh\GhPrInterface;
use Pablo\Provider\Gh\PrInfo;
use Pablo\Provider\Git\GitRepoInterface;
use Pablo\Provider\Tracker\ProviderRegistryInterface;
use Pablo\StateMachine\StateMachine;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Pablo\Support\RepoSlug;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Terminal tables: the issue-tracking view and the active-task listing.
 *
 * Both are strictly read-only.
 */
final class Listing
{
    // Only these states qualify for the "💭 Waiting for feedback" section.
    private const WAITING_FEEDBACK_STATES = [
        State::InProgress,
        State::CiRed,
        State::RequestChanges,
        State::TestingFailed,
    ];

    private const SLACK_EMPTY = [
        State::WaitingReview->value => 'No PRs waiting for review right now 🎉',
        State::NeedsTesting->value => 'Nothing needs testing right now 🎉',
        State::Approved->value => 'No approved PRs awaiting merge right now 🎉',
    ];

    private const TASKS_HEADERS = ['Project', 'Task', 'State', 'Agents', 'Activity', 'Issue', 'Tracker', 'PR', 'Since'];

    public function __construct(
        private readonly Stamps $stamps,
        private readonly GhPrInterface $gh,
        private readonly GitRepoInterface $git,
        private readonly ProviderRegistryInterface $providers,
        private readonly RepoSlug $repoSlug,
        private readonly StateMachine $stateMachine,
        private readonly Time $time,
    ) {
    }

    /**
     * Is this task blocked on the user right now?
     *
     * The single definition of the "💭 Waiting for feedback" split, shared by
     * the terminal listing and the web dashboard so the two cannot drift. A
     * task counts when its state is eligible and either PABLO has triggered an
     * agent (task-analyst, ci-analyst, pr/task-feedback) on it whose run has
     * concluded (`Task::hasFinishedAgent()`, stamped by the watch-agent/backfill),
     * or a live/waiting session is currently reported on its worktree. That
     * union keeps existing behaviour while adding the PABLO-owned signal, which
     * is what surfaces an agent the ADE has already evicted from its list.
     *
     * A task with an agent currently working on it (🏃) never qualifies: the
     * ball is not with the user yet, however many earlier runs have concluded.
     *
     * Note it deliberately excludes needs-testing and waiting-review: those
     * mean the ball is with QA/reviewers, not with you.
     */
    public function isWaitingForFeedback(Task $task, ?AgentActivity $agents = null): bool
    {
        if (!\in_array($task->state, self::WAITING_FEEDBACK_STATES, true)) {
            return false;
        }
        if (null !== $agents && $agents->isRunning()) {
            return false;
        }

        return $task->hasFinishedAgent() || (null !== $agents && $agents->isWaiting());
    }

    // ------------------------------------------------------- ordering ----

    public function stateRank(State $state): int
    {
        return $state->displayRank();
    }

    public function timeSince(string $isoTimestamp): string
    {
        try {
            $dt = new \DateTimeImmutable($isoTimestamp);
        } catch (\Exception) { // a malformed timestamp
            return '?';
        }

        return $this->ago((int) ((new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp() - $dt->getTimestamp()));
    }

    /** "just now", "5m ago", "3h ago", "2d ago" (each unit rounded down). */
    private function ago(int $seconds): string
    {
        if ($seconds < 60) {
            return 'just now';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60).'m ago';
        }
        if ($seconds < 86400) {
            return intdiv($seconds, 3600).'h ago';
        }

        return intdiv($seconds, 86400).'d ago';
    }

    /** @param array<string, ProjectConfig> $projects */
    public function lastPollHeader(array $projects): string
    {
        $latest = null;
        foreach ($projects as $name => $_) {
            $stamp = $this->stamps->readStamp($name, 'poll');
            if (null === $stamp) {
                continue;
            }
            $dt = (new \DateTimeImmutable('@'.(int) $stamp->ranAt))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            if (null === $latest || $dt > $latest) {
                $latest = $dt;
            }
        }
        if (null === $latest) {
            return '';
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));

        return 'Poller last ran: '.$this->ago((int) ($now->getTimestamp() - $latest->getTimestamp())).' ('.$latest->format('H:i').')';
    }

    /**
     * @param array<int, string>             $headers
     * @param array<int, array<int, string>> $rows
     */
    private function table(array $headers, array $rows): string
    {
        $output = new BufferedOutput();
        $table = new Table($output);
        $table->setHeaders($headers);
        $table->setRows($rows);
        $table->render();

        return $output->fetch();
    }

    /** Terminal display-column width of $s (emoji = 2, ASCII = 1). */
    public function displayWidth(string $s): int
    {
        $w = 0;
        $len = mb_strlen($s, 'UTF-8');
        for ($i = 0; $i < $len; ++$i) {
            $w += mb_strwidth(mb_substr($s, $i, 1, 'UTF-8'), 'UTF-8');
        }

        return $w;
    }

    public function padRight(string $s, int $width): string
    {
        return $s.str_repeat(' ', max(0, $width - $this->displayWidth($s)));
    }

    /**
     * @param array<int, string>             $headers
     * @param array<int, array<int, string>> $rows
     * @param array<int, int>|null           $widths
     */
    public function render(array $headers, array $rows, ?array $widths = null): string
    {
        if (null === $widths) {
            $widths = [];
            foreach ($headers as $i => $h) {
                $w = $this->displayWidth($h);
                foreach ($rows as $row) {
                    $w = max($w, $this->displayWidth($row[$i] ?? ''));
                }
                $widths[] = $w;
            }
        }
        $lines = [];
        $lines[] = implode('  ', array_map(fn ($i) => $this->padRight($headers[$i], $widths[$i]), array_keys($headers)));
        $lines[] = implode('  ', array_map(static fn ($w) => str_repeat('-', $w), $widths));
        foreach ($rows as $row) {
            $buf = [];
            foreach (array_keys($headers) as $i) {
                $buf[] = $this->padRight($row[$i] ?? '', $widths[$i]);
            }
            $lines[] = implode('  ', $buf);
        }

        return implode("\n", $lines);
    }

    // --------------------------------------------------- issues table ----

    /**
     * @param array<int, string> $names
     *
     * @return list<string>
     */
    private function branchCandidates(string $base, array $names): array
    {
        $found = [];
        foreach ($names as $name) {
            if ($name === $base) {
                $found[] = $name;
            }
        }
        $n = 2;
        while (\in_array("{$base}-{$n}", $names, true)) {
            $found[] = "{$base}-{$n}";
            ++$n;
        }

        return $found;
    }

    public function issuesTable(ProjectConfig $cfg, Store $store): string
    {
        $provider = $this->providers->get($cfg->provider);
        $issues = $provider->listAssigned($cfg);
        $names = $this->git->allBranchNames($cfg->repoPath);
        $slug = $this->repoSlug->for($cfg);

        $rows = [];
        foreach ($issues as $issue) {
            $base = 'github' === $cfg->provider
                ? strtolower($cfg->projectKey).'-'.strtolower($issue->key)
                : strtolower($issue->key);
            $branches = $this->branchCandidates($base, $names);

            $prCell = '-';
            foreach ($branches as $branch) {
                $pr = $this->gh->prForBranch($slug, $branch);
                if (null !== $pr) {
                    $state = 'MERGED' === $pr->state ? 'merged' : ($pr->isDraft ? 'draft' : strtolower($pr->state));
                    $prCell = "#{$pr->number} {$pr->title} ({$state})";
                    break;
                }
            }

            $rows[] = [
                $issue->key,
                $issue->title,
                $issue->status ?? '-',
                $prCell,
                [] !== $branches ? implode(', ', $branches) : '-',
            ];
        }

        return $this->table(['Issue', 'Title', 'Status', 'PR', 'Branch'], $rows);
    }

    // ---------------------------------------------------- tasks table ----

    private function issueCell(Task $task): string
    {
        if (null !== $task->issue) {
            return "{$task->issue->key} {$task->issue->title}";
        }

        return $task->summary ?? '-';
    }

    public function prStateCell(Task $task, ?PrInfo $pr): string
    {
        return PrBadge::fromTask($task, $pr)->render();
    }

    /**
     * @param array<int, SessionInfo> $sessions
     */
    public function agentActivitySummary(array $sessions): AgentActivity
    {
        return AgentActivity::fromSessions($sessions);
    }

    /**
     * @param array<string, string>|null $prefetched key => status map from batch lookup
     */
    private function remoteStatusCell(Task $task, ProjectConfig $cfg, bool $live, ?array $prefetched = null): string
    {
        if (null === $task->issue) {
            return 'N/A';
        }
        if (!$live && null !== $task->displayCache->trackerStatus) {
            return $task->displayCache->trackerStatus;
        }
        if (null !== $prefetched && isset($prefetched[$task->issue->key])) {
            return $prefetched[$task->issue->key];
        }
        try {
            return $this->providers->get($cfg->provider)->issueStatus($task->issue->key, $cfg);
        } catch (PabloError) {
            return '?';
        }
    }

    /** @param array<string, PrInfo>|null $prefetched */
    private function prCell(Task $task, ProjectConfig $cfg, bool $live, ?array $prefetched): string
    {
        if (!$live && null !== $task->displayCache->prState) {
            return $task->displayCache->prState;
        }
        if ($task->merged) {
            return '✅ merged';
        }
        if (null === $task->prNumber) {
            return '-';
        }
        if (null !== $prefetched) {
            return $this->prStateCell($task, $prefetched[$task->branch] ?? null);
        }
        try {
            $pr = $this->gh->prForBranch($this->repoSlug->for($cfg), $task->branch);
        } catch (PabloError) {
            return "#{$task->prNumber}";
        }

        return $this->prStateCell($task, $pr);
    }

    /**
     * @param array<int, SessionInfo>|null $sessions
     */
    private function agentCells(Task $task, bool $live, AgentLauncherInterface $agents, ?array $sessions): AgentCell
    {
        if (!$live && null !== $task->displayCache->agentCount) {
            return new AgentCell((string) $task->displayCache->agentCount, $task->displayCache->agentActivity ?? '-');
        }
        if (null === $sessions) {
            $sessions = $agents->activeSessions($task->worktreePath);
        }
        $activity = $this->agentActivitySummary($sessions);

        return new AgentCell((string) $activity->total, $activity->render());
    }

    /**
     * The tasks table, gathered (taskRows()) then rendered
     * (renderTasksTable()).
     *
     * @param array<string, ProjectConfig> $projects
     * @param list<State>                  $states
     */
    public function tasksTable(array $projects, Store $store, AgentLauncherInterface $agents, bool $live = false, bool $refresh = false, array $states = []): string
    {
        return $this->renderTasksTable($this->taskRows($projects, $store, $agents, $live, $refresh, $states), $projects);
    }

    /**
     * Renders rows from taskRows() as the terminal listing, split into two
     * sections, each sorted by state (ties keep store insertion order —
     * stable): "💭 Waiting for feedback" = states in WAITING_FEEDBACK_STATES
     * with a waiting agent, and the tail ("Other tasks") for everything else
     * — including tasks with a running agent, whose 🏃 activity cell keeps
     * them visible. Only reads the poll stamps of $projects for the header.
     *
     * @param list<TaskRow>                $rows
     * @param array<string, ProjectConfig> $projects
     */
    public function renderTasksTable(array $rows, array $projects): string
    {
        if ([] === $rows) {
            return 'no active tasks';
        }

        $waiting = array_values(array_filter($rows, static fn (TaskRow $r) => $r->waitingForFeedback));
        $rest = array_values(array_filter($rows, static fn (TaskRow $r) => !$r->waitingForFeedback));

        $sections = [];
        $pollHeader = $this->lastPollHeader($projects);
        if ('' !== $pollHeader) {
            $sections[] = $pollHeader;
        }
        if ([] !== $waiting) {
            $sections[] = "💭 Waiting for feedback\n\n".$this->tasksSection($waiting);
        }
        if ([] !== $rest) {
            $sections[] = ([] !== $waiting ? "Other tasks\n\n" : '').$this->tasksSection($rest);
        }

        return implode("\n\n", $sections);
    }

    /** @param list<TaskRow> $rows */
    private function tasksSection(array $rows): string
    {
        return $this->table(self::TASKS_HEADERS, array_map($this->taskCells(...), $rows));
    }

    /** @return list<string> */
    private function taskCells(TaskRow $row): array
    {
        return [
            $row->task->project,
            $row->task->branch,
            $row->stateCell(),
            $row->agentCell->count,
            $row->agentCell->activity,
            $this->issueCell($row->task),
            $row->tracker,
            $row->prCell,
            $this->timeSince($row->task->stateEnteredAt),
        ];
    }

    /**
     * The rows of `pablo task:list`, resolved once from the display cache (or
     * fetched live with $live / $refresh; $refresh also persists them as the
     * new cache), in display order: waiting-for-feedback first, each part by
     * state rank. `--format=json` serialises these, renderTasksTable()
     * renders them.
     *
     * Only tasks of $projects are kept; a non-empty $states keeps only the
     * tasks in one of those states.
     *
     * @param array<string, ProjectConfig> $projects
     * @param list<State>                  $states
     *
     * @return list<TaskRow>
     */
    public function taskRows(array $projects, Store $store, AgentLauncherInterface $agents, bool $live = false, bool $refresh = false, array $states = []): array
    {
        $fetchLive = $live || $refresh;
        $tasks = $this->listedTasks($store, $projects, $states);
        $prefetch = $this->prefetch($tasks, $projects, $agents, $fetchLive);

        $rows = [];
        foreach ($tasks as $task) {
            $row = $this->taskRow($task, $projects[$task->project], $agents, $prefetch, $fetchLive);
            if ($refresh) {
                $this->persistDisplayCache($row, $store);
            }
            $rows[] = $row;
        }

        usort($rows, fn (TaskRow $a, TaskRow $b): int => ($b->waitingForFeedback <=> $a->waitingForFeedback) ?: $this->rankEntries($a->task, $b->task));

        return $rows;
    }

    /**
     * @param array<string, ProjectConfig> $projects
     * @param list<State>                  $states
     *
     * @return list<Task>
     */
    private function listedTasks(Store $store, array $projects, array $states): array
    {
        return array_values(array_filter(
            $store->allTasks(),
            static fn (Task $t) => isset($projects[$t->project]) && ([] === $states || \in_array($t->state, $states, true)),
        ));
    }

    /**
     * Bulk-fetches what the display cache cannot answer (everything when
     * $fetchLive): agent sessions, then tracker statuses, then PRs.
     *
     * @param list<Task>                   $tasks
     * @param array<string, ProjectConfig> $projects
     */
    private function prefetch(array $tasks, array $projects, AgentLauncherInterface $agents, bool $fetchLive): TaskPrefetch
    {
        return new TaskPrefetch(
            $this->prefetchSessions($tasks, $agents, $fetchLive),
            $this->prefetchTrackerStatuses($tasks, $projects, $fetchLive),
            $this->prefetchPrs($tasks, $projects, $fetchLive),
        );
    }

    /**
     * @param list<Task> $tasks
     *
     * @return array<string, list<SessionInfo>>
     */
    private function prefetchSessions(array $tasks, AgentLauncherInterface $agents, bool $fetchLive): array
    {
        $worktrees = [];
        foreach ($tasks as $task) {
            if ($fetchLive || null === $task->displayCache->agentCount) {
                $worktrees[] = $task->worktreePath;
            }
        }

        return [] !== $worktrees ? $agents->bulkDisplaySessions($worktrees) : [];
    }

    /**
     * @param list<Task>                   $tasks
     * @param array<string, ProjectConfig> $projects
     *
     * @return array<string, array<string, string>> provider => issue key => status
     */
    private function prefetchTrackerStatuses(array $tasks, array $projects, bool $fetchLive): array
    {
        $byProvider = [];
        foreach ($tasks as $task) {
            $issue = $task->issue;
            if (null === $issue || (!$fetchLive && null !== $task->displayCache->trackerStatus)) {
                continue;
            }
            $cfg = $projects[$task->project];
            $byProvider[$cfg->provider][$issue->key] = $cfg;
        }

        $statuses = [];
        foreach ($byProvider as $providerName => $pairs) {
            try {
                $statuses[$providerName] = $this->providers->get($providerName)->batchIssueStatus($pairs);
            } catch (PabloError) {
                $statuses[$providerName] = [];
            }
        }

        return $statuses;
    }

    /**
     * @param list<Task>                   $tasks
     * @param array<string, ProjectConfig> $projects
     *
     * @return array<string, array<string, PrInfo>> repo slug => branch => PR
     */
    private function prefetchPrs(array $tasks, array $projects, bool $fetchLive): array
    {
        $branchesBySlug = [];
        foreach ($tasks as $task) {
            if ($task->merged || null === $task->prNumber || (!$fetchLive && null !== $task->displayCache->prState)) {
                continue;
            }
            $branchesBySlug[$this->repoSlug->for($projects[$task->project])][] = $task->branch;
        }
        if ([] === $branchesBySlug) {
            return [];
        }

        $repoBranches = [];
        foreach ($branchesBySlug as $slug => $branches) {
            $repoBranches[] = [$slug, $branches];
        }

        return $this->gh->prsForBranchesBulk($repoBranches);
    }

    private function taskRow(Task $task, ProjectConfig $cfg, AgentLauncherInterface $agents, TaskPrefetch $prefetch, bool $fetchLive): TaskRow
    {
        $tracker = $this->remoteStatusCell($task, $cfg, $fetchLive, $prefetch->trackerStatusesFor($cfg->provider));
        $slug = $this->repoSlug->for($cfg);
        $pr = $this->prCell($task, $cfg, $fetchLive, $prefetch->prsFor($slug));
        $cell = $this->agentCells($task, $fetchLive, $agents, $prefetch->sessionsFor($task));
        $def = $this->stateMachine->states()[$task->state->value] ?? null;

        return new TaskRow(
            task: $task,
            stateLabel: $def['label'] ?? $task->state->value,
            stateEmoji: $def['emoji'] ?? '',
            tracker: $tracker,
            prCell: $pr,
            agentCell: $cell,
            waitingForFeedback: $this->isWaitingForFeedback($task, AgentActivity::fromDisplay((int) $cell->count, $cell->activity)),
            repoSlug: $slug,
        );
    }

    /**
     * --refresh: the freshly fetched cells become the task's display cache. A
     * tracker lookup that failed ('?') keeps the previous status.
     */
    private function persistDisplayCache(TaskRow $row, Store $store): void
    {
        $task = $row->task;
        $task->displayCache = new DisplayCache(
            trackerStatus: (null !== $task->issue && '?' !== $row->tracker) ? $row->tracker : $task->displayCache->trackerStatus,
            prState: $row->prCell,
            agentCount: (int) $row->agentCell->count,
            agentActivity: $row->agentCell->activity,
            at: $this->time->utcnow(),
        );
        $store->save($task);
    }

    /**
     * Sort key: state rank first, then entry timestamp (stable).
     */
    private function rankEntries(Task $a, Task $b): int
    {
        $cmp = $this->stateRank($a->state) <=> $this->stateRank($b->state);
        if (0 !== $cmp) {
            return $cmp;
        }

        return strcmp($a->stateEnteredAt, $b->stateEnteredAt);
    }

    // ---------------------------------------------------- slack export ----

    /**
     * Tasks currently in $state, across all projects, each enriched with a
     * live-fetched PR. Rows feed renderSlack().
     *
     * @param array<string, ProjectConfig> $projects
     *
     * @return list<SlackItem>
     */
    public function queueTasks(array $projects, Store $store, string $state): array
    {
        $stateEnum = State::tryFrom($state);
        if (null === $stateEnum) {
            throw new PabloError('unknown state '.var_export($state, true).' (expected one of '.json_encode(State::all()).')');
        }
        $rows = [];
        foreach ($store->allTasks() as $task) {
            if ($task->state !== $stateEnum) {
                continue;
            }
            $cfg = $projects[$task->project] ?? null;
            if (null === $cfg) {
                continue;
            }
            $info = null;
            try {
                $info = $this->gh->prForBranch($this->repoSlug->for($cfg), $task->branch);
            } catch (PabloError) {
                // no PR found (or gh failed): the Slack line goes out without one
            }
            if (null !== $info) {
                $pr = new SlackPr($info->number, $info->title, $info->url, $info->isDraft);
            } elseif (null !== $task->prNumber) {
                $slug = $this->repoSlug->for($cfg);
                $pr = new SlackPr(
                    $task->prNumber,
                    null !== $task->issue ? $task->issue->key : ($task->summary ?? ''),
                    "https://github.com/{$slug}/pull/{$task->prNumber}",
                    false,
                );
            } else {
                $pr = null;
            }
            $rows[] = new SlackItem($task->project, $task->branch, $task->issue, $task->summary, $pr);
        }

        return $rows;
    }

    /** @param list<SlackItem> $rows */
    public function renderSlack(array $rows, string $state): string
    {
        $emptyMsg = self::SLACK_EMPTY[$state] ?? 'Nothing to list right now 🎉';
        $groups = [];
        foreach ($rows as $row) {
            $pr = $row->pr;
            if (null === $pr) {
                continue;
            }
            if (null !== $row->issue) {
                $text = "{$pr->url} {$row->issue->key} {$row->issue->title}";
            } else {
                $text = "{$pr->url} #{$pr->number} {$pr->title}";
            }
            $groups[$row->project][] = "• {$text}";
        }

        if ([] === $groups) {
            return $emptyMsg;
        }
        $out = [];
        foreach ($groups as $project => $bullets) {
            $out[] = "*{$project}*\n".implode("\n", $bullets);
        }

        return implode("\n", $out);
    }
}
