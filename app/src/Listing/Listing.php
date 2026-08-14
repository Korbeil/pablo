<?php

declare(strict_types=1);

namespace Pablo\Listing;

use Pablo\Agents\AgentLauncherInterface;
use Pablo\Agents\SessionInfo;
use Pablo\Config\ProjectConfig;
use Pablo\Dispatch\Dispatch;
use Pablo\Domain\AgentActivity;
use Pablo\Domain\DisplayCache;
use Pablo\Domain\Issue;
use Pablo\Domain\PrBadge;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Domain\Time;
use Pablo\Provider\Gh\GhPr;
use Pablo\Provider\Gh\PrInfo;
use Pablo\Provider\Git\GitRepo;
use Pablo\Provider\Tracker\ProviderRegistry;
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
    private const TASKS_SORT_ORDER = [
        State::TestingFailed,
        State::NeedsTesting,
        State::RequestChanges,
        State::WaitingReview,
        State::ReadyToReview,
        State::CiRed,
        State::Draft,
        State::Waiting,
        State::InProgress,
    ];

    // Only these states qualify for the "💭 Waiting for feedback" section.
    private const WAITING_FEEDBACK_STATES = [
        State::InProgress,
        State::CiRed,
        State::RequestChanges,
        State::TestingFailed,
    ];

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
     * is what surfaces an agent Orca has already evicted from its list.
     *
     * Note it deliberately excludes needs-testing and waiting-review: those
     * mean the ball is with QA/reviewers, not with you.
     */
    public static function isWaitingForFeedback(Task $task, ?AgentActivity $agents = null): bool
    {
        return \in_array($task->state, self::WAITING_FEEDBACK_STATES, true)
            && ($task->hasFinishedAgent() || (null !== $agents && $agents->isWaiting()));
    }

    private const SLACK_EMPTY = [
        State::WaitingReview->value => 'No PRs waiting for review right now 🎉',
        State::NeedsTesting->value => 'Nothing needs testing right now 🎉',
    ];

    private const TASKS_HEADERS = ['Project', 'Task', 'State', 'Agents', 'Activity', 'Issue', 'Tracker', 'PR', 'Since'];

    // ------------------------------------------------------- ordering ----

    public static function stateRank(State $state): int
    {
        $i = array_search($state, self::TASKS_SORT_ORDER, true);

        return false === $i ? \count(self::TASKS_SORT_ORDER) : $i;
    }

    public static function timeSince(string $isoTimestamp): string
    {
        try {
            $dt = new \DateTimeImmutable($isoTimestamp);
        } catch (\Throwable) {
            return '?';
        }
        $seconds = (int) ((new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp() - $dt->getTimestamp());
        if ($seconds < 60) {
            return 'just now';
        }
        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return "{$minutes}m ago";
        }
        $hours = intdiv($minutes, 60);
        if ($hours < 24) {
            return "{$hours}h ago";
        }

        return intdiv($hours, 24).'d ago';
    }

    /** @param array<string, ProjectConfig> $projects */
    public static function lastPollHeader(array $projects): string
    {
        $latest = null;
        foreach ($projects as $name => $_) {
            $stamp = Dispatch::readStamp($name, 'poll');
            if (null === $stamp) {
                continue;
            }
            $dt = (new \DateTimeImmutable('@'.(int) $stamp['ran_at']))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            if (null === $latest || $dt > $latest) {
                $latest = $dt;
            }
        }
        if (null === $latest) {
            return '';
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));
        $seconds = (int) ($now->getTimestamp() - $latest->getTimestamp());
        if ($seconds < 60) {
            $ago = 'just now';
        } elseif ($seconds < 3600) {
            $ago = intdiv($seconds, 60).'m ago';
        } elseif ($seconds < 86400) {
            $ago = intdiv($seconds, 3600).'h ago';
        } else {
            $ago = intdiv($seconds, 86400).'d ago';
        }

        return 'Poller last ran: '.$ago.' ('.$latest->format('H:i').')';
    }

    /**
     * @param array<int, string>             $headers
     * @param array<int, array<int, string>> $rows
     */
    private static function table(array $headers, array $rows): string
    {
        $output = new BufferedOutput();
        $table = new Table($output);
        $table->setHeaders($headers);
        $table->setRows($rows);
        $table->render();

        return $output->fetch();
    }

    /** Terminal display-column width of $s (emoji = 2, ASCII = 1). */
    public static function displayWidth(string $s): int
    {
        $w = 0;
        $len = mb_strlen($s, 'UTF-8');
        for ($i = 0; $i < $len; ++$i) {
            $w += mb_strwidth(mb_substr($s, $i, 1, 'UTF-8'), 'UTF-8');
        }

        return $w;
    }

    public static function padRight(string $s, int $width): string
    {
        return $s.str_repeat(' ', max(0, $width - self::displayWidth($s)));
    }

    /**
     * @param array<int, string>             $headers
     * @param array<int, array<int, string>> $rows
     * @param array<int, int>|null           $widths
     */
    public static function render(array $headers, array $rows, ?array $widths = null): string
    {
        if (null === $widths) {
            $widths = [];
            foreach ($headers as $i => $h) {
                $w = self::displayWidth($h);
                foreach ($rows as $row) {
                    $w = max($w, self::displayWidth($row[$i] ?? ''));
                }
                $widths[] = $w;
            }
        }
        $lines = [];
        $lines[] = implode('  ', array_map(static fn ($i) => self::padRight($headers[$i], $widths[$i]), array_keys($headers)));
        $lines[] = implode('  ', array_map(static fn ($w) => str_repeat('-', $w), $widths));
        foreach ($rows as $row) {
            $buf = [];
            foreach (array_keys($headers) as $i) {
                $buf[] = self::padRight($row[$i] ?? '', $widths[$i]);
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
    private static function branchCandidates(string $base, array $names): array
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

    public static function issuesTable(ProjectConfig $cfg, Store $store): string
    {
        $provider = ProviderRegistry::get($cfg->provider);
        $issues = $provider->listAssigned($cfg);
        $names = GitRepo::allBranchNames($cfg->repoPath);
        $slug = RepoSlug::for($cfg);

        $rows = [];
        foreach ($issues as $issue) {
            $base = 'github' === $cfg->provider
                ? strtolower($cfg->projectKey).'-'.strtolower($issue->key)
                : strtolower($issue->key);
            $branches = self::branchCandidates($base, $names);

            $prCell = '-';
            foreach ($branches as $branch) {
                $pr = GhPr::prForBranch($slug, $branch);
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

        return self::table(['Issue', 'Title', 'Status', 'PR', 'Branch'], $rows);
    }

    // ---------------------------------------------------- tasks table ----

    private static function stateCell(Task $task): string
    {
        $def = StateMachine::states()[$task->state->value] ?? null;
        if (null === $def) {
            return $task->state->value;
        }

        return "{$def['emoji']} {$def['label']}";
    }

    private static function issueCell(Task $task): string
    {
        if (null !== $task->issue) {
            return "{$task->issue->key} {$task->issue->title}";
        }

        return $task->summary ?? '-';
    }

    public static function prStateCell(Task $task, ?PrInfo $pr): string
    {
        return PrBadge::fromTask($task, $pr)->render();
    }

    /**
     * @param array<int, SessionInfo> $sessions
     *
     * @return array{0: int, 1: string}
     */
    public static function agentActivitySummary(array $sessions): array
    {
        $activity = AgentActivity::fromSessions($sessions);

        return [$activity->total, $activity->render()];
    }

    /**
     * @param array<string, string>|null $prefetched key => status map from batch lookup
     */
    private static function remoteStatusCell(Task $task, ProjectConfig $cfg, bool $live, ?array $prefetched = null): string
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
            return ProviderRegistry::get($cfg->provider)->issueStatus($task->issue->key, $cfg);
        } catch (PabloError) {
            return '?';
        }
    }

    /** @param array<string, PrInfo>|null $prefetched */
    private static function prCell(Task $task, ProjectConfig $cfg, bool $live, ?array $prefetched): string
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
            return self::prStateCell($task, $prefetched[$task->branch] ?? null);
        }
        try {
            $pr = GhPr::prForBranch(RepoSlug::for($cfg), $task->branch);
        } catch (PabloError) {
            return "#{$task->prNumber}";
        }

        return self::prStateCell($task, $pr);
    }

    /**
     * @param array<int, SessionInfo>|null $sessions
     *
     * @return array{0: string, 1: string}
     */
    private static function agentCells(Task $task, bool $live, AgentLauncherInterface $agents, ?array $sessions): array
    {
        if (!$live && null !== $task->displayCache->agentCount) {
            return [(string) $task->displayCache->agentCount, $task->displayCache->agentActivity ?? '-'];
        }
        if (null === $sessions) {
            $sessions = $agents->activeSessions($task->worktreePath);
        }
        [$count, $activity] = self::agentActivitySummary($sessions);

        return [(string) $count, $activity];
    }

    /**
     * Tasks split into two sections, each sorted by state (ties keep store
     * insertion order — stable). Waiting-feedback section = states in
     * WAITING_FEEDBACK_STATES whose activity cell contains "💭".
     *
     * @param array<string, ProjectConfig> $projects
     */
    public static function tasksTable(array $projects, Store $store, AgentLauncherInterface $agents, bool $live = false, bool $refresh = false): string
    {
        $fetchLive = $live || $refresh;
        $tasks = array_values(array_filter($store->allTasks(), static fn (Task $t) => isset($projects[$t->project])));

        $needsAgentCheck = array_values(array_filter(
            $tasks,
            static fn (Task $t) => $fetchLive || null === $t->displayCache->agentCount,
        ));
        $sessionsByWorktree = [] !== $needsAgentCheck
            ? $agents->bulkDisplaySessions(array_map(static fn (Task $t) => $t->worktreePath, $needsAgentCheck))
            : [];

        $needsTracker = array_values(array_filter(
            $tasks,
            static fn (Task $t) => null !== $t->issue && ($fetchLive || null === $t->displayCache->trackerStatus),
        ));
        $trackerStatuses = [];
        if ([] !== $needsTracker) {
            $byProvider = [];
            foreach ($needsTracker as $task) {
                $cfg = $projects[$task->project];
                $issue = $task->issue;
                if (null === $issue) {
                    continue;
                }
                $byProvider[$cfg->provider][] = [$issue->key, $cfg];
            }
            foreach ($byProvider as $providerName => $pairs) {
                try {
                    $trackerStatuses[$providerName] = ProviderRegistry::get($providerName)->batchIssueStatus($pairs);
                } catch (PabloError) {
                    $trackerStatuses[$providerName] = [];
                }
            }
        }

        $needsPrCheck = array_values(array_filter(
            $tasks,
            static fn (Task $t) => !$t->merged && null !== $t->prNumber && ($fetchLive || null === $t->displayCache->prState),
        ));
        $repoBranches = [];
        $seen = [];
        foreach ($needsPrCheck as $task) {
            $slug = RepoSlug::for($projects[$task->project]);
            if (!isset($seen[$slug])) {
                $seen[$slug] = [];
            }
            $seen[$slug][] = $task->branch;
        }
        foreach ($seen as $slug => $branches) {
            $repoBranches[] = [$slug, $branches];
        }
        $prsByRepo = [] !== $repoBranches
            ? GhPr::prsForBranchesBulk($repoBranches)
            : [];

        $entries = [];
        foreach ($tasks as $task) {
            $cfg = $projects[$task->project];
            $tracker = self::remoteStatusCell($task, $cfg, $fetchLive, $trackerStatuses[$cfg->provider] ?? null);
            $slug = RepoSlug::for($cfg);
            $pr = self::prCell($task, $cfg, $fetchLive, $prsByRepo[$slug] ?? null);
            [$count, $activity] = self::agentCells(
                $task,
                $fetchLive,
                $agents,
                $sessionsByWorktree[$task->worktreePath] ?? null,
            );
            if ($refresh) {
                $task->displayCache = new DisplayCache(
                    trackerStatus: (null !== $task->issue && '?' !== $tracker) ? $tracker : $task->displayCache->trackerStatus,
                    prState: $pr,
                    agentCount: (int) $count,
                    agentActivity: $activity,
                    at: Time::utcnow(),
                );
                $store->save($task);
            }
            $entries[] = [$task, [
                $task->project,
                $task->branch,
                self::stateCell($task),
                $count,
                $activity,
                self::issueCell($task),
                $tracker,
                $pr,
                self::timeSince($task->stateEnteredAt),
            ]];
        }

        if ([] === $entries) {
            return 'no active tasks';
        }

        $split = static function (array $e) {
            return self::isWaitingForFeedback(
                $e[0],
                AgentActivity::fromDisplay((int) $e[1][3], $e[1][4]),
            );
        };
        $waitingEntries = array_values(array_filter($entries, $split));
        $restEntries = array_values(array_filter($entries, static fn ($e) => !$split($e)));

        $rank = static function (array $a, array $b): int {
            $cmp = self::stateRank($a[0]->state) <=> self::stateRank($b[0]->state);
            if (0 !== $cmp) {
                return $cmp;
            }

            return strcmp($a[0]->stateEnteredAt, $b[0]->stateEnteredAt);
        };
        usort($waitingEntries, $rank);
        usort($restEntries, $rank);

        $sections = [];
        if ([] !== $waitingEntries) {
            $rows = array_map(static fn ($e) => $e[1], $waitingEntries);
            $sections[] = "💭 Waiting for feedback\n\n".self::table(self::TASKS_HEADERS, $rows);
        }
        if ([] !== $restEntries) {
            $rows = array_map(static fn ($e) => $e[1], $restEntries);
            $header = [] !== $waitingEntries ? "Other tasks\n\n" : '';
            $sections[] = $header.self::table(self::TASKS_HEADERS, $rows);
        }
        $pollHeader = self::lastPollHeader($projects);
        if ('' !== $pollHeader) {
            array_unshift($sections, $pollHeader);
        }

        return implode("\n\n", $sections);
    }

    // ---------------------------------------------------- slack export ----

    /**
     * Tasks currently in $state, across all projects, each enriched with a
     * live-fetched PR. Rows feed renderSlack().
     *
     * @param array<string, ProjectConfig> $projects
     *
     * @return array<int, array<string, mixed>>
     */
    public static function queueTasks(array $projects, Store $store, string $state): array
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
                $info = GhPr::prForBranch(RepoSlug::for($cfg), $task->branch);
            } catch (PabloError) {
                // ignore
            }
            if (null !== $info) {
                $pr = [
                    'number' => $info->number,
                    'title' => $info->title,
                    'url' => $info->url,
                    'is_draft' => $info->isDraft,
                ];
            } elseif (null !== $task->prNumber) {
                $slug = RepoSlug::for($cfg);
                $pr = [
                    'number' => $task->prNumber,
                    'title' => null !== $task->issue ? $task->issue->key : ($task->summary ?? ''),
                    'url' => "https://github.com/{$slug}/pull/{$task->prNumber}",
                    'is_draft' => false,
                ];
            } else {
                $pr = null;
            }
            $rows[] = [
                'project' => $task->project,
                'branch' => $task->branch,
                'issue' => null !== $task->issue ? $task->issue->toJson() : null,
                'summary' => $task->summary,
                'pr' => $pr,
            ];
        }

        return $rows;
    }

    /** @param array<int, array<string, mixed>> $rows */
    public static function renderSlack(array $rows, string $state): string
    {
        $emptyMsg = self::SLACK_EMPTY[$state] ?? 'Nothing to list right now 🎉';
        $groups = [];
        foreach ($rows as $row) {
            $pr = $row['pr'] ?? null;
            if (null === $pr) {
                continue;
            }
            $issue = $row['issue'] ?? null;
            if (null !== $issue) {
                $text = "{$pr['url']} {$issue['key']} {$issue['title']}";
            } else {
                $text = "{$pr['url']} #{$pr['number']} {$pr['title']}";
            }
            $groups[$row['project']][] = "• {$text}";
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
