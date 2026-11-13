<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\System;

use Pablo\Command\System\ConfigValidateCommand;
use Pablo\Config\Config;
use Pablo\Config\ConfigValidator;
use Pablo\Config\GlobalConfig;
use Pablo\Doctor\Doctor;
use Pablo\Store\Store;
use Pablo\Tests\Config\ConfigValidatorTest;
use Pablo\Tests\FakeAgents;
use Pablo\Tests\FakeProcessRunner;
use Pablo\Tests\TempDir;
use Pablo\Tests\UsesGlobalConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class ConfigValidateCommandTest extends TestCase
{
    use UsesGlobalConfig;

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = TempDir::create('validate-cmd');
        mkdir($this->tmp.'/projects', 0o777, true);
        mkdir($this->tmp.'/repo/.git', 0o777, true);
        putenv('PABLO_PROJECTS_DIR='.$this->tmp.'/projects');
        $this->writeGlobalConfig(ConfigValidatorTest::DEFAULTS);
    }

    protected function tearDown(): void
    {
        putenv('PABLO_PROJECTS_DIR');
        $this->unsetGlobalConfig();
        (new Filesystem())->remove($this->tmp);
    }

    /** @param array<string, mixed> $input */
    private function validate(array $input = []): CommandTester
    {
        $global = new GlobalConfig();
        $runner = new FakeProcessRunner();
        $command = new ConfigValidateCommand(
            new ConfigValidator($global, new Config($global)),
            new Doctor($runner, \Pablo\Tests\Registries::runtimes($global), \Pablo\Tests\Registries::ades($global), static fn (string $bin): string => '/usr/bin/'.$bin),
            new Store($this->tmp.'/state'),
            new Config($global),
            \Pablo\Tests\Registries::launchers($runner, $global),
            new FakeAgents(),
        );
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    private function project(string $extra = ''): void
    {
        file_put_contents($this->tmp.'/projects/wk.yaml', ConfigValidatorTest::project('wk', $this->tmp.'/repo', $extra));
    }

    public function testAValidConfigExitsZero(): void
    {
        $this->project();

        $tester = $this->validate();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString("✅ {$this->configPath}", $tester->getDisplay());
        $this->assertStringContainsString("✅ {$this->tmp}/projects/wk.yaml", $tester->getDisplay());
        $this->assertStringContainsString('2 files checked: 0 errors, 0 warnings', $tester->getDisplay());
    }

    public function testWarningsAloneExitZero(): void
    {
        $this->project("ci:\n  ignore_check: [approval]\n");

        $tester = $this->validate();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('ci.ignore_check is not a known key, ignored; did you mean `ci.ignore_checks`?', $tester->getDisplay());
        $this->assertStringContainsString('0 errors, 1 warning', $tester->getDisplay());
    }

    public function testAnErrorExitsOne(): void
    {
        $this->project("sync:\n  interval_minutes: 0\n");

        $tester = $this->validate();

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString("❌ {$this->tmp}/projects/wk.yaml", $tester->getDisplay());
        $this->assertStringContainsString('   ❌ sync.interval_minutes must be a whole number of minutes, at least 1, got 0', $tester->getDisplay());
    }

    public function testAnUnknownRuntimeIsAnError(): void
    {
        $this->writeGlobalConfig(ConfigValidatorTest::DEFAULTS."agent_runtime: codex\n");
        $this->project();

        $tester = $this->validate();

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString("unknown agent_runtime 'codex'", $tester->getDisplay());
    }

    public function testJson(): void
    {
        $this->project("sync:\n  intervall_minutes: 5\n  strategy: squash\n");

        $tester = $this->validate(['--format' => 'json']);

        $this->assertSame(1, $tester->getStatusCode());
        $payload = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame([
            'ok' => false,
            'errors' => 1,
            'warnings' => 1,
            'files' => [$this->configPath, $this->tmp.'/projects/wk.yaml'],
            'issues' => [
                ['file' => $this->tmp.'/projects/wk.yaml', 'key' => 'sync.intervall_minutes', 'severity' => 'warning', 'message' => 'is not a known key, ignored; did you mean `sync.interval_minutes`?'],
                ['file' => $this->tmp.'/projects/wk.yaml', 'key' => 'sync.strategy', 'severity' => 'error', 'message' => "must be one of: rebase, merge, got 'squash'"],
            ],
        ], $payload);
    }

    public function testJsonOfAValidConfigIsOk(): void
    {
        $this->project();

        $tester = $this->validate(['--format' => 'json']);

        $this->assertSame(0, $tester->getStatusCode());
        $payload = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertTrue($payload['ok']);
        $this->assertSame([], $payload['issues']);
    }
}
