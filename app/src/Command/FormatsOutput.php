<?php

declare(strict_types=1);

namespace Pablo\Command;

use Pablo\Support\PabloError;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The one machine-readable output convention of the read commands:
 * `--format=table|json` (default table), plus whatever extra format a
 * command declares (`stats` adds csv). JSON goes to stdout and nothing
 * else does — anything a script should not parse goes to stderr
 * (Command::errorOutput()). A command that already had a `--json` flag
 * keeps it as an alias of `--format=json`.
 */
trait FormatsOutput
{
    public const FORMATS = ['table', 'json'];

    private const JSON_FLAGS = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR;

    /** @param list<string> $extraFormats accepted on top of FORMATS */
    protected function addFormatOption(array $extraFormats = [], string $description = 'output format: table or json'): static
    {
        return $this->addOption('format', null, InputOption::VALUE_REQUIRED, $description, 'table', [...self::FORMATS, ...$extraFormats]);
    }

    /**
     * Validates --format against FORMATS + $extraFormats and returns it; a
     * legacy --json flag reads as "json".
     *
     * @param list<string> $extraFormats
     */
    protected function outputFormat(InputInterface $input, array $extraFormats = []): string
    {
        if ($input->hasOption('json') && true === $input->getOption('json')) {
            return 'json';
        }
        $formats = [...self::FORMATS, ...$extraFormats];
        $format = (string) $input->getOption('format');
        if (!\in_array($format, $formats, true)) {
            throw new PabloError(\sprintf("invalid --format '%s'; expected one of: %s", $format, implode(', ', $formats)));
        }

        return $format;
    }

    /** Validates --format (and honours a legacy --json flag): true when JSON is asked for. */
    protected function wantsJson(InputInterface $input): bool
    {
        return 'json' === $this->outputFormat($input);
    }

    protected function writeJson(OutputInterface $output, mixed $payload): void
    {
        $output->writeln(json_encode($payload, self::JSON_FLAGS), OutputInterface::OUTPUT_RAW);
    }
}
