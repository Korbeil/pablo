<?php

declare(strict_types=1);

namespace Pablo\Doctor;

use Pablo\Agents\AgentLauncherFactory;
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
 * project uses that provider; the selected agent runtime's CLI (opencode or
 * claude or copilot, per agent_runtime); orca or openchamber, whichever the global
 * agent_backend selects (orca by default).
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
        'claude' => [['claude', 'auth', 'status', '--text'], 'run: claude auth login'],
        // Copilot has no non-interactive login check: --version only proves
        // the install; `copilot login` is documented as the next step.
        'copilot' => [['copilot', '--version'], 'reinstall: npm install -g @github/copilot, then run: copilot login'],
        'openchamber' => [['openchamber', 'status'], 'install openchamber: https://github.com/openchamber/openchamber'],
    ];

    private const ALWAYS_REQUIRED = ['gh'];

    /**
     * Wizard ordering: hard requirements first, then the per-provider
     * trackers (only included when a project uses them).
     */
    public const CLI_ORDER = ['gh', 'orca', 'opencode', 'claude', 'copilot', 'openchamber', 'acli', 'acli-confluence', 'linear'];

    /** @var array<string, array<string, string>> platform => install command */
    private const INSTALL_COMMANDS = [
        'gh' => [
            'Darwin' => 'brew install gh',
            'Linux' => 'sudo apt-get install gh',
        ],
        'opencode' => [
            'Darwin' => 'curl -fsSL https://opencode.ai/install | bash',
            'Linux' => 'curl -fsSL https://opencode.ai/install | bash',
        ],
        'claude' => [
            'Darwin' => 'curl -fsSL https://claude.ai/install.sh | bash',
            'Linux' => 'curl -fsSL https://claude.ai/install.sh | bash',
        ],
        'copilot' => [
            'Darwin' => 'npm install -g @github/copilot',
            'Linux' => 'npm install -g @github/copilot',
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
        'opencode' => 'https://opencode.ai',
        'claude' => 'https://code.claude.com/docs',
        'copilot' => 'https://docs.github.com/en/copilot/how-tos/copilot-cli',
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
        // user need not install Orca, a Claude Code or Copilot user need not install OpenCode.
        $required['openchamber' === $this->agentLaunchers->resolveBackend() ? 'openchamber' : 'orca'] = true;
        $required[$this->runtimeBinary()] = true;
        $list = array_keys($required);
        sort($list);

        return $list;
    }

    /**
     * The selected runtime's CLI. An unknown runtime is reported by
     * configErrors(); the CLI checks fall back to OpenCode meanwhile.
     */
    private function runtimeBinary(): string
    {
        try {
            return $this->agentLaunchers->runtime()->binary();
        } catch (PabloError) {
            return 'opencode';
        }
    }

    /**
     * Global agent settings that cannot work at all: an unknown
     * agent_runtime, or a backend that cannot run the runtime (OpenChamber +
     * anything but OpenCode). Always a hard failure — for the doctor and the dispatcher.
     *
     * @return list<string>
     */
    public function configErrors(): array
    {
        try {
            $runtime = $this->agentLaunchers->runtimes()->resolveName();
        } catch (PabloError $e) {
            return [$e->getMessage()];
        }
        $error = AgentLauncherFactory::incompatibility($this->agentLaunchers->resolveBackend(), $runtime);

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
        return self::CLI_ORDER;
    }

    public function installCommand(string $cli): ?string
    {
        $perOs = self::INSTALL_COMMANDS[$cli] ?? null;
        if (null === $perOs) {
            return null;
        }

        return $perOs[\PHP_OS_FAMILY] ?? null;
    }

    public function docsUrl(string $cli): ?string
    {
        return self::DOCS_URLS[$cli] ?? null;
    }

    private function binaryFor(string $cliName): string
    {
        return self::CLI_PROBES[$cliName][0][0];
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
            [$probeArgv, $hint] = self::CLI_PROBES[$cliName];
            if (null === $this->which(self::binaryFor($cliName))) {
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
