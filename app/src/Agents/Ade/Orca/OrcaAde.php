<?php

declare(strict_types=1);

namespace Pablo\Agents\Ade\Orca;

use Pablo\Agents\Ade\AdeInterface;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Analytics\UsageHarvesterInterface;
use Pablo\Doctor\CliProbe;
use Pablo\Domain\Time;
use Pablo\Support\ProcessRunnerInterface;

/**
 * Orca: each agent runs in an Orca terminal of the task's worktree (the
 * repository must be registered with Orca), headless when Orca refuses. The
 * default ADE; it runs every runtime.
 */
final class OrcaAde implements AdeInterface
{
    public const NAME = 'orca';

    /** The backup file holding `orca repo list --json`. */
    public const BACKUP_FILE = 'orca-repos.json';

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return 'Orca';
    }

    public function launcher(AgentRuntimeInterface $runtime, ProcessRunnerInterface $runner, Time $time): AgentLauncherInterface
    {
        return new OrcaLauncher($runner, $time, runtime: $runtime);
    }

    public function runtimeError(AgentRuntimeInterface $runtime): ?string
    {
        return null;
    }

    public function probe(): CliProbe
    {
        return new CliProbe(
            cli: 'orca',
            argv: ['orca', 'status'],
            hint: 'start the Orca app, or run: orca open',
            // Without Orca, agents fall back to a headless run.
            soft: true,
        );
    }

    /** The prompt travels inside an Orca terminal's command line: kept short. */
    public function takesLaunchContext(): bool
    {
        return false;
    }

    public function usage(ProcessRunnerInterface $runner): ?UsageHarvesterInterface
    {
        return null;
    }

    public function backup(ProcessRunnerInterface $runner): array
    {
        try {
            return [self::BACKUP_FILE => $runner->run(['orca', 'repo', 'list', '--json'], false, 30)];
        } catch (\Throwable) {
            return [];
        }
    }

    public function restore(ProcessRunnerInterface $runner, array $repoPaths): array
    {
        $lines = [];
        foreach ($repoPaths as $repoPath) {
            try {
                $runner->run(['orca', 'repo', 'add', '--path', $repoPath, '--json'], true, 30);
                $lines[] = "  ✔ orca: registered {$repoPath}";
            } catch (\Throwable) {
                $lines[] = "  ⚠ orca: could not register {$repoPath} (Orca may not be running — run `orca repo add --path {$repoPath}` manually)";
            }
        }

        return $lines;
    }

    public function followHint(): string
    {
        return 'check Orca for the new terminal tab';
    }
}
