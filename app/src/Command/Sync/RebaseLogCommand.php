<?php

declare(strict_types=1);

namespace Pablo\Command\Sync;

use Pablo\Agents\Runtime\SessionRef;
use Pablo\Command\Command;
use Pablo\Command\FormatsOutput;
use Pablo\Command\UsesProjects;
use Pablo\Config\Config;
use Pablo\Provider\Git\Sync;
use Pablo\Provider\Git\SyncReport;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sync:log', description: 'show the last sync/rebase session log')]
final class RebaseLogCommand extends Command
{
    use FormatsOutput;
    use UsesProjects;

    public function __construct(
        private readonly Sync $sync,
        protected readonly Store $store,
        protected readonly Config $projectsLoader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addProjectArgument()
            ->addOption('history', null, InputOption::VALUE_NONE, 'show past sync runs (newest first) instead of the last one')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'with --history: runs shown per project', (string) self::HISTORY_LIMIT)
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'with --history: only these actions, comma-separated (e.g. conflict,lease-failed)')
            ->addFormatOption();
    }

    public const HISTORY_LIMIT = 20;

    /** @param list<string> $names */
    private function writeHistory(InputInterface $input, OutputInterface $output, array $names, bool $json): int
    {
        $limit = filter_var($input->getOption('limit'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (false === $limit) {
            throw new PabloError('--limit must be a positive integer');
        }
        $only = array_values(array_filter(array_map(trim(...), explode(',', (string) $input->getOption('only'))), static fn (string $a): bool => '' !== $a));
        $unknown = array_diff($only, array_keys(Sync::ACTION_ICONS));
        if ([] !== $unknown) {
            throw new PabloError('unknown action(s) for --only: '.implode(', ', $unknown).'; known: '.implode(', ', array_keys(Sync::ACTION_ICONS)));
        }

        $runs = [];
        $found = false;
        foreach ($names as $name) {
            foreach ($this->sync->history($name, $limit, $only) as $run) {
                $found = true;
                $log = $run->log;
                if ($json) {
                    $runs[] = [
                        'project' => $name,
                        'timestamp' => $log->timestamp,
                        'strategy' => $log->strategy,
                        'apply' => $run->apply,
                        'reports' => array_map(static fn (SyncReport $r): array => $r->toArray(), $log->reports),
                    ];
                    continue;
                }
                $output->writeln("# {$name} — ".($log->timestamp ?? '?').' ('.$log->strategy.', '.($run->apply ? 'applied' : 'dry run').')');
                $output->writeln(Sync::renderReports($log->reports));
            }
        }
        if ($json) {
            $this->writeJson($output, ['runs' => $runs]);
        } elseif (!$found) {
            $output->writeln([] !== $only ? 'no matching sync runs in the history' : 'no sync history found — run `pablo sync:run` first');
        }

        return self::SUCCESS;
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $json = $this->wantsJson($input);
        $names = array_keys($this->selectedProjects($input, $output));
        sort($names);
        if ($input->getOption('history')) {
            return $this->writeHistory($input, $output, $names, $json);
        }

        if ($json) {
            $logs = [];
            foreach ($names as $name) {
                $log = $this->sync->loadLastLog($name);
                if (null === $log) {
                    continue;
                }
                $logs[] = [
                    'project' => $name,
                    'timestamp' => $log->timestamp,
                    'strategy' => $log->strategy,
                    'reports' => array_map(static fn (SyncReport $r): array => $r->toArray(), $log->reports),
                ];
            }
            $this->writeJson($output, ['logs' => $logs]);

            return self::SUCCESS;
        }

        $found = false;
        foreach ($names as $name) {
            $log = $this->sync->loadLastLog($name);
            if (null === $log) {
                continue;
            }
            $found = true;
            $output->writeln("# {$name} — ".($log->timestamp ?? '?').' ('.$log->strategy.')');
            foreach ($log->reports as $r) {
                $icon = Sync::ACTION_ICONS[$r->action] ?? '•';
                $line = "{$icon} ".str_pad($r->branch, 24)." {$r->action}";
                if (\in_array($r->action, ['would-sync', 'synced'], true) && ($r->behind || $r->ahead)) {
                    $line .= " (behind {$r->behind}, ahead {$r->ahead})";
                }
                if ('' !== $r->detail && 'conflict' !== $r->action) {
                    $line .= ' — '.$r->detail;
                }
                $output->writeln($line);
                if ('conflict' === $r->action) {
                    $files = $r->conflictFiles;
                    if ([] !== $files) {
                        $output->writeln('   conflicting files: '.implode(', ', $files));
                    }
                    if ('' !== $r->agentHandle) {
                        $output->writeln('   fix agent running — '.SessionRef::command($r->agentHandle));
                    }
                }
            }
        }
        if (!$found) {
            $output->writeln('no rebase logs found — run `pablo sync:run` first');
        }

        return self::SUCCESS;
    }
}
