<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\System;

use Pablo\Command\System\ProjectNewCommand;
use Pablo\Config\Config;
use Pablo\Tests\FakeGit;
use Pablo\Tests\UsesGlobalConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ProjectNewCommandTest extends TestCase
{
    use UsesGlobalConfig;

    private function loader(): Config
    {
        return new Config(new \Pablo\Config\GlobalConfig());
    }

    private string $tmp;
    private FakeGit $git;
    private string $projectsDir;
    private string $globalConfigYaml;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-project-new-'.uniqid();
        $this->projectsDir = $this->tmp.'/projects';
        mkdir($this->projectsDir, 0o777, true);

        $this->globalConfigYaml = <<<'YAML'
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
default_model: openrouter/test/model
pr_description_locale: en
YAML;
        $this->writeGlobalConfig($this->globalConfigYaml);

        putenv('PABLO_PROJECTS_DIR='.$this->projectsDir);

        $this->git = new FakeGit();
        $this->git->userEmail = 'git@example.com';
        // The wizard probes the repo path; there is none under /home/me, so
        // every probe fails and documented defaults apply.
        $this->git->gitThrows = 'git rev-parse failed: not a repository';
    }

    protected function tearDown(): void
    {
        putenv('PABLO_PROJECTS_DIR');
        $this->unsetGlobalConfig();

        $this->removeDir($this->tmp);
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

    public function testHappyPathJiraWithDefaults(): void
    {
        $command = new ProjectNewCommand($this->git, new \Pablo\Store\Store(), new Config(new \Pablo\Config\GlobalConfig()), new \Pablo\Agents\AgentLauncherFactory(new \Pablo\Config\GlobalConfig()), new \Pablo\Tests\FakeAgents());
        $tester = new CommandTester($command);

        $tester->setInputs([
            '/home/me/dev/my-project', // repo path
            '', // project name (accepts default: my-project)
            '', // type (accepts default: work)
            '1', // provider (jira)
            '', // jira site (accepts default: acme.atlassian.net)
            '', // confluence space (empty = skip)
            '', // project key (accepts default: MY-PROJECT)
            '', // provider identity (accepts default: git@example.com)
            'n', // failure signal? no
            'y', // confirm write
        ]);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());

        $projects = $this->loader()->loadProjects($this->projectsDir);
        $this->assertArrayHasKey('my-project', $projects);

        $cfg = $projects['my-project'];
        $this->assertSame('my-project', $cfg->name);
        $this->assertSame('work', $cfg->type);
        $this->assertSame('/home/me/dev/my-project', $cfg->repoPath);
        $this->assertSame('main', $cfg->primaryBranch);
        $this->assertSame('jira', $cfg->provider);
        $this->assertSame('acme.atlassian.net', $cfg->site);
        $this->assertNull($cfg->confluenceSpace);
        $this->assertSame('MY-PROJECT', $cfg->projectKey);
        $this->assertSame('git@example.com', $cfg->identity);
        $this->assertNull($cfg->issueRepo);
        $this->assertNull($cfg->failureSignal);
    }

    public function testHappyPathGithub(): void
    {
        $this->git->originUrl = static fn (string $repo) => 'git@github.com:stripe/payment-kit.git';

        $command = new ProjectNewCommand($this->git, new \Pablo\Store\Store(), new Config(new \Pablo\Config\GlobalConfig()), new \Pablo\Agents\AgentLauncherFactory(new \Pablo\Config\GlobalConfig()), new \Pablo\Tests\FakeAgents());
        $tester = new CommandTester($command);

        $tester->setInputs([
            '/home/me/dev/payment-kit',
            '', // project name (default: payment-kit)
            '1', // type: open-source
            '0', // provider: github
            'stripe/payment-kit', // issue repo
            '', // project key (default: PAYMENT-KIT)
            '', // identity (default: git@example.com)
            'n', // failure signal? no
            'y', // confirm write
        ]);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());

        $cfg = $this->loader()->loadProjects($this->projectsDir)['payment-kit'];
        $this->assertSame('payment-kit', $cfg->name);
        $this->assertSame('open-source', $cfg->type);
        $this->assertSame('github', $cfg->provider);
        $this->assertSame('PAYMENT-KIT', $cfg->projectKey);
        $this->assertSame('stripe/payment-kit', $cfg->issueRepo);
        $this->assertSame('git@example.com', $cfg->identity);
    }

    public function testHappyPathLinear(): void
    {
        $command = new ProjectNewCommand($this->git, new \Pablo\Store\Store(), new Config(new \Pablo\Config\GlobalConfig()), new \Pablo\Agents\AgentLauncherFactory(new \Pablo\Config\GlobalConfig()), new \Pablo\Tests\FakeAgents());
        $tester = new CommandTester($command);

        $tester->setInputs([
            '/home/me/dev/my-app',
            'my-app',
            '2', // type: personal
            '2', // provider: linear
            '', // confluence space (empty = skip)
            'PROJ', // project key
            'user@linear.app', // identity
            'n', // failure signal? no
            'y', // confirm write
        ]);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());

        $cfg = $this->loader()->loadProjects($this->projectsDir)['my-app'];
        $this->assertSame('my-app', $cfg->name);
        $this->assertSame('personal', $cfg->type);
        $this->assertSame('linear', $cfg->provider);
        $this->assertSame('PROJ', $cfg->projectKey);
        $this->assertSame('user@linear.app', $cfg->identity);
        $this->assertNull($cfg->site);
    }

    public function testWithFailureSignal(): void
    {
        $command = new ProjectNewCommand($this->git, new \Pablo\Store\Store(), new Config(new \Pablo\Config\GlobalConfig()), new \Pablo\Agents\AgentLauncherFactory(new \Pablo\Config\GlobalConfig()), new \Pablo\Tests\FakeAgents());
        $tester = new CommandTester($command);

        $tester->setInputs([
            '/home/me/dev/qa-test',
            'qa-test',
            '0', // type: work
            '0', // provider: github
            '', // issue repo (empty)
            'QT', // project key
            'me@example.com',
            'y', // failure signal? yes
            'qa-failed', // failure signal value
            'y', // confirm write
        ]);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());

        $cfg = $this->loader()->loadProjects($this->projectsDir)['qa-test'];
        $this->assertSame('qa-failed', $cfg->failureSignal);
    }

    public function testRejectsDuplicateName(): void
    {
        file_put_contents($this->projectsDir.'/existing.yaml', <<<'YAML'
name: existing
type: work
repo:
  path: /tmp/existing
  primary_branch: main
issue_tracker:
  provider: github
  identity: u@e.com
  project_key: EX
YAML);

        $command = new ProjectNewCommand($this->git, new \Pablo\Store\Store(), new Config(new \Pablo\Config\GlobalConfig()), new \Pablo\Agents\AgentLauncherFactory(new \Pablo\Config\GlobalConfig()), new \Pablo\Tests\FakeAgents());
        $tester = new CommandTester($command);

        $tester->setInputs([
            '/tmp/existing2',
            'existing',       // duplicate — validator rejects, re-prompts
            'existing2',      // fallback with unique name
            '0',              // type: work
            '0',              // provider: github
            '',               // issue repo
            '',               // project key
            'u@e.com',
            'n',              // failure signal? no
            'y',              // confirm write
        ]);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('already exists', $tester->getDisplay());

        $projects = $this->loader()->loadProjects($this->projectsDir);
        $this->assertArrayHasKey('existing2', $projects);
    }

    public function testAbortsOnNoConfirm(): void
    {
        $command = new ProjectNewCommand($this->git, new \Pablo\Store\Store(), new Config(new \Pablo\Config\GlobalConfig()), new \Pablo\Agents\AgentLauncherFactory(new \Pablo\Config\GlobalConfig()), new \Pablo\Tests\FakeAgents());
        $tester = new CommandTester($command);

        $tester->setInputs([
            '/tmp/abort',
            'abort',
            '0', // work
            '0', // github
            '', // issue repo
            '', // project key (default: ABORT)
            'u@e.com',
            'n', // failure signal? no
            'n', // DON'T confirm
        ]);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertFileDoesNotExist($this->projectsDir.'/abort.yaml');
    }
}
