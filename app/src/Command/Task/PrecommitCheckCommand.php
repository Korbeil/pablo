<?php

declare(strict_types=1);

namespace Pablo\Command\Task;

use Pablo\Command\Command;
use Pablo\StateMachine\StateMachine;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PrecommitCheckCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('task:precommit-check')
            ->setDescription('check /commit-and-pr is allowed here')
            ->addOption('json', null, InputOption::VALUE_NONE, 'emit JSON (accepted for back-compat)');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->store();
        try {
            $ctx = $this->resolveCtx($store, $this->agents());
        } catch (PabloError $e) {
            $err = $output instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface
                ? $output->getErrorOutput()
                : $output;
            $err->writeln('pablo: '.$e->getMessage());

            return self::INVALID;
        }
        $payload = [
            'project' => $ctx->task->project,
            'branch' => $ctx->task->branch,
            'state' => $ctx->task->state->value,
            'allowed' => \in_array($ctx->task->state, StateMachine::COMMIT_ALLOWED_FROM, true),
        ];
        $output->writeln(json_encode($payload, \JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
