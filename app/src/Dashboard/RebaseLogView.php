<?php

declare(strict_types=1);

namespace Pablo\Dashboard;

use Pablo\Config\ProjectConfig;
use Pablo\Provider\Git\Sync;
use Pablo\Provider\Git\SyncReport;

/**
 * The last sync/rebase session per project, decoded for rendering, plus the
 * last few notable events (conflicts, lease failures, local rewrites left alone, errors) of the earlier runs,
 * read from the project's sync history. Local files only: no CLI call.
 */
final class RebaseLogView
{
    /** Earlier events shown per project. */
    public const EVENTS_SHOWN = 5;

    /** The actions worth keeping visible after a later clean sync. */
    public const NOTABLE_ACTIONS = ['conflict', 'lease-failed', 'local-rewrite', 'error'];

    public function __construct(private readonly Sync $sync)
    {
    }

    /**
     * Presentation per SyncReport action: Bulma colour + Lucide icon, alongside
     * the emoji the terminal already uses (Sync::ACTION_ICONS).
     */
    public const ACTION_STYLE = [
        'up-to-date' => ['is-success', 'lucide:check', 'up to date'],
        'synced' => ['is-success', 'lucide:refresh-cw', 'synced'],
        'would-sync' => ['is-info', 'lucide:refresh-cw', 'would sync'],
        'conflict' => ['is-danger', 'lucide:ban', 'conflict'],
        'lease-failed' => ['is-warning', 'lucide:rotate-ccw', 'lease failed'],
        'dirty' => ['is-warning', 'lucide:file-pen', 'dirty'],
        'local-rewrite' => ['is-warning', 'lucide:pen-line', 'local rewrite'],
        'locked' => ['pablo-chip-muted', 'lucide:lock', 'locked'],
        'skipped' => ['pablo-chip-muted', 'lucide:git-pull-request-closed', 'skipped'],
        'error' => ['is-danger', 'lucide:circle-x', 'error'],
        'unregistered' => ['pablo-chip-muted', 'lucide:circle-alert', 'unregistered'],
    ];

    /**
     * @param array<string, ProjectConfig> $projects
     *
     * @return list<RebaseLogSection>
     */
    public function forProjects(array $projects): array
    {
        $logs = [];
        foreach ($projects as $cfg) {
            $section = $this->forProject($cfg->name);
            if (null !== $section) {
                $logs[] = $section;
            }
        }
        usort($logs, static fn (RebaseLogSection $a, RebaseLogSection $b) => ($b->log->timestamp ?? '') <=> ($a->log->timestamp ?? ''));

        return $logs;
    }

    public function forProject(string $project): ?RebaseLogSection
    {
        $log = $this->sync->loadLastLog($project);
        if (null === $log) {
            return null;
        }

        $rows = [];
        foreach ($log->reports as $report) {
            $rows[] = $this->row($report);
        }

        $events = [];
        foreach ($this->sync->history($project, null, self::NOTABLE_ACTIONS) as $run) {
            if ($run->log->timestamp === $log->timestamp) {
                continue; // the last run, already shown above
            }
            foreach ($run->log->reports as $report) {
                $events[] = new RebaseLogEvent($run->log->timestamp, $run->apply, $this->row($report));
                if (\count($events) >= self::EVENTS_SHOWN) {
                    break 2;
                }
            }
        }

        return new RebaseLogSection($log, $rows, $events);
    }

    private function row(SyncReport $report): RebaseLogRow
    {
        [$color, $icon, $label] = self::ACTION_STYLE[$report->action] ?? ['pablo-chip-muted', 'lucide:circle-alert', $report->action];

        return new RebaseLogRow(
            report: $report,
            color: $color,
            icon: $icon,
            label: $label,
            emoji: Sync::ACTION_ICONS[$report->action] ?? '•',
        );
    }
}
