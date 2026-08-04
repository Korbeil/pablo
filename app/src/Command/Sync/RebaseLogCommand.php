<?php

declare(strict_types=1);

namespace Pablo\Command\Sync;

use Pablo\Command\Command;
use Pablo\Provider\Git\Sync;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RebaseLogCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('rebase-log')
            ->setDescription('show the last sync/rebase session log')
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
            $log = Sync::loadLastLog($name);
            if (null === $log) {
                continue;
            }
            $found = true;
            $output->writeln("# {$name} — {$log['timestamp']} (".($log['strategy'] ?? 'unknown').')');
            foreach ($log['reports'] ?? [] as $r) {
                $icon = Sync::ACTION_ICONS[$r['action']] ?? '•';
                $line = "{$icon} ".str_pad((string) $r['branch'], 24)." {$r['action']}";
                $behind = $r['behind'] ?? 0;
                $ahead = $r['ahead'] ?? 0;
                if (\in_array($r['action'], ['would-sync', 'synced'], true) && ($behind || $ahead)) {
                    $line .= " (behind {$behind}, ahead {$ahead})";
                }
                if (!empty($r['detail']) && 'conflict' !== $r['action']) {
                    $line .= ' — '.$r['detail'];
                }
                if ('conflict' === $r['action'] && !empty($r['agent_handle'])) {
                    $line .= ' (agent: '.$r['agent_handle'].')';
                }
                $output->writeln($line);
                if ('conflict' === $r['action']) {
                    $files = $r['conflict_files'] ?? [];
                    if ([] !== $files) {
                        $output->writeln('   conflicting files: '.implode(', ', $files));
                    }
                    if (!empty($r['agent_handle'])) {
                        $output->writeln('   fix agent running — attach with opencode -s <session> to inspect');
                    }
                }
            }
        }
        if (!$found) {
            $output->writeln('no rebase logs found — run `pablo sync` first');
        }

        return self::SUCCESS;
    }
}
