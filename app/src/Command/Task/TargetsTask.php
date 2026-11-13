<?php

declare(strict_types=1);

namespace Pablo\Command\Task;

use Pablo\Command\Command;
use Pablo\Domain\Task;
use Pablo\StateMachine\TaskCtx;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * The one way a task command picks its task: an optional `[<branch>]`
 * argument (always the last positional one) + `--project` to disambiguate it,
 * or `--worktree <path>`, falling back to the task owning the cwd when
 * neither is given.
 *
 * Used by every `task:*` command that acts on an existing task, so they all
 * target the same way (see site/content/cli.html "Choosing the task").
 *
 * @phpstan-require-extends Command
 */
trait TargetsTask
{
    /**
     * Shell completion offers the recorded task branches (narrowed by a
     * --project already typed) plus $extraBranches, and the configured
     * projects for --project.
     *
     * @param list<string> $extraBranches extra values completed for <branch> (e.g. task:info's `current`)
     */
    protected function addTaskTarget(array $extraBranches = []): static
    {
        $branches = $this->suggestions()->branchCompleter();

        return $this
            ->addArgument(
                'branch',
                InputArgument::OPTIONAL,
                'task branch to act on instead of the cwd\'s task',
                null,
                [] === $extraBranches ? $branches : static fn (CompletionInput $input): array => [...$extraBranches, ...$branches($input)],
            )
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'project owning <branch> (needed only when several projects have a task with that branch)', null, $this->suggestions()->projectCompleter())
            ->addOption('worktree', null, InputOption::VALUE_REQUIRED, 'task worktree path to act on instead of the cwd');
    }

    protected function resolveTargetCtx(InputInterface $input): TaskCtx
    {
        $branch = (string) $input->getArgument('branch');
        $project = (string) $input->getOption('project');
        $worktree = (string) $input->getOption('worktree');

        if ('' !== $worktree) {
            if ('' !== $branch || '' !== $project) {
                throw new PabloError('--worktree cannot be combined with <branch> or --project: pick one way to name the task');
            }

            return $this->resolveCtx($worktree);
        }
        if ('' === $branch) {
            if ('' !== $project) {
                throw new PabloError('--project needs a branch: pablo '.$this->getName().' <branch> --project <name>');
            }

            return $this->resolveCtx();
        }

        $task = $this->findTaskByBranch($branch, $project);
        $cfg = $this->projects()[$task->project] ?? null;
        if (null === $cfg) {
            throw new PabloError("task {$task->branch} belongs to project '{$task->project}', which has no config under projects/ anymore");
        }

        return new TaskCtx(task: $task, cfg: $cfg, store: $this->store(), agents: $this->agents());
    }

    private function findTaskByBranch(string $branch, string $project): Task
    {
        if ('' !== $project) {
            $task = $this->store()->get($project, $branch);
            if (null === $task) {
                throw new PabloError("no PABLO task '{$branch}' for project '{$project}'");
            }

            return $task;
        }
        $matches = array_values(array_filter(
            $this->store()->allTasks(),
            static fn (Task $t): bool => $t->branch === $branch,
        ));
        if ([] === $matches) {
            throw new PabloError("no PABLO task named '{$branch}'");
        }
        if (\count($matches) > 1) {
            $projects = array_map(static fn (Task $t): string => $t->project, $matches);
            sort($projects);

            throw new PabloError(\sprintf("'%s' exists in several projects (%s) — add --project <name>", $branch, implode(', ', $projects)));
        }

        return $matches[0];
    }
}
