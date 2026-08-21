<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\System;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Stand-in for system:generate-agents: records that a command wired to
 * regenerate agents actually invoked it, without touching the repo's
 * opencode/agents directory.
 */
final class FakeGenerateAgentsCommand extends Command
{
    public int $runs = 0;

    protected function configure(): void
    {
        $this->setName('system:generate-agents');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ++$this->runs;
        $output->writeln('(generate-agents ran)');

        return self::SUCCESS;
    }
}
