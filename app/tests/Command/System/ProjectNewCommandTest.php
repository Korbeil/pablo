<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\System;

use Pablo\Command\System\ProjectNewCommand;
use Pablo\Config\Config;
use Pablo\Provider\Git\GitRepo;
use Pablo\Tests\UsesGlobalConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ProjectNewCommandTest extends TestCase
{
    use UsesGlobalConfig;

    private string $tmp;
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

        GitRepo::setUserEmail(static fn (?string $cwd = null) => 'git@example.com');
        GitRepo::setOriginUrl(null);
        GitRepo::setAllBranchNames(null);
    }

    protected function tearDown(): void
    {
        putenv('PABLO_PROJECTS_DIR');
        $this->unsetGlobalConfig();
        GitRepo::setUserEmail(null);
        GitRepo::setOriginUrl(null);
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
        $command = new ProjectNewCommand();
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

        $projects = Config::loadProjects($this->projectsDir);
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
        GitRepo::setOriginUrl(static fn (string $repo) => 'git@github.com:stripe/payment-kit.git');

        $command = new ProjectNewCommand();
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

        $cfg = Config::loadProjects($this->projectsDir)['payment-kit'];
        $this->assertSame('payment-kit', $cfg->name);
        $this->assertSame('open-source', $cfg->type);
        $this->assertSame('github', $cfg->provider);
        $this->assertSame('PAYMENT-KIT', $cfg->projectKey);
        $this->assertSame('stripe/payment-kit', $cfg->issueRepo);
        $this->assertSame('git@example.com', $cfg->identity);
    }

    public function testHappyPathLinear(): void
    {
        $command = new ProjectNewCommand();
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

        $cfg = Config::loadProjects($this->projectsDir)['my-app'];
        $this->assertSame('my-app', $cfg->name);
        $this->assertSame('personal', $cfg->type);
        $this->assertSame('linear', $cfg->provider);
        $this->assertSame('PROJ', $cfg->projectKey);
        $this->assertSame('user@linear.app', $cfg->identity);
        $this->assertNull($cfg->site);
    }

    public function testWithFailureSignal(): void
    {
        $command = new ProjectNewCommand();
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

        $cfg = Config::loadProjects($this->projectsDir)['qa-test'];
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

        $command = new ProjectNewCommand();
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

        $projects = Config::loadProjects($this->projectsDir);
        $this->assertArrayHasKey('existing2', $projects);
    }

    public function testAbortsOnNoConfirm(): void
    {
        $command = new ProjectNewCommand();
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

    public function testRegeneratesWhenProviderSetChanges(): void
    {
        $this->writeSeedProject('acme', 'jira');
        [$command, $fake] = $this->wiredCommand();

        $tester = new CommandTester($command);
        $tester->setInputs([
            '/home/me/dev/lin-app',
            'lin-app',
            '0', // work
            '2', // provider: linear — set grows from {jira} to {jira, linear}
            '', // confluence space (empty = skip)
            'LIN', // project key
            'u@l.app', // identity
            'n', // failure signal? no
            'y', // confirm write
        ]);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertArrayHasKey('lin-app', Config::loadProjects($this->projectsDir));
        $this->assertSame(1, $fake->runs, 'a changed provider set must re-generate agents');
        $this->assertStringContainsString('Agent templates will be re-generated', $tester->getDisplay());
    }

    public function testDoesNotRegenerateWhenProviderSetUnchanged(): void
    {
        $this->writeSeedProject('other-gh', 'github');
        [$command, $fake] = $this->wiredCommand();

        $tester = new CommandTester($command);
        $tester->setInputs([
            '/home/me/dev/second-gh',
            'second-gh',
            '0', // work
            '0', // provider: github — already enabled elsewhere
            '', // issue repo
            '', // project key (default: SECOND-GH)
            'u@e.com', // identity
            'n', // failure signal? no
            'y', // confirm write
        ]);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertArrayHasKey('second-gh', Config::loadProjects($this->projectsDir));
        $this->assertSame(0, $fake->runs, 'an unchanged provider set must not re-generate agents');
        $this->assertStringNotContainsString('re-generated', $tester->getDisplay());
    }

    /** @return array{0: ProjectNewCommand, 1: FakeGenerateAgentsCommand} */
    private function wiredCommand(): array
    {
        $command = new ProjectNewCommand();
        $fake = new FakeGenerateAgentsCommand();
        $application = new Application();
        $application->addCommand($command);
        $application->addCommand($fake);

        return [$command, $fake];
    }

    private function writeSeedProject(string $name, string $provider): void
    {
        file_put_contents($this->projectsDir.'/'.$name.'.yaml', <<<YAML
name: {$name}
type: work
repo:
  path: /tmp/{$name}
  primary_branch: main
issue_tracker:
  provider: {$provider}
  identity: u@e.com
  project_key: XX
YAML);
    }
}
