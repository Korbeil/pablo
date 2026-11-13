<?php

declare(strict_types=1);

namespace Pablo\Doctor;

use Pablo\Agents\Ade\Detached;
use Pablo\Agents\AgentLauncherFactory;
use Pablo\Config\ProjectConfig;
use Pablo\Support\PabloError;
use Pablo\Support\PabloPaths;
use Pablo\Support\ProcessRunnerInterface;
use Symfony\Component\Process\ExecutableFinder;

/**
 * CLI preflight checks — pablo system:doctor / /pablo-doctor.
 *
 * Derives the required CLI set from the configured projects. gh is always
 * required; acli (Jira) and a separate acli-confluence probe when a project
 * configures a Confluence space, only for jira projects; linear only when a
 * project uses that provider; the selected agent runtime's CLI and the
 * selected ADE's (if it needs one), each described by its own CliProbe.
 */
final class Doctor
{
    private const ALWAYS_REQUIRED = ['gh'];

    private const PROBE_TIMEOUT_S = 30;

    /** @var callable|null PATH-lookup override fn(string): ?string */
    private $which;

    public function __construct(
        private readonly ProcessRunnerInterface $runner,
        private readonly AgentLauncherFactory $agentLaunchers,
        ?callable $which = null,
    ) {
        $this->which = $which;
    }

    /**
     * @param array<string, ProjectConfig> $projects
     *
     * @return list<string>
     */
    public function requiredClis(array $projects): array
    {
        $required = array_fill_keys(self::ALWAYS_REQUIRED, true);
        $needsConfluence = false;
        foreach ($projects as $cfg) {
            if ('jira' === $cfg->tracker->provider) {
                $required['acli'] = true;
                if (null !== $cfg->confluenceSpace) {
                    $needsConfluence = true;
                }
            } elseif ('linear' === $cfg->tracker->provider) {
                $required['linear'] = true;
            }
        }
        if ($needsConfluence) {
            $required['acli-confluence'] = true;
        }
        // Only the selected ADE and runtime are required: nobody needs the CLI
        // of an ADE or a runtime they do not use.
        $adeProbe = $this->agentLaunchers->ades()->resolveOrDefault()->probe();
        if (null !== $adeProbe) {
            $required[$adeProbe->cli] = true;
        }
        $required[$this->agentLaunchers->runtimes()->resolveOrDefault()->probe()->cli] = true;
        $list = array_keys($required);
        sort($list);

        return $list;
    }

    /**
     * Global agent settings that cannot work at all: an unknown
     * agent_runtime or agent_backend, or an ADE that cannot run the runtime
     * (see AdeInterface::runtimeError()). Always a hard failure — for the
     * doctor and the dispatcher.
     *
     * @return list<string>
     */
    public function configErrors(): array
    {
        try {
            $runtime = $this->agentLaunchers->runtime();
            $ade = $this->agentLaunchers->ade();
        } catch (PabloError $e) {
            return [$e->getMessage()];
        }
        $error = $ade->runtimeError($runtime);

        return null !== $error ? [$error] : [];
    }

    /**
     * A warning when no program on PATH can put a background run in a session
     * of its own (setsid, or perl on macOS — see Detached): agents still run,
     * but stay in the dispatcher's process group, so stopping it can stop
     * them too. Soft: never fails the doctor.
     */
    public function detachWarning(): ?string
    {
        if (Detached::canStartSession(fn (string $bin): ?string => $this->which($bin))) {
            return null;
        }

        return \sprintf('⚠️  %-10s neither %s is on PATH: background agents are not detached into their own session', 'detach', implode(' nor ', Detached::SESSION_HELPERS))
            ."\n   → install perl (or util-linux's setsid)";
    }

    /** @param list<string> $errors */
    public function renderConfigErrors(array $errors): string
    {
        return implode("\n", array_map(static fn (string $e): string => \sprintf('❌ %-10s %s', 'config', $e), $errors));
    }

    /**
     * The tracker CLIs. Soft: a dead acli/linear only degrades the projects
     * using that provider, it never blocks a dispatch run.
     *
     * @return array<string, CliProbe>
     */
    private static function trackerProbes(): array
    {
        $acli = [
            'Darwin' => 'brew tap atlassian-labs/acli && brew install acli',
            'Linux' => 'brew tap atlassian-labs/acli && brew install acli',
        ];
        $acliDocs = 'https://developer.atlassian.com/cloud/acli/';

        return [
            'acli' => new CliProbe('acli', ['acli', 'jira', 'auth', 'status'], 'run: acli auth login', $acli, $acliDocs, soft: true),
            'acli-confluence' => new CliProbe('acli-confluence', ['acli', 'confluence', 'auth', 'status'], 'run: acli confluence auth login', $acli, $acliDocs, soft: true),
            'linear' => new CliProbe(
                'linear',
                ['linear', 'auth', 'status'],
                'run: linear auth login (schpet/linear-cli)',
                [
                    'Darwin' => 'brew install schpet/tap/linear',
                    'Linux' => 'deno install -A --reload -f -g -n linear jsr:@schpet/linear-cli',
                ],
                'https://github.com/schpet/linear-cli',
                soft: true,
            ),
        ];
    }

    /**
     * Every CLI doctor knows, keyed by CLI id: gh, every ADE's then every
     * runtime's probe, then the trackers'. Built per call (the ADE and
     * runtime probes depend on the runtime environment).
     *
     * @return array<string, CliProbe>
     */
    private function probes(): array
    {
        $probes = ['gh' => new CliProbe(
            'gh',
            ['gh', 'auth', 'status'],
            'run: gh auth login',
            ['Darwin' => 'brew install gh', 'Linux' => 'sudo apt-get install gh'],
            'https://cli.github.com',
        )];
        foreach ($this->agentLaunchers->ades()->all() as $ade) {
            $probe = $ade->probe();
            if (null !== $probe) {
                $probes[$probe->cli] = $probe;
            }
        }
        foreach ($this->agentLaunchers->runtimes()->all() as $runtime) {
            $probe = $runtime->probe();
            $probes[$probe->cli] = $probe;
        }

        return $probes + self::trackerProbes();
    }

