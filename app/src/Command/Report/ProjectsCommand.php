<?php

declare(strict_types=1);

namespace Pablo\Command\Report;

use Pablo\Command\Command;
use Pablo\Command\FormatsOutput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'project:list', description: 'list configured projects')]
final class ProjectsCommand extends Command
{
    use FormatsOutput;

    protected function configure(): void
    {
        $this->addFormatOption();
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $json = $this->wantsJson($input);
        $projects = $this->projects();
        $this->warnProjectErrors($output);
        ksort($projects);
        if ($json) {
            $list = [];
            foreach ($projects as $name => $cfg) {
                $list[] = [
                    'name' => $name,
                    'type' => $cfg->type,
                    'provider' => $cfg->provider,
                    'project_key' => $cfg->projectKey,
                    'repo_path' => $cfg->repoPath,
                    'primary_branch' => $cfg->primaryBranch,
                    'worktrees_root' => $cfg->worktreesRoot,
                ];
            }
            $this->writeJson($output, ['projects' => $list]);

            return self::SUCCESS;
        }
        foreach ($projects as $name => $cfg) {
            $output->writeln("{$name}\t{$cfg->type}\t{$cfg->provider}\t{$cfg->repoPath}");
        }

        return self::SUCCESS;
    }
}
