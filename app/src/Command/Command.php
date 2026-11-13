<?php

declare(strict_types=1);

namespace Pablo\Command;

use Pablo\Support\PabloError;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Base for every pablo subcommand: a PabloError routes to stderr + non-zero
 * exit. It needs no service: each subcommand injects exactly what it uses
 * through its own constructor (the DI container autowires them); the project
 * helpers live in the UsesProjects trait.
 */
abstract class Command extends SymfonyCommand
{
    /** Where warnings and errors go, so stdout stays parseable. */
    protected function errorOutput(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            return $this->doExecute($input, $output);
        } catch (PabloError $e) {
            $this->errorOutput($output)->writeln('pablo: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    abstract protected function doExecute(InputInterface $input, OutputInterface $output): int;
}
