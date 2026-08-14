<?php

declare(strict_types=1);

namespace Pablo\Tests;

use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Support\PabloError;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    use UsesGlobalConfig;

    private const DEFAULTS = <<<'YAML'
sync:
  strategy: rebase
  auto_apply: false
  interval_minutes: 30
state_polling:
  interval_minutes: 10
review:
  bot_whitelist: []
ci:
  ignore_checks: []
YAML;

    private const FULL_PROJECT = <<<'YAML'
name: wallet-kit
type: open-source
repo:
  path: ~/dev/wallet-kit
  primary_branch: main
worktrees_root: ~/dev/wallet-kit-worktrees
issue_tracker:
  provider: github
  identity: bfontaine
  project_key: WK
sync:
  strategy: merge
  auto_apply: true
  interval_minutes: 5
state_polling:
  interval_minutes: 2
testing:
  failure_signal: qa-failed
review:
  bot_whitelist: ["copilot-pull-request-reviewer[bot]"]
ci:
  ignore_checks: ["approval"]
YAML;

    private const MINIMAL_PROJECT = <<<'YAML'
name: mini
type: personal
repo:
  path: ~/dev/mini
  primary_branch: master
issue_tracker:
  provider: github
  identity: octocat
  project_key: MI

YAML;

    private string $projectsDir;

    protected function setUp(): void
    {
        $this->projectsDir = sys_get_temp_dir().'/pablo-config-'.uniqid();
        mkdir($this->projectsDir, 0o777, true);
        $this->writeGlobalConfig(self::DEFAULTS);
    }

    protected function tearDown(): void
    {
        $this->unsetGlobalConfig();
        $this->removeDir($this->projectsDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items ?: [] as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function write(string $name, string $content): void
    {
        file_put_contents($this->projectsDir.'/'.$name, $content);
    }

    public function testLoadsOnlyProjectYamls(): void
    {
        $this->write('mini.yaml', self::MINIMAL_PROJECT);
        $projects = Config::loadProjects($this->projectsDir);
        $this->assertSame(['mini'], array_keys($projects));
    }

    public function testPerKeyMerge(): void
    {
        $this->write('mini.yaml', self::MINIMAL_PROJECT."sync:\n  interval_minutes: 5\n");
        $cfg = Config::loadProjects($this->projectsDir)['mini'];
        $this->assertSame(5, $cfg->syncInterval);
        $this->assertSame('rebase', $cfg->syncStrategy);
        $this->assertFalse($cfg->syncAutoApply);
        $this->assertSame(10, $cfg->pollInterval);
        $this->assertSame([], $cfg->botWhitelist);
        $this->assertSame([], $cfg->ciIgnoreChecks);
    }

    public function testProjectValueWins(): void
    {
        $this->write('wallet-kit.yaml', self::FULL_PROJECT);
        $cfg = Config::loadProjects($this->projectsDir)['wallet-kit'];
        $this->assertSame('merge', $cfg->syncStrategy);
        $this->assertTrue($cfg->syncAutoApply);
        $this->assertSame(5, $cfg->syncInterval);
        $this->assertSame(2, $cfg->pollInterval);
        $this->assertSame(['copilot-pull-request-reviewer[bot]'], $cfg->botWhitelist);
        $this->assertSame(['approval'], $cfg->ciIgnoreChecks);
        $this->assertSame('qa-failed', $cfg->failureSignal);
        $this->assertSame(getenv('HOME').'/dev/wallet-kit-worktrees', $cfg->worktreesRoot);
        $this->assertSame(getenv('HOME').'/dev/wallet-kit', $cfg->repoPath);
    }

    public function testWorktreesRootDefault(): void
    {
        $this->write('mini.yaml', self::MINIMAL_PROJECT);
        $cfg = Config::loadProjects($this->projectsDir)['mini'];
        $this->assertSame(getenv('HOME').'/.pablo/worktrees/mini', $cfg->worktreesRoot);
    }

    public function testSiteOptional(): void
    {
        $this->write('mini.yaml', self::MINIMAL_PROJECT);
        $this->assertNull(Config::loadProjects($this->projectsDir)['mini']->site);
        $this->write('mini.yaml', str_replace(
            '  project_key: MI'."\n",
            "  project_key: MI\n  site: acme.atlassian.net\n",
            self::MINIMAL_PROJECT,
        ));
        $this->assertSame('acme.atlassian.net', Config::loadProjects($this->projectsDir)['mini']->site);
    }

    public function testConfluenceSpaceOptional(): void
    {
        $this->write('mini.yaml', self::MINIMAL_PROJECT);
        $this->assertNull(Config::loadProjects($this->projectsDir)['mini']->confluenceSpace);
        $this->write('mini.yaml', self::MINIMAL_PROJECT."confluence:\n  space: PIM\n");
        $this->assertSame('PIM', Config::loadProjects($this->projectsDir)['mini']->confluenceSpace);
    }

    public function testIssueRepoOptional(): void
    {
        $this->write('mini.yaml', self::MINIMAL_PROJECT);
        $this->assertNull(Config::loadProjects($this->projectsDir)['mini']->issueRepo);
        $this->write('mini.yaml', str_replace(
            '  project_key: MI'."\n",
            "  project_key: MI\n  repo: acme/upstream\n",
            self::MINIMAL_PROJECT,
        ));
        $this->assertSame('acme/upstream', Config::loadProjects($this->projectsDir)['mini']->issueRepo);
    }

    public function testStartupScriptOptional(): void
    {
        $this->write('mini.yaml', self::MINIMAL_PROJECT);
        $this->assertNull(Config::loadProjects($this->projectsDir)['mini']->startupScript);
    }

    public function testStartupScriptParsed(): void
    {
        $this->write('mini.yaml', self::MINIMAL_PROJECT."startup_script: ~/scripts/pablo-setup.sh\n");
        $cfg = Config::loadProjects($this->projectsDir)['mini'];
        $this->assertSame(getenv('HOME').'/scripts/pablo-setup.sh', $cfg->startupScript);
    }

    public function testMissingRequiredKeyNamesFileAndKey(): void
    {
        $this->write('broken.yaml', "name: broken\ntype: work\n");
        try {
            Config::loadProjects($this->projectsDir);
            $this->fail('expected PabloError');
        } catch (PabloError $e) {
            $this->assertStringContainsString('broken.yaml', $e->getMessage());
            $this->assertStringContainsString('repo.path', $e->getMessage());
        }
    }

    public function testUnknownTypeRejected(): void
    {
        $this->write('bad.yaml', str_replace('type: personal', 'type: hobby', self::MINIMAL_PROJECT));
        try {
            Config::loadProjects($this->projectsDir);
            $this->fail('expected PabloError');
        } catch (PabloError $e) {
            $this->assertStringContainsString('hobby', $e->getMessage());
        }
    }

    public function testUnknownProviderRejected(): void
    {
        $this->write('bad.yaml', str_replace('provider: github', 'provider: gitlab', self::MINIMAL_PROJECT));
        try {
            Config::loadProjects($this->projectsDir);
            $this->fail('expected PabloError');
        } catch (PabloError $e) {
            $this->assertStringContainsString('gitlab', $e->getMessage());
        }
    }

    public function testGithubRequiresProjectKey(): void
    {
        $this->write('bad.yaml', str_replace("  project_key: MI\n", '', self::MINIMAL_PROJECT));
        try {
            Config::loadProjects($this->projectsDir);
            $this->fail('expected PabloError');
        } catch (PabloError $e) {
            $this->assertStringContainsString('project_key', $e->getMessage());
        }
    }

    public function testDefaultsComeFromGlobalConfig(): void
    {
        $this->write('mini.yaml', self::MINIMAL_PROJECT);
        $cfg = Config::loadProjects($this->projectsDir)['mini'];
        $this->assertSame('rebase', $cfg->syncStrategy);
        $this->assertSame(30, $cfg->syncInterval);
        $this->assertSame(10, $cfg->pollInterval);
        $this->assertSame([], $cfg->botWhitelist);
        $this->assertSame([], $cfg->ciIgnoreChecks);
    }

    public function testProjectValueWinsOverGlobalDefaults(): void
    {
        $this->write('mini.yaml', self::MINIMAL_PROJECT."sync:\n  strategy: merge\n  interval_minutes: 5\n");
        $cfg = Config::loadProjects($this->projectsDir)['mini'];
        $this->assertSame('merge', $cfg->syncStrategy);
        $this->assertSame(5, $cfg->syncInterval);
        $this->assertSame(10, $cfg->pollInterval);
    }

    public function testNoGlobalConfigMeansNoDefaults(): void
    {
        putenv('PABLO_CONFIG='.$this->projectsDir.'/missing.yaml');
        $this->write('mini.yaml', self::MINIMAL_PROJECT);
        try {
            Config::loadProjects($this->projectsDir);
            $this->fail('expected PabloError');
        } catch (PabloError $e) {
            $this->assertStringContainsString('sync.interval_minutes', $e->getMessage());
        }
    }

    public function testGlobalConfigPathHonorsOverride(): void
    {
        $this->assertSame($this->configPath, GlobalConfig::configPath());
        $this->assertSame('/tmp/custom-config.yaml', GlobalConfig::configPath('/tmp/custom-config.yaml'));
        putenv('PABLO_CONFIG');
        $this->assertSame(getenv('HOME').'/.pablo/config.yaml', GlobalConfig::configPath());
    }
}
