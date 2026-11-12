<?php

declare(strict_types=1);

namespace Pablo\Command;

use Pablo\Support\PabloError;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The one machine-readable output convention of the read commands:
 * `--format=table|json` (default table). JSON goes to stdout and nothing
 * else does — anything a script should not parse goes to stderr
 * (errorOutput()). A command that already had a `--json` flag keeps it as an
 * alias of `--format=json`.
 */
trait FormatsOutput
{
    public const FORMATS = ['table', 'json'];

    private const JSON_FLAGS = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR;

    protected function addFormatOption(): static
    {
        return $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'output format: table or json', 'table', self::FORMATS);
    }

    /** Validates --format (and honours a legacy --json flag): true when JSON is asked for. */
    protected function wantsJson(InputInterface $input): bool
    {
        if ($input->hasOption('json') && true === $input->getOption('json')) {
            return true;
        }
        $format = (string) $input->getOption('format');
        if (!\in_array($format, self::FORMATS, true)) {
            throw new PabloError(\sprintf("invalid --format '%s'; expected one of: %s", $format, implode(', ', self::FORMATS)));
        }

        return 'json' === $format;
    }

    protected function writeJson(OutputInterface $output, mixed $payload): void
    {
        $output->writeln(json_encode($payload, self::JSON_FLAGS), OutputInterface::OUTPUT_RAW);
    }

    /** Where warnings go, so stdout stays parseable JSON. */
    protected function errorOutput(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }
}
