<?php

declare(strict_types=1);

namespace Pablo\Support;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs external CLIs (gh/acli/linear/orca) via Symfony Process. Injected as
 * ProcessRunnerInterface; tests provide fakes instead of shelling out.
 */
final class ProcessRunner implements ProcessRunnerInterface
{
    /**
     * Environment to launch an external CLI with.
     *
     * acli (Atlassian CLI) reads a secret from the Secret Service keyring on
     * startup; when the login keyring is locked it blocks on an unanswered
     * unlock prompt and every invocation times out. Pointing
     * DBUS_SESSION_BUS_ADDRESS at a non-existent socket makes its go-keyring
     * fall back to the file-based provider instead. Other CLIs need the
     * inherited session bus, so only override it for acli.
     *
     * @param list<string> $argv
     *
     * @return array<string, string>
     */
    private static function envFor(array $argv): array
    {
        if ('acli' !== ($argv[0] ?? '')) {
            return [];
        }

        return ['DBUS_SESSION_BUS_ADDRESS' => 'unix:path='.sys_get_temp_dir().'/pablo-nokeyring'];
    }

    public function run(array $argv, bool $check = true, ?float $timeout = null): string
    {
        $process = new Process($argv, env: self::envFor($argv));
        if (null !== $timeout) {
            $process->setTimeout($timeout);
        }
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new PabloError(\sprintf('%s timed out after %ss: %s', $argv[0], $timeout, implode(' ', $argv)));
        } catch (ProcessRuntimeException) {
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

    public function runParallel(array $commandGroups, bool $check = true, ?float $timeout = null): array
    {
        if ([] === $commandGroups) {
            return [];
        }

        $processes = [];
        foreach ($commandGroups as $i => $argv) {
            $p = new Process($argv, env: self::envFor($argv));
            if (null !== $timeout) {
                $p->setTimeout($timeout);
            }
            $p->start();
            $processes[$i] = $p;
        }

        $results = [];
        foreach ($processes as $i => $p) {
            try {
                $p->wait();
            } catch (ProcessTimedOutException) {
                if ($check) {
                    throw new PabloError(\sprintf('%s timed out after %ss: %s', $commandGroups[$i][0], $timeout, implode(' ', $commandGroups[$i])));
                }
                $results[$i] = '';

                continue;
            } catch (ProcessRuntimeException) {
                if ($check) {
                    throw new PabloError(\sprintf('%s is not installed (required for this project\'s provider)', $commandGroups[$i][0]));
                }
                $results[$i] = '';

                continue;
            }

            if ($check && !$p->isSuccessful()) {
                $message = '' !== trim($p->getErrorOutput())
                    ? trim($p->getErrorOutput())
                    : trim($p->getOutput());
                throw new PabloError(\sprintf('%s failed: %s', $commandGroups[$i][0], $message));
            }
            $results[$i] = $p->getOutput();
        }

        ksort($results);

        return array_values($results);
    }

    public function probe(array $argv, ?float $timeout = null): ProbeResult
    {
        $process = new Process($argv, env: self::envFor($argv));
        if (null !== $timeout) {
            $process->setTimeout($timeout);
        }
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new ProbeResult(124, 'timed out after '.$timeout.'s');
        }
        $output = trim(trim($process->getErrorOutput())."\n".trim($process->getOutput()));

        return new ProbeResult($process->getExitCode() ?? -1, $output);
    }
}
