<?php

declare(strict_types=1);

namespace Pablo\Command\Sync;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Provider\Git\Sync;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sync:log', description: 'show the last sync/rebase session log')]
final class RebaseLogCommand extends Command
{
    public function __construct(
        private readonly Sync $sync,
        Store $store,
        Config $projectsLoader,
        AgentLauncherFactory $agentLaunchers,
        AgentLauncherInterface $agents,
    ) {
        parent::__construct($store, $projectsLoader, $agentLaunchers, $agents);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('project', InputArgument::OPTIONAL, 'limit to one project');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $projects = $this->projects();
        $project = $input->getArgument('project') ?: null;
        if (null !== $project && !isset($projects[$project])) {
            throw new PabloError("unknown project '{$project}'; configured projects: ".implode(', ', array_keys($projects)));
        }
        $names = null !== $project ? [$project] : array_keys($projects);
        sort($names);

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
                        $output->writeln('   fix agent running — opencode -s '.$r->agentHandle);
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
