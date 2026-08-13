<?php

declare(strict_types=1);

namespace Pablo\Tests;

use Pablo\Command\System\DoctorCommand;
use Pablo\Config\ProjectConfig;
use Pablo\Doctor\CheckResult;
use Pablo\Doctor\Doctor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Tester\CommandTester;

final class DoctorTest extends TestCase
{
    private const TMP = '/tmp';

    protected function tearDown(): void
    {
        Doctor::setWhichSeam(null);
        Doctor::setProbeSeam(null);
    }

    private function makeCfg(string $name, string $provider, ?string $confluenceSpace = null): ProjectConfig
    {
        return new ProjectConfig(
            name: $name,
            type: 'work',
            repoPath: self::TMP.'/'.$name,
            primaryBranch: 'main',
            worktreesRoot: self::TMP.'/wt/'.$name,
            provider: $provider,
            identity: 'octocat',
            projectKey: 'PR',
            syncStrategy: 'rebase',
            syncAutoApply: false,
            syncInterval: 30,
            pollInterval: 10,
            failureSignal: null,
            botWhitelist: [],
            ciIgnoreChecks: [],
            confluenceSpace: $confluenceSpace,
        );
    }

    public function testRequiredSetWithoutJiraLinear(): void
    {
        $projects = ['a' => $this->makeCfg('a', 'github')];
        $this->assertSame(['gh', 'opencode', 'orca'], Doctor::requiredClis($projects));
    }

    public function testRequiredIncludesAcliForJiraProjects(): void
    {
        $projects = ['a' => $this->makeCfg('a', 'jira'), 'b' => $this->makeCfg('b', 'linear')];
        $this->assertSame(['acli', 'gh', 'linear', 'opencode', 'orca'], Doctor::requiredClis($projects));
    }

    public function testRequiredAddsAcliConfluenceOnlyWhenSpaceConfigured(): void
    {
        $projects = [
            'a' => $this->makeCfg('a', 'jira', 'PIM'),
            'b' => $this->makeCfg('b', 'jira'),
        ];
        $clis = Doctor::requiredClis($projects);
        $this->assertContains('acli', $clis);
        $this->assertContains('acli-confluence', $clis);

        $projects = ['a' => $this->makeCfg('a', 'jira')];
        $this->assertNotContains('acli-confluence', Doctor::requiredClis($projects));
    }

    public function testMissingCliReportedNotInstalled(): void
    {
        Doctor::setWhichSeam(static fn (string $name): ?string => null);
        $results = Doctor::checkAll(['a' => $this->makeCfg('a', 'github')]);
        foreach ($results as $r) {
            $this->assertFalse($r->installed);
            $this->assertFalse($r->ok());
        }
        $gh = $this->findCli($results, 'gh');
        $this->assertStringContainsString('not installed', $gh->detail);
    }

    public function testAuthFailureSurfacesCliMessageAndHint(): void
    {
        Doctor::setWhichSeam(static fn (string $name): string => '/usr/bin/'.$name);
        Doctor::setProbeSeam(static fn (array $argv): array => [1, 'You are not logged into any GitHub hosts']);
        $results = Doctor::checkAll(['a' => $this->makeCfg('a', 'github')]);
        $gh = $this->findCli($results, 'gh');
        $this->assertTrue($gh->installed);
        $this->assertFalse($gh->authenticated);
        $this->assertFalse($gh->ok());
        $this->assertStringContainsString('not logged in', strtolower($gh->detail));
        $this->assertStringContainsString('gh auth login', $gh->hint);
    }

    public function testAllGreen(): void
    {
        Doctor::setWhichSeam(static fn (string $name): string => '/usr/bin/'.$name);
        Doctor::setProbeSeam(static fn (array $argv): array => [0, 'ok']);
        $results = Doctor::checkAll(['a' => $this->makeCfg('a', 'github')]);
        foreach ($results as $r) {
            $this->assertTrue($r->ok());
        }
    }

    /**
     * @param list<string> $argv
     *
     * @return array{0: int, 1: string}
     */
    private static function probeDispatch(array $argv): array
    {
        if ($argv === ['acli', 'confluence', 'auth', 'status']) {
            return [1, 'not authenticated: run acli confluence auth login'];
        }
        if ($argv === ['acli', 'jira', 'auth', 'status']) {
            return [0, "✓ Authenticated\n  Email: acme@example.com"];
        }

        return [0, 'ok'];
    }

    public function testJiraProbeFailureSurfacesAcliHint(): void
    {
        Doctor::setWhichSeam(static fn (string $name): string => '/usr/bin/'.$name);
        Doctor::setProbeSeam(static fn (array $argv): array => [1, 'Error: not authenticated']);
        $results = Doctor::checkAll(['a' => $this->makeCfg('a', 'jira')]);
        $check = $this->findCli($results, 'acli');
        $this->assertTrue($check->installed);
        $this->assertFalse($check->authenticated);
        $this->assertFalse($check->ok());
        $this->assertStringContainsString('acli auth login', $check->hint);
        $this->assertStringContainsString('not authenticated', $check->detail);
    }

    public function testJiraProbeOk(): void
    {
        Doctor::setWhichSeam(static fn (string $name): string => '/usr/bin/'.$name);
        Doctor::setProbeSeam(self::probeDispatch(...));
        $results = Doctor::checkAll(['a' => $this->makeCfg('a', 'jira')]);
        $check = $this->findCli($results, 'acli');
        $this->assertTrue($check->ok());
        $this->assertStringContainsString('Authenticated', $check->detail);
    }

    public function testConfluenceProbeReportedSeparately(): void
    {
        Doctor::setWhichSeam(static fn (string $name): string => '/usr/bin/'.$name);
        Doctor::setProbeSeam(self::probeDispatch(...));
        $results = Doctor::checkAll(['a' => $this->makeCfg('a', 'jira', 'PIM')]);
        $acliJira = $this->findCli($results, 'acli');
        $acliConf = $this->findCli($results, 'acli-confluence');
        $this->assertTrue($acliJira->ok());
        $this->assertFalse($acliConf->ok());
        $this->assertStringContainsString('confluence auth login', $acliConf->hint);
    }

    public function testRender(): void
    {
        $results = [
            Doctor::checkAll(['a' => $this->makeCfg('a', 'github')]),
        ];
        $all = array_merge(...$results);
        $out = Doctor::render($all);
        $this->assertStringContainsString('✅', $out);
    }

    /** @param array<int, CheckResult> $results */
    private function findCli(array $results, string $cli): CheckResult
    {
        foreach ($results as $r) {
            if ($r->cli === $cli) {
                return $r;
            }
        }
        $this->fail("no result for {$cli}");
    }

    public function testDoctorExitCodes(): void
    {
        Doctor::setWhichSeam(static fn (string $name): string => '/usr/bin/'.$name);
        Doctor::setProbeSeam(static fn (array $argv): array => [0, 'ok']);
        $tester = new CommandTester(new DoctorCommand());
        $tester->execute([]);
        $this->assertSame(SymfonyCommand::SUCCESS, $tester->getStatusCode());

        Doctor::setProbeSeam(static fn (array $argv): array => [1, 'login please']);
        $tester = new CommandTester(new DoctorCommand());
        $tester->execute([]);
        $this->assertSame(SymfonyCommand::FAILURE, $tester->getStatusCode());
    }
}
