<?php

declare(strict_types=1);

namespace Pablo\Command\Task;

use Pablo\Command\Command;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'task:info', description: 'task record utilities')]
final class TaskCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('current', InputArgument::REQUIRED, 'task record verb (current)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'emit JSON (accepted for back-compat)');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        if ('current' !== (string) $input->getArgument('current')) {
            throw new PabloError('usage: pablo task:info current');
        }
        $store = $this->store();
        $ctx = $this->resolveCtx();
        $payload = $ctx->task->toJson();
        $payload['repo_path'] = $ctx->cfg->repoPath;
        $payload['primary_branch'] = $ctx->cfg->primaryBranch;
        $output->writeln(json_encode(
            $payload,
            \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        ));

        return self::SUCCESS;
    }
}
