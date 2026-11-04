<?php

declare(strict_types=1);

namespace Pablo\Tests\Install;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * bin/install.sh run for real in a throwaway HOME, against a copy of the
 * files it links. php, composer and the service managers are fakes: what is
 * under test is the config it records (--runtime / --backend), the links it
 * creates and that running it again changes nothing.
 */
final class InstallScriptTest extends TestCase
{
    use ShellSandbox;

    private string $repo;
    private string $config;

    protected function setUp(): void
    {
        $this->createSandbox();
        $this->repo = $this->sandbox.'/repo';
        $this->config = $this->sandbox.'/home/.pablo/config.yaml';
        $this->createRepoCopy();
        $this->createFakeTools();
    }

    protected function tearDown(): void
    {
        $this->removeSandbox();
    }

    public function testRuntimeAndBackendAreRecordedOnAFirstInstall(): void
    {
        $process = $this->install(['--runtime', 'opencode', '--backend', 'orca']);

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertSame('opencode', $this->configValue('agent_runtime'));
        $this->assertSame('orca', $this->configValue('agent_backend'));
        $this->assertSame($this->exampleModel(), $this->configValue('default_model'));
        $this->assertFileExists($this->sandbox.'/home/.config/opencode/agents/task-analyst.md');
        $this->assertFileExists($this->sandbox.'/home/.local/bin/pablo');
    }

    public function testClaudeCodeGetsAClaudeModelAndItsLinks(): void
    {
        $process = $this->install(['--runtime=claude-code', '--backend=headless']);

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertSame('claude-code', $this->configValue('agent_runtime'));
        $this->assertSame('sonnet', $this->configValue('default_model'));
        $this->assertSame('orca', $this->configValue('agent_backend'), 'headless is Orca falling back by itself');
        $this->assertTrue(is_link($this->sandbox.'/home/.claude/agents/pablo-test.md'));
        $this->assertTrue(is_link($this->sandbox.'/home/.claude/commands/pablo-commit-and-pr.md'));
    }

    public function testCopilotGetsACopilotModelAndItsLinks(): void
    {
        $process = $this->install(['--runtime', 'copilot']);

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertSame('copilot', $this->configValue('agent_runtime'));
        $this->assertSame('claude-sonnet-5.5', $this->configValue('default_model'));
        $this->assertTrue(is_link($this->sandbox.'/home/.copilot/agents/pablo-test.agent.md'));
    }

    public function testSwitchingRuntimeRewritesTheKeysInPlace(): void
    {
        $this->assertSame(0, $this->install(['--runtime', 'claude-code'])->getExitCode());
        $process = $this->install(['--runtime', 'opencode']);

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertSame('opencode', $this->configValue('agent_runtime'));
        $this->assertSame($this->exampleModel(), $this->configValue('default_model'));
        $this->assertSame(1, $this->keyCount('agent_runtime'));
        $this->assertSame(1, $this->keyCount('default_model'));
    }

    public function testChoosingTheSameRuntimeKeepsTheModelYouSet(): void
    {
        $this->assertSame(0, $this->install(['--runtime', 'claude-code'])->getExitCode());
        file_put_contents($this->config, (string) preg_replace('/^default_model:.*$/m', 'default_model: opus', (string) file_get_contents($this->config)));

        $process = $this->install(['--runtime', 'claude-code']);

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertSame('opus', $this->configValue('default_model'));
    }

    public function testWithoutOptionsAnExistingConfigIsLeftAlone(): void
    {
        $this->assertSame(0, $this->install(['--runtime', 'copilot', '--backend', 'orca'])->getExitCode());
        $before = (string) file_get_contents($this->config);

        $process = $this->install([]);

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertSame($before, (string) file_get_contents($this->config));
    }

    public function testRunningTwiceWithTheSameOptionsChangesNothing(): void
    {
        $this->assertSame(0, $this->install(['--runtime', 'claude-code', '--backend', 'orca'])->getExitCode());
        $before = (string) file_get_contents($this->config);

        $process = $this->install(['--runtime', 'claude-code', '--backend', 'orca']);

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertSame($before, (string) file_get_contents($this->config));
    }

    public function testAFirstInstallStillPicksTheToolsItFinds(): void
    {
        $this->fakeTool('claude');
        $this->fakeTool('openchamber');

        $process = $this->install([]);

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertSame('claude-code', $this->configValue('agent_runtime'));
        $this->assertNull($this->configValue('agent_backend'), 'OpenChamber cannot run Claude Code');
    }

    public function testOpenChamberWithAnotherRuntimeIsRefusedBeforeTouchingAnything(): void
    {
        $process = $this->install(['--runtime', 'claude-code', '--backend', 'openchamber']);

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('OpenChamber only works with OpenCode', $this->outputOf($process));
        $this->assertFileDoesNotExist($this->config);
    }

