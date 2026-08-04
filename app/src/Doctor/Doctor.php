<?php

declare(strict_types=1);

namespace Pablo\Doctor;

use Pablo\Config\ProjectConfig;
use Pablo\Provider\Confluence\Confluence;
use Pablo\Provider\Tracker\Jira;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * CLI preflight checks — pablo doctor / /pablo-doctor.
 *
 * Derives the required CLI set from the configured projects. gh is always
 * required; acli (Jira) and a separate acli-confluence probe when a project
 * configures a Confluence space, only for jira projects; linear only when a
 * project uses that provider; orca/opencode always.
 */
final class Doctor
{
    private const CLI_PROBES = [
        'gh' => [['gh', 'auth', 'status'], 'run: gh auth login'],
        'acli' => [['acli', 'jira', 'auth', 'status'], 'run: acli auth login'],
        'acli-confluence' => [['acli', 'confluence', 'auth', 'status'], 'run: acli confluence auth login'],
        'linear' => [['linear', 'auth', 'status'], 'run: linear auth login (schpet/linear-cli)'],
        'orca' => [['orca', 'status'], 'start the Orca app, or run: orca open'],
        'opencode' => [['opencode', '--version'], 'install opencode: https://opencode.ai'],
    ];

    private const ALWAYS_REQUIRED = ['gh', 'opencode', 'orca'];

    private const PROBE_TIMEOUT_S = 30;

    /** @var callable|null test seams (mirror monkeypatching shutil.which / _probe) */
    /** @var callable|null */
    private static $whichSeam;
    /** @var callable|null */
    private static $probeSeam;

    public static function setWhichSeam(?callable $fn): void
    {
        self::$whichSeam = $fn;
    }

    public static function setProbeSeam(?callable $fn): void
    {
        self::$probeSeam = $fn;
    }

    /**
     * @param array<string, ProjectConfig> $projects
     *
     * @return list<string>
     */
    public static function requiredClis(array $projects): array
    {
        $required = array_fill_keys(self::ALWAYS_REQUIRED, true);
        $needsConfluence = false;
        foreach ($projects as $cfg) {
            if ('jira' === $cfg->provider) {
                $required['acli'] = true;
                if (null !== $cfg->confluenceSpace) {
                    $needsConfluence = true;
                }
            } elseif ('linear' === $cfg->provider) {
                $required['linear'] = true;
            }
        }
        if ($needsConfluence) {
            $required['acli-confluence'] = true;
        }
        $list = array_keys($required);
        sort($list);

        return $list;
    }

    /**
     * @param list<string> $argv
     *
     * @return array{0: int, 1: string}
     */
    private static function probe(array $argv): array
    {
        if (null !== self::$probeSeam) {
            return (self::$probeSeam)($argv);
        }
        $process = new Process($argv);
        $process->setTimeout(self::PROBE_TIMEOUT_S);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return [124, 'timed out after '.self::PROBE_TIMEOUT_S.'s'];
        }
        $output = trim(trim($process->getErrorOutput())."\n".trim($process->getOutput()));

        return [$process->getExitCode() ?? -1, $output];
    }

    private static function binaryFor(string $cliName): string
    {
        return self::CLI_PROBES[$cliName][0][0];
    }

    private static function which(string $bin): ?string
    {
        if (null !== self::$whichSeam) {
            return (self::$whichSeam)($bin);
        }

        return (new ExecutableFinder())->find($bin);
    }

    /**
     * @param array<string, ProjectConfig> $projects
     *
     * @return list<CheckResult>
     */
    /** @var callable|null test seam: (array): list<CheckResult> */
    private static $checkAll;

    public static function setCheckAll(?callable $fn): void
    {
        self::$checkAll = $fn;
    }

    /**
     * @param array<string, ProjectConfig> $projects
     *
     * @return list<CheckResult>
     */
    public static function checkAll(array $projects): array
    {
        if (null !== self::$checkAll) {
            return (self::$checkAll)($projects);
        }
        $results = [];
        foreach (self::requiredClis($projects) as $cliName) {
            [$probeArgv, $hint] = self::CLI_PROBES[$cliName];
            if (null === self::which(self::binaryFor($cliName))) {
                $results[] = new CheckResult(
                    cli: $cliName,
                    installed: false,
                    authenticated: false,
                    detail: "{$cliName} is not installed (not on PATH)",
                    hint: $hint,
                );
                continue;
            }
            [$code, $output] = self::probe($probeArgv);
            $lines = preg_split('/\r?\n/', $output) ?: [];
            $results[] = new CheckResult(
                cli: $cliName,
                installed: true,
                authenticated: 0 === $code,
                detail: '' !== $output ? ($lines[0] ?? '') : (0 === $code ? 'ok' : 'failed'),
                hint: 0 !== $code ? $hint : '',
            );
        }

        return $results;
    }

    /** @param array<int, CheckResult> $results */
    public static function render(array $results): string
    {
        $lines = [];
        foreach ($results as $result) {
            $icon = $result->ok() ? '✅' : '❌';
            $lines[] = \sprintf("{$icon} %-10s %s", $result->cli, $result->detail);
            if (!$result->ok() && '' !== $result->hint) {
                $lines[] = "   → {$result->hint}";
            }
        }

        return implode("\n", $lines);
    }
}
