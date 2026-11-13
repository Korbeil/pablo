<?php

declare(strict_types=1);

namespace Pablo\Command\Task;

use Pablo\Agents\AgentLauncherInterface;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\StateMachine\StateMachine;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'task:precommit-check', description: 'check /pablo-commit-and-pr is allowed here')]
final class PrecommitCheckCommand extends Command
{
    use TargetsTask;

    public function __construct(
        protected readonly Store $store,
        protected readonly Config $projectsLoader,
        protected readonly AgentLauncherInterface $agents,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addTaskTarget()
            ->addOption('json', null, InputOption::VALUE_NONE, 'emit JSON (accepted for back-compat)');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $ctx = $this->resolveTargetCtx($input);
        } catch (PabloError $e) {
            $this->errorOutput($output)->writeln('pablo: '.$e->getMessage());

            return self::INVALID;
        }
        $payload = [
            'project' => $ctx->task->project,
            'branch' => $ctx->task->branch,
            'state' => $ctx->task->state->value,
            'allowed' => \in_array($ctx->task->state, StateMachine::COMMIT_ALLOWED_FROM, true),
            'pr_description_locale' => $ctx->cfg->prDescriptionLocale,
        ];
        $output->writeln(json_encode($payload, \JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
