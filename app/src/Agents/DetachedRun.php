<?php

declare(strict_types=1);

namespace Pablo\Agents;

/**
 * One live background (headless) run, read from its pidfile: every ADE's
 * fallback, the headless ADE's runs, startup scripts and the rebase
 * conflict resolver Sync launches — the latter without a launch record.
 */
final readonly class DetachedRun
{
    public function __construct(
        public string $handle,
        public string $worktree,
        public string $label,
        public ?string $startedAt,
        public string $ade,
        public ?string $runtime = null,
        public ?string $session = null,
    ) {
    }
}
