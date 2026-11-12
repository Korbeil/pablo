<?php

declare(strict_types=1);

namespace Pablo\Command\Report;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Command\Command;
use Pablo\Command\FormatsOutput;
use Pablo\Completion\Suggestions;
use Pablo\Config\Config;
use Pablo\Config\ProjectConfig;
use Pablo\Domain\State;
use Pablo\Listing\Listing;
use Pablo\Listing\TaskRow;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'task:list', description: 'active task worktrees and their states', aliases: ['tasks'])]
final class TasksCommand extends Command
{
    use FormatsOutput;

    public function __construct(
        private readonly Listing $listing,
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
            ->addOption('live', null, InputOption::VALUE_NONE, 'fetch fresh Tracker/PR/Agents data for display only')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'fetch fresh data and persist it as the new cache before rendering')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'only this project\'s tasks', null, $this->suggestions()->projectCompleter())
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'only tasks of this project type (work/open-source/personal)', null, Config::PROJECT_TYPES)
            ->addOption('state', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'only tasks in this state (repeatable)', [], Suggestions::states())
            ->addFormatOption();
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $projects = $this->filterProjects(
            $this->projects(),
            (string) $input->getOption('project'),
            (string) $input->getOption('type'),
        );
        $states = $this->parseStates((array) $input->getOption('state'));

        if ($this->wantsJson($input)) {
            $rows = $this->listing->taskRows(
                $projects,
                $this->store(),
                $this->agents(),
                (bool) $input->getOption('live'),
                (bool) $input->getOption('refresh'),
                $states,
            );
            $this->writeJson($output, ['tasks' => array_map(static fn (TaskRow $r): array => $r->toArray(), $rows)]);

            return self::SUCCESS;
        }

        $render = $this->listing->tasksTable(
            $projects,
            $this->store(),
            $this->agents(),
            (bool) $input->getOption('live'),
            (bool) $input->getOption('refresh'),
            $states,
        );
        $output->writeln($render);
        $this->warnProjectErrors($output);

        return self::SUCCESS;
    }

    /**
     * --project / --type narrow the project set the listing walks: a task
     * whose project is filtered out is never fetched nor rendered.
     *
     * @param array<string, ProjectConfig> $projects
     *
     * @return array<string, ProjectConfig>
     */
    private function filterProjects(array $projects, string $project, string $type): array
    {
        if ('' !== $type && !\in_array($type, Config::PROJECT_TYPES, true)) {
            throw new PabloError(\sprintf("invalid --type '%s'; expected one of: %s", $type, implode(', ', Config::PROJECT_TYPES)));
        }
        if ('' !== $project) {
            if (!isset($projects[$project])) {
                $known = array_keys($projects);
                sort($known);

                throw new PabloError(\sprintf("unknown --project '%s'; configured: %s", $project, [] === $known ? '(none)' : implode(', ', $known)));
            }
            $projects = [$project => $projects[$project]];
        }
        if ('' !== $type) {
            $projects = array_filter($projects, static fn (ProjectConfig $cfg): bool => $cfg->type === $type);
        }

        return $projects;
    }

    /**
     * @param array<mixed> $raw
     *
     * @return list<State>
     */
    private function parseStates(array $raw): array
    {
        $states = [];
        foreach ($raw as $value) {
            $state = State::tryFrom((string) $value);
            if (null === $state) {
                throw new PabloError(\sprintf("invalid --state '%s'; expected one of: %s", (string) $value, implode(', ', array_map(static fn (State $s): string => $s->value, State::cases()))));
            }
            $states[] = $state;
        }

        return $states;
    }
}
