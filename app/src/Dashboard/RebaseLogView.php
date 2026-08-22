<?php

declare(strict_types=1);

namespace Pablo\Dashboard;

use Pablo\Config\ProjectConfig;
use Pablo\Provider\Git\Sync;

/**
 * The last sync/rebase session per project, decoded for rendering.
 *
 * Sync writes one file per project and overwrites it on every run, so there is
 * no history — only "what the last sync did".
 */
final class RebaseLogView
{
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
        'locked' => ['pablo-chip-muted', 'lucide:lock', 'locked'],
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
            [$color, $icon, $label] = self::ACTION_STYLE[$report->action] ?? ['pablo-chip-muted', 'lucide:circle-alert', $report->action];
            $rows[] = new RebaseLogRow(
                report: $report,
                color: $color,
                icon: $icon,
                label: $label,
                emoji: Sync::ACTION_ICONS[$report->action] ?? '•',
            );
        }

        return new RebaseLogSection($log, $rows);
    }
}
