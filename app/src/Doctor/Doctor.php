<?php

declare(strict_types=1);

namespace Pablo\Doctor;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\Runtime\RuntimeProbe;
use Pablo\Config\ProjectConfig;
use Pablo\Support\PabloError;
use Pablo\Support\ProcessRunnerInterface;
use Symfony\Component\Process\ExecutableFinder;

/**
 * CLI preflight checks — pablo system:doctor / /pablo-doctor.
 *
 * Derives the required CLI set from the configured projects. gh is always
 * required; acli (Jira) and a separate acli-confluence probe when a project
 * configures a Confluence space, only for jira projects; linear only when a
 * project uses that provider; the selected agent runtime's CLI (per
 * agent_runtime, described by its RuntimeProbe); orca or openchamber,
 * whichever the global agent_backend selects (orca by default).
 */
final class Doctor
{
    private const CLI_PROBES = [
        'gh' => [['gh', 'auth', 'status'], 'run: gh auth login'],
        'acli' => [['acli', 'jira', 'auth', 'status'], 'run: acli auth login'],
        'acli-confluence' => [['acli', 'confluence', 'auth', 'status'], 'run: acli confluence auth login'],
        'linear' => [['linear', 'auth', 'status'], 'run: linear auth login (schpet/linear-cli)'],
        'orca' => [['orca', 'status'], 'start the Orca app, or run: orca open'],
        'openchamber' => [['openchamber', 'status'], 'install openchamber: https://github.com/openchamber/openchamber'],
    ];

    private const ALWAYS_REQUIRED = ['gh'];

    /**
     * Wizard ordering: hard requirements first (the runtimes' CLIs go right
     * after orca), then the per-provider trackers (only included when a
     * project uses them).
     */
    private const CLI_ORDER_BEFORE_RUNTIMES = ['gh', 'orca'];
    private const CLI_ORDER_AFTER_RUNTIMES = ['openchamber', 'acli', 'acli-confluence', 'linear'];

    /** @var array<string, array<string, string>> platform => install command */
    private const INSTALL_COMMANDS = [
        'gh' => [
            'Darwin' => 'brew install gh',
            'Linux' => 'sudo apt-get install gh',
        ],
        'openchamber' => [
            'Darwin' => 'curl -fsSL https://raw.githubusercontent.com/openchamber/openchamber/main/scripts/install.sh | bash',
            'Linux' => 'curl -fsSL https://raw.githubusercontent.com/openchamber/openchamber/main/scripts/install.sh | bash',
        ],
        'acli' => [
            'Darwin' => 'brew tap atlassian-labs/acli && brew install acli',
            'Linux' => 'brew tap atlassian-labs/acli && brew install acli',
        ],
        'acli-confluence' => [
            'Darwin' => 'brew tap atlassian-labs/acli && brew install acli',
            'Linux' => 'brew tap atlassian-labs/acli && brew install acli',
        ],
        'linear' => [
            'Darwin' => 'brew install schpet/tap/linear',
            'Linux' => 'deno install -A --reload -f -g -n linear jsr:@schpet/linear-cli',
        ],
    ];

    private const DOCS_URLS = [
        'gh' => 'https://cli.github.com',
        'openchamber' => 'https://github.com/openchamber/openchamber',
        'acli' => 'https://developer.atlassian.com/cloud/acli/',
        'acli-confluence' => 'https://developer.atlassian.com/cloud/acli/',
        'linear' => 'https://github.com/schpet/linear-cli',
    ];

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
        // Only the selected backend and runtime are required: an OpenChamber
        // user need not install Orca, nor anyone the CLI of a runtime they do not use.
        $required['openchamber' === $this->agentLaunchers->resolveBackend() ? 'openchamber' : 'orca'] = true;
        $required[$this->agentLaunchers->runtimes()->resolveOrDefault()->probe()->cli] = true;
        $list = array_keys($required);
        sort($list);

        return $list;
    }

    /**
     * Global agent settings that cannot work at all: an unknown
     * agent_runtime, or a backend that cannot run the runtime (see
     * AgentRuntimeInterface::backendError()). Always a hard failure — for the
     * doctor and the dispatcher.
     *
     * @return list<string>
     */
    public function configErrors(): array
    {
        try {
            $runtime = $this->agentLaunchers->runtime();
        } catch (PabloError $e) {
            return [$e->getMessage()];
        }
        $error = $runtime->backendError($this->agentLaunchers->resolveBackend());

        return null !== $error ? [$error] : [];
    }

    /** @param list<string> $errors */
    public function renderConfigErrors(array $errors): string
    {
        return implode("\n", array_map(static fn (string $e): string => \sprintf('❌ %-10s %s', 'config', $e), $errors));
    }

    /** @return list<string> */
    public function cliOrder(): array
    {
        $runtimes = array_map(static fn (RuntimeProbe $probe): string => $probe->cli, $this->runtimeProbes());

        return [...self::CLI_ORDER_BEFORE_RUNTIMES, ...array_values($runtimes), ...self::CLI_ORDER_AFTER_RUNTIMES];
    }

    /** @return array<string, RuntimeProbe> every runtime's probe, keyed by CLI id */
    private function runtimeProbes(): array
    {
        $probes = [];
        foreach ($this->agentLaunchers->runtimes()->all() as $runtime) {
            $probe = $runtime->probe();
            $probes[$probe->cli] = $probe;
        }

        return $probes;
    }

    /** @return array{0: list<string>, 1: string} probe argv and failure hint */
    private function probeFor(string $cli): array
    {
        $runtime = $this->runtimeProbes()[$cli] ?? null;
        if (null !== $runtime) {
            return [$runtime->argv, $runtime->hint];
        }
        if (!isset(self::CLI_PROBES[$cli])) {
            throw new PabloError("unknown CLI '{$cli}'");
        }

        return self::CLI_PROBES[$cli];
    }

    public function installCommand(string $cli): ?string
    {
        $runtime = $this->runtimeProbes()[$cli] ?? null;
        $perOs = null !== $runtime ? $runtime->installCommands : (self::INSTALL_COMMANDS[$cli] ?? null);
        if (null === $perOs) {
            return null;
        }

        return $perOs[\PHP_OS_FAMILY] ?? null;
    }

    public function docsUrl(string $cli): ?string
    {
        $runtime = $this->runtimeProbes()[$cli] ?? null;

        return null !== $runtime ? $runtime->docsUrl : (self::DOCS_URLS[$cli] ?? null);
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
            [$probeArgv, $hint] = $this->probeFor($cliName);
            if (null === $this->which($probeArgv[0])) {
                $results[] = new CheckResult(
                    cli: $cliName,
                    installed: false,
                    authenticated: false,
                    detail: "{$cliName} is not installed (not on PATH)",
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
