<?php

declare(strict_types=1);

namespace Pablo\Tests\Twig;

use Pablo\Provider\Git\Sync;
use Pablo\Provider\Git\SyncReport;
use Pablo\Tests\RestoresErrorHandlers;
use Pablo\Tests\UsesGlobalConfig;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * The RebaseLog cards must follow the TaskBoard's project-type filter: when
 * pablo:type-change is emitted, only matching projects' last sync shows.
 *
 * Runs against a fixture ~/.pablo tree so it never reads the developer's real
 * task state.
 */
final class RebaseLogTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use RestoresErrorHandlers;
    use UsesGlobalConfig;

    private function sync(): Sync
    {
        $slugGit = new \Pablo\Tests\FakeGit();
        $slugGit->originUrl = 'git@github.com:octocat/proj.git';

        return new Sync(
            new \Pablo\Provider\Git\GitRepo(),
            new \Pablo\Tests\FakeGhPr(),
            new \Pablo\Support\RepoSlug($slugGit),
            new \Pablo\Support\ProcessRunner(),
            new \Pablo\Domain\Time(),
        );
    }

    private string $tmp;

    protected function setUp(): void
    {
        $this->snapshotErrorHandlers();
        $this->tmp = sys_get_temp_dir().'/pablo-rebaselog-'.uniqid();
        mkdir($this->tmp.'/projects', 0o777, true);
        mkdir($this->tmp.'/logs', 0o777, true);

        putenv('PABLO_PROJECTS_DIR='.$this->tmp.'/projects');
        putenv('PABLO_LOGS_DIR='.$this->tmp.'/logs');

        $this->writeGlobalConfig(<<<'YAML'
            sync:
                strategy: rebase
                auto_apply: false
                interval_minutes: 720
            state_polling:
                interval_minutes: 10
            review:
                bot_whitelist: []
            ci:
                ignore_checks: []
            default_model: openrouter/test/model
            pr_description_locale: en
            YAML);

        $this->writeProject('wallet-kit', 'work');
        $this->writeProject('bookkeeper', 'open-source');

        $this->sync()->saveLastLog('wallet-kit', 'rebase', [
            new SyncReport(worktree: '/wt/a', branch: 'wk-fix', action: 'synced'),
        ]);
        $this->sync()->saveLastLog('bookkeeper', 'rebase', [
            new SyncReport(worktree: '/wt/b', branch: 'bk-tidy', action: 'synced'),
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['PABLO_PROJECTS_DIR', 'PABLO_LOGS_DIR'] as $var) {
            putenv($var);
        }
        $this->unsetGlobalConfig();
        exec('rm -rf '.escapeshellarg($this->tmp));
        parent::tearDown();
        $this->restoreErrorHandlers();
    }

    private function writeProject(string $name, string $type): void
    {
        file_put_contents($this->tmp.'/projects/'.$name.'.yaml', <<<YAML
            name: {$name}
            type: {$type}
            repo:
                path: {$this->tmp}/{$name}
                primary_branch: main
            issue_tracker:
                provider: github
                identity: octocat
                project_key: {$name}
            YAML);
    }

    private function renderAfter(?string $type): string
    {
        $component = $this->createLiveComponent('RebaseLog', [], static::createClient());
        if (null !== $type) {
            $component->emit('pablo:type-change', ['type' => $type]);
        }

        return (string) $component->render();
    }

    public function testLogsShowEveryProjectByDefault(): void
    {
        $html = $this->renderAfter(null);

        $this->assertStringContainsString('wallet-kit', $html);
        $this->assertStringContainsString('bookkeeper', $html);
    }

    public function testLogsFollowTheTypeChangeEvent(): void
    {
        $html = $this->renderAfter('open-source');

        $this->assertStringContainsString('bookkeeper', $html);
        $this->assertStringNotContainsString('wallet-kit', $html);
    }
}
