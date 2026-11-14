<?php

declare(strict_types=1);

namespace Pablo\Command\Task;

use Pablo\Agents\AgentLauncherInterface;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Pablo\Yolo\YoloPipeline;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Turn YOLO mode on or off for one task, or hand the choice back to its
 * project's yolo.enabled. Takes effect from the next state agent: a run
 * already going finishes as it started.
 */
#[AsCommand(name: 'task:yolo', description: 'turn YOLO mode on/off for a task (by default the cwd\'s), or back to its project\'s setting')]
final class YoloCommand extends Command
{
    use TargetsTask;

    private const MODES = ['on' => true, 'off' => false, 'project' => null];

    public function __construct(
        protected readonly Store $store,
        protected readonly Config $projectsLoader,
        protected readonly AgentLauncherInterface $agents,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('mode', InputArgument::REQUIRED, 'on, off, or project (follow the project\'s yolo.enabled)', null, array_keys(self::MODES));
        $this->addTaskTarget();
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $mode = (string) $input->getArgument('mode');
        if (!\array_key_exists($mode, self::MODES)) {
            throw new PabloError("unknown mode '{$mode}': use on, off or project");
        }
        $ctx = $this->resolveTargetCtx($input);
        $lock = $this->store->taskLock($ctx->task->project, $ctx->task->branch);
        try {
            $task = $this->store->get($ctx->task->project, $ctx->task->branch) ?? $ctx->task;
            $task->yolo = self::MODES[$mode];
            // A new choice starts a new count of implementation rounds.
            $task->yoloRounds = 0;
            $this->store->save($task);
        } finally {
            $lock->release();
        }
        $on = YoloPipeline::isOn($task, $ctx->cfg);
        $why = null === $task->yolo ? " (project {$ctx->cfg->name}'s yolo.enabled)" : '';
        $output->writeln("{$task->branch}: YOLO mode ".($on ? 'on' : 'off').$why);
        if ($on && null !== $task->yoloStep) {
            $output->writeln("  {$task->yoloStep->value} is running");
        }

        return self::SUCCESS;
    }
}
