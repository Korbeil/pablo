<?php

declare(strict_types=1);

namespace Pablo\Support;

use Pablo\Command\Command;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;

/**
 * Helpers for shelling out to external CLIs (gh/acli/linear/orca) and for
 * parsing ISO-8601 timestamps. Mirrors providers.run_cli / parse_ts.
 */
final class Proc
{
    /** @var callable|null replacement runner (test seam, mirrors monkeypatch) */
    /** @var callable|null */
    private static $runner;

    /** @param callable(array<int, string>, bool, ?float): string $runner */
    public static function setRunner(?callable $runner): void
    {
        self::$runner = $runner;
    }

    /**
     * @param list<string> $argv
     */
    public static function run(array $argv, bool $check = true, ?float $timeout = null): string
    {
        if (null !== self::$runner) {
            return (self::$runner)($argv, $check, $timeout);
        }
        $process = new Process($argv);
        if (null !== $timeout) {
            $process->setTimeout($timeout);
        }
        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new PabloError(\sprintf('%s timed out after %ss: %s', $argv[0], $timeout, implode(' ', $argv)));
        } catch (ProcessRuntimeException $e) {
            // Command could not be executed (typically: binary not installed).
            throw new PabloError(\sprintf('%s is not installed (required for this project\'s provider)', $argv[0]));
        }
        if ($check && !$process->isSuccessful()) {
            $message = '' !== trim($process->getErrorOutput())
                ? trim($process->getErrorOutput())
                : trim($process->getOutput());
            throw new PabloError(\sprintf('%s failed: %s', $argv[0], $message));
        }

        return $process->getOutput();
    }

    /**
     * Parse an ISO-8601 timestamp (accepting a trailing Z) as UTC.
     */
    public static function parseTs(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value);
    }
}
