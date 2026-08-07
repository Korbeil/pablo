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
     * @return list<array{project: string, timestamp: ?string, strategy: string, reports: list<array<string, mixed>>}>
     */
    public function forProjects(array $projects): array
    {
        $logs = [];
        foreach ($projects as $cfg) {
            $log = $this->forProject($cfg->name);
            if (null !== $log) {
                $logs[] = $log;
            }
        }
        usort($logs, static fn (array $a, array $b) => ($b['timestamp'] ?? '') <=> ($a['timestamp'] ?? ''));

        return $logs;
    }

    /**
     * @return array{project: string, timestamp: ?string, strategy: string, reports: list<array<string, mixed>>}|null
     */
    public function forProject(string $project): ?array
    {
        $raw = Sync::loadLastLog($project);
        if (null === $raw) {
            return null;
        }

        $reports = [];
        /** @var array<int, array<string, mixed>> $rawReports */
        $rawReports = \is_array($raw['reports'] ?? null) ? $raw['reports'] : [];
        foreach ($rawReports as $report) {
            $action = \is_string($report['action'] ?? null) ? $report['action'] : 'unknown';
            [$color, $icon, $label] = self::ACTION_STYLE[$action] ?? ['pablo-chip-muted', 'lucide:circle-alert', $action];

            /** @var list<string> $conflictFiles */
            $conflictFiles = \is_array($report['conflict_files'] ?? null) ? array_values($report['conflict_files']) : [];

            $reports[] = [
                'branch' => (string) ($report['branch'] ?? '?'),
                'action' => $action,
                'label' => $label,
                'emoji' => Sync::ACTION_ICONS[$action] ?? '•',
                'color' => $color,
                'icon' => $icon,
                'behind' => (int) ($report['behind'] ?? 0),
                'ahead' => (int) ($report['ahead'] ?? 0),
                'conflict_files' => $conflictFiles,
                'detail' => (string) ($report['detail'] ?? ''),
                'agent_handle' => (string) ($report['agent_handle'] ?? ''),
            ];
        }

        return [
            'project' => (string) ($raw['project'] ?? $project),
            'timestamp' => \is_string($raw['timestamp'] ?? null) ? $raw['timestamp'] : null,
            'strategy' => (string) ($raw['strategy'] ?? '?'),
            'reports' => $reports,
        ];
    }
}
