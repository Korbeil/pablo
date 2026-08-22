<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[\Symfony\Component\Console\Attribute\AsCommand(name: 'project:remove', description: 'remove a project config from ~/.pablo/projects/')]
final class ProjectRemoveCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'project name (the YAML "name" key)');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');
        $projectsDir = $this->projectsLoader->projectsDir();

        // The file name may differ from the project name (any filename is
        // allowed), so resolve via the YAML "name" key like the loader does.
        $path = $this->configPathFor($projectsDir, $name);
        if (null === $path) {
            throw new PabloError("unknown project '{$name}' — see pablo project:list");
        }

        // Deleting a managed project mid-task must be an explicit two-step:
        // close the tasks first, then remove the config.
        $active = $this->store()->allTasks($name);
        if ([] !== $active) {
            $branches = implode(', ', array_map(static fn ($t) => $t->branch, $active));

            throw new PabloError("project '{$name}' still has active task(s): {$branches} — close them first with pablo task:close");
        }

        unlink($path);
        $output->writeln("Removed: {$path}");

        // The provider set may have shrunk — always regenerate so the agent
        // templates stop teaching a removed/downgraded tracker.
        $command = $this->getApplication()?->find('system:generate-agents');
        if (null !== $command) {
            $command->run(new ArrayInput([]), $output);
        }

        $output->writeln('');
        $output->writeln('Task cleanup stays with <comment>pablo task:close</comment> — existing worktrees and ~/.pablo state were left untouched.');

        return self::SUCCESS;
    }

    private function configPathFor(string $projectsDir, string $name): ?string
    {
        foreach (glob(rtrim($projectsDir, '/').'/*.yaml') ?: [] as $candidate) {
            $data = $this->projectsLoader->loadYaml($candidate);
            if (($data['name'] ?? null) === $name) {
                return $candidate;
            }
        }

        return null;
    }
}