    public function testAKeptOpenChamberBackendBlocksAnotherRuntime(): void
    {
        $this->assertSame(0, $this->install(['--runtime', 'opencode', '--backend', 'openchamber'])->getExitCode());
        $this->assertSame('openchamber', $this->configValue('agent_backend'));

        $process = $this->install(['--runtime', 'copilot']);

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('agent_backend: openchamber only works with OpenCode', $this->outputOf($process));

        $this->assertSame(0, $this->install(['--runtime', 'copilot', '--backend', 'orca'])->getExitCode());
        $this->assertSame('orca', $this->configValue('agent_backend'));
    }

    public function testBadOptionsAreRefused(): void
    {
        foreach ([['--runtime', 'vim'], ['--backend', 'vim'], ['--runtime'], ['--nope']] as $arguments) {
            $process = $this->install($arguments);

            $this->assertNotSame(0, $process->getExitCode(), implode(' ', $arguments));
        }
        $this->assertFileDoesNotExist($this->config);
    }

    public function testTheDashboardServiceIsInstalledUnlessOptedOut(): void
    {
        if ('Linux' !== \PHP_OS_FAMILY) {
            $this->markTestSkipped('systemd units are Linux only');
        }

        $this->assertSame(0, $this->install(['--runtime', 'opencode'])->getExitCode());
        $this->assertStringContainsString('enable --now pablo-web.service', $this->serviceLog());

        unlink($this->sandbox.'/home/service-log');
        $this->assertSame(0, $this->install(['--runtime', 'opencode', '--without-web'])->getExitCode());
        $this->assertStringNotContainsString('enable --now pablo-web.service', $this->serviceLog());
        $this->assertStringContainsString('disable --now pablo-web.service', $this->serviceLog());
    }

    /** @param list<string> $arguments */
    private function install(array $arguments): Process
    {
        return $this->runInSandbox([$this->realTool('bash'), $this->repo.'/bin/install.sh', ...$arguments]);
    }

    private function configValue(string $key): ?string
    {
        foreach (file($this->config, \FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with($line, $key.':')) {
                return trim(substr($line, \strlen($key) + 1));
            }
        }

        return null;
    }

    private function keyCount(string $key): int
    {
        return \count(array_filter(
            file($this->config, \FILE_IGNORE_NEW_LINES) ?: [],
            static fn (string $line): bool => str_starts_with($line, $key.':'),
        ));
    }

    private function exampleModel(): string
    {
        return (string) preg_replace('/^.*^default_model:\s*(\S+).*$/ms', '$1', (string) file_get_contents($this->repo.'/examples/config.yaml'));
    }

    private function serviceLog(): string
    {
        return (string) file_get_contents($this->sandbox.'/home/service-log');
    }

    private function createRepoCopy(): void
    {
        $root = \dirname(__DIR__, 3);
        mkdir($this->repo.'/app/bin', 0o777, true);
        foreach (['bin', 'examples', 'opencode', 'systemd', 'launchd'] as $directory) {
            $process = $this->runInSandbox([$this->sandbox.'/path/cp', '-R', $root.'/'.$directory, $this->repo.'/'.$directory]);
            $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        }
        foreach (['pablo', 'console'] as $entrypoint) {
            file_put_contents($this->repo.'/app/bin/'.$entrypoint, "#!/usr/bin/env php\n");
            chmod($this->repo.'/app/bin/'.$entrypoint, 0o755);
        }
    }

    private function createFakeTools(): void
    {
        // `php -r realpath` is what install.sh resolves links with; the agent
        // generation writes what the real command would, into the repo copy.
        $this->fakeTool('php', <<<'BASH'
            if [ "${1:-}" = "-r" ]; then perl -MCwd=realpath -e 'print realpath($ARGV[0])' "$3"; exit 0; fi
            case "$*" in
            *system:generate-agents*)
                repo="$(cd "$(dirname "$1")/../.." && pwd -P)"
                case "$*" in
                *"--runtime claude-code"*)
                    mkdir -p "$HOME/.pablo/claude" "$repo/claude/agents" "$repo/claude/commands"
                    echo '{}' > "$HOME/.pablo/claude/agents.json"
                    echo agent > "$repo/claude/agents/pablo-test.md"
                    echo command > "$repo/claude/commands/pablo-commit-and-pr.md"
                    ;;
                *"--runtime copilot"*)
                    mkdir -p "$repo/copilot/agents" "$repo/copilot/skills/pablo-task"
                    echo agent > "$repo/copilot/agents/pablo-test.agent.md"
                    echo skill > "$repo/copilot/skills/pablo-task/SKILL.md"
                    ;;
                esac
                ;;
            esac
            exit 0
            BASH);
        $this->fakeTool('composer');
        $this->fakeTool('loginctl');
        $this->fakeTool('systemctl', 'echo "$*" >> "$HOME/service-log"');
        $this->fakeTool('launchctl', 'echo "$*" >> "$HOME/service-log"');
    }
}
