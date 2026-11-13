<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents\Runtime;

use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Agents\Runtime\AgentRuntimeRegistry;
use Pablo\Agents\Runtime\SessionRef;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Doctor\AgentFileState;
use Pablo\Install\Links;
use Pablo\Support\Files;
use Pablo\Tests\SandboxedEnv;
use Pablo\Tests\TempDir;
use Pablo\Tests\UsesGlobalConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What every registered runtime must honour, so shared code can rely on the
 * interfaces alone.
 */
final class RuntimeContractTest extends TestCase
{
    use SandboxedEnv;
    use UsesGlobalConfig;

    private const ENV = ['HOME', 'PABLO_RUNTIME_DIR', 'PABLO_CLAUDE_DIR', 'CLAUDE_CONFIG_DIR', 'COPILOT_HOME', 'PABLO_AGENT_RUNTIME'];

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = TempDir::create('runtime-contract');
        mkdir($this->tmp.'/home', 0o777, true);
        foreach (self::ENV as $name) {
            $this->setEnv($name, null);
        }
        $this->setEnv('HOME', $this->tmp.'/home');
        $this->setEnv('PABLO_RUNTIME_DIR', $this->tmp.'/runtime');
        $this->writeGlobalConfig("{}\n");
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
        $this->unsetGlobalConfig();
        Files::remove($this->tmp);
    }

    /** @return iterable<string, array{string}> */
    public static function runtimes(): iterable
    {
        foreach (AgentRuntimeRegistry::NAMES as $name) {
            yield $name => [$name];
        }
    }

    private function runtime(string $name): AgentRuntimeInterface
    {
        return (new AgentRuntimeRegistry(new GlobalConfig()))->resolve($name);
    }

    #[DataProvider('runtimes')]
    public function testIdentityAndProbeAgree(string $name): void
    {
        $runtime = $this->runtime($name);
        $this->assertSame($name, $runtime->name());
        $this->assertNotSame('', $runtime->label());
        $this->assertNull($runtime->modelError($runtime->defaultModel()), 'the default model must be valid');

        $probe = $runtime->probe();
        $this->assertSame($runtime->binary(), $probe->cli);
        $this->assertSame($runtime->binary(), $probe->argv[0]);
        $this->assertNotSame('', $probe->docsUrl);
        $this->assertArrayHasKey('Linux', $probe->installCommands);
        $this->assertArrayHasKey('Darwin', $probe->installCommands);

        $sid = $runtime->newSessionId();
        $command = $runtime->launchCommand('task-analyst', 'go', null, $sid);
        $this->assertStringStartsWith($runtime->binary().' ', $command);
        $this->assertStringContainsString($sid, $command, 'the run goes under the PABLO-assigned session');
    }

    #[DataProvider('runtimes')]
    public function testSessionHandlesAreRecognisedWhateverTheSelectedRuntime(string $name): void
    {
        $runtime = $this->runtime($name);
        $sessions = $runtime->sessions();
        $id = $runtime->newSessionId();
        $this->assertNotSame($id, $runtime->newSessionId(), 'every launch gets its own session');
        $handle = $sessions->handle($id);

        $this->assertTrue($sessions->owns($handle));
        $this->assertSame($sessions->resumeCommand($handle), SessionRef::command($handle));
        foreach (AgentRuntimeRegistry::NAMES as $other) {
            if ($other !== $name) {
                $this->assertFalse($this->runtime($other)->sessions()->owns($handle), "{$other} must not claim a {$name} handle");
            }
        }
    }

    #[DataProvider('runtimes')]
    public function testUninstallCoversEverythingInstallLinksAndDoctorSeesItFresh(string $name): void
    {
        $runtime = $this->runtime($name);
        $files = $runtime->agentFiles();
        $templates = new AgentTemplates(new Config(new GlobalConfig()));
        $agentsDir = $templates->templatesDir();
        $providers = $templates->enabledProviders();

        // What system:generate-agents writes: the shared render, then the runtime's own files.
        $rendered = AgentTemplates::renderedDir();
        foreach (AgentTemplates::AGENT_NAMES as $agent) {
            Files::write($rendered.'/agents/'.$agent.'.md', $templates->render($agentsDir, $agent, $providers));
        }
        foreach (Files::list(AgentTemplates::commandsDirFor($agentsDir), '*.md') as $source) {
            Files::copy($source, $rendered.'/commands/'.basename($source));
        }
        foreach (Files::list(AgentTemplates::skillsDirFor($agentsDir), '*', dirsOnly: true) as $source) {
            Files::copy($source, $rendered.'/skills/'.basename($source));
        }
        foreach ($files->generate($agentsDir, $providers) as $path => $content) {
            $this->assertStringStartsWith($files->generatedDir().'/', $path);
            Files::write($path, $content);
        }

        // What system:install links.
        $links = $files->links($rendered);
        $this->assertNotSame([], $links);
        $linker = new Links(\dirname($rendered));
        foreach ($links as $link => $target) {
            $this->assertStringStartsWith($files->userConfigDir().'/', $link);
            $this->assertFileExists($target, "{$link} must point at a generated file");
            $linker->link($target, $link);
        }

        $installed = $files->installedPaths($agentsDir);
        $this->assertSame([], array_values(array_diff(array_keys($links), $installed)), 'system:uninstall must know every link system:install creates');

        foreach ($files->staleness($agentsDir, $providers) as $status) {
            $this->assertSame(AgentFileState::Ok, $status->state, "{$status->agent}: {$status->detail}");
        }
    }
}