    /**
     * Wizard ordering: hard requirements first (gh, then the ADEs' and the
     * runtimes' CLIs), then the per-provider trackers (only included when a
     * project uses them).
     *
     * @return list<string>
     */
    public function cliOrder(): array
    {
        return array_keys($this->probes());
    }

    /**
     * Whether a failing $cli only degrades (agents fall back to a headless
     * run, a tracker only fails its own projects), never blocks a dispatch run.
     */
    public function isSoft(string $cli): bool
    {
        return $this->probes()[$cli]->soft ?? false;
    }

    private function probeFor(string $cli): CliProbe
    {
        return $this->probes()[$cli] ?? throw new PabloError("unknown CLI '{$cli}'");
    }

    public function installCommand(string $cli): ?string
    {
        return $this->probes()[$cli]->installCommands[\PHP_OS_FAMILY] ?? null;
    }

    public function docsUrl(string $cli): ?string
    {
        return $this->probes()[$cli]->docsUrl ?? null;
    }

    private function which(string $bin): ?string
    {
        if (null !== $this->which) {
            return ($this->which)($bin);
        }

        return (new ExecutableFinder())->find($bin);
    }

    /**
     * @param array<string, ProjectConfig> $projects
     *
     * @return list<CheckResult>
     */
    public function checkAll(array $projects): array
    {
        return $this->checkIds($this->requiredClis($projects));
    }

    /**
     * @param list<string> $cliIds
     *
     * @return list<CheckResult>
     */
    public function checkIds(array $cliIds): array
    {
        $results = [];
        foreach ($cliIds as $cliName) {
            $cli = $this->probeFor($cliName);
            [$probeArgv, $hint] = [$cli->argv, $cli->hint];
            $path = $this->which($probeArgv[0]);
            if (null === $path) {
                $results[] = new CheckResult(
                    cli: $cliName,
                    installed: false,
                    authenticated: false,
                    detail: "{$cliName} is not installed (not on PATH)",
                    hint: $hint,
                );
                continue;
            }
            // Another program under the same name (e.g. a desktop app): never run it.
            $refusal = null !== $cli->refuse ? ($cli->refuse)($path) : null;
            if (null !== $refusal) {
                $results[] = new CheckResult(
                    cli: $cliName,
                    installed: true,
                    authenticated: false,
                    detail: $refusal,
                    hint: $hint,
                );
                continue;
            }
            $probe = $this->runner->probe($probeArgv, self::PROBE_TIMEOUT_S);
            $lines = preg_split('/\r?\n/', $probe->output) ?: [];
            $results[] = new CheckResult(
                cli: $cliName,
                installed: true,
                authenticated: 0 === $probe->exitCode,
                detail: '' !== $probe->output ? ($lines[0] ?? '') : (0 === $probe->exitCode ? 'ok' : 'failed'),
                hint: 0 !== $probe->exitCode ? $hint : '',
            );
        }

        return $results;
    }

    /**
     * Soft warnings about the user's hook command (hooks.command): the
     * executable it starts with cannot be found. Never a failure — a hook
     * is the user's own business and never blocks PABLO.
     *
     * @param array<string, ProjectConfig> $projects
     *
     * @return list<string>
     */
    public function hookWarnings(array $projects): array
    {
        $byCommand = [];
        foreach ($projects as $cfg) {
            if (null !== $cfg->hooks->command) {
                $byCommand[$cfg->hooks->command][] = $cfg->name;
            }
        }
        $warnings = [];
        foreach ($byCommand as $command => $names) {
            $bin = self::hookExecutable((string) $command);
            if (null === $bin) {
                continue; // starts with shell syntax: nothing to look up
            }
            $found = str_contains($bin, '/') ? is_file($bin) && is_executable($bin) : null !== $this->which($bin);
            if (!$found) {
                $warnings[] = \sprintf("hooks.command: '%s' not found (projects: %s)", $bin, implode(', ', $names));
            }
        }

        return $warnings;
    }

    /** @param list<string> $warnings */
    public function renderHookWarnings(array $warnings): string
    {
        return implode("\n", array_map(static fn (string $w): string => \sprintf('⚠️  %-9s %s', 'hooks', $w), $warnings));
    }

    /**
     * The executable a hook command starts with (leading VAR=value
     * assignments skipped, quotes stripped, ~/ expanded), or null when it
     * starts with shell syntax or a builtin there is nothing to look up for.
     */
    public static function hookExecutable(string $command): ?string
    {
        $tokens = preg_split('/\s+/', trim($command)) ?: [];
        foreach ($tokens as $token) {
            if (1 === preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $token)) {
                continue;
            }
            $token = trim($token, '"\'');
            if ('' === $token || 1 === preg_match('/^(if|for|while|until|case|function|\{|\(|\[\[?|!|exec|command|builtin|source|\.|eval|test|echo|printf|cd|true|false|:)$/', $token)
                || str_contains($token, '$') || str_contains($token, '`')) {
                return null;
            }
            if (str_starts_with($token, '~/')) {
                $token = PabloPaths::home().substr($token, 1);
            }

            return $token;
        }

        return null;
    }

    /** @param array<int, CheckResult> $results */
    public function render(array $results): string
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
