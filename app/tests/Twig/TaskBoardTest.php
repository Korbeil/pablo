<?php

declare(strict_types=1);

namespace Pablo\Tests\Twig;

use Pablo\Domain\DisplayCache;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Store\Store;
use Pablo\Support\RepoSlug;
use Pablo\Tests\RestoresErrorHandlers;
use Pablo\Tests\UsesGlobalConfig;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * The TaskBoard's project-type tabs: filtering must keep tasks of matching
 * projects and drop the rest, with "All" showing everything.
 *
 * Runs against a fixture ~/.pablo tree so it never reads the developer's real
 * task state, and stubs RepoSlug so no `git remote get-url` runs.
 */
final class TaskBoardTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use RestoresErrorHandlers;
    use UsesGlobalConfig;

    private string $tmp;

    protected function setUp(): void
    {
        $this->snapshotErrorHandlers();
        $this->tmp = sys_get_temp_dir().'/pablo-taskboard-'.uniqid();
        mkdir($this->tmp.'/projects', 0o777, true);

        putenv('PABLO_STATE_DIR='.$this->tmp.'/state');
        putenv('PABLO_PROJECTS_DIR='.$this->tmp.'/projects');
        putenv('PABLO_STAMPS_DIR='.$this->tmp.'/stamps');
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
        $this->writeProject('blog', 'personal');

        RepoSlug::setFor(static fn () => 'acme/wallet-kit');
    }

    protected function tearDown(): void
    {
        RepoSlug::setFor(null);
        foreach (['PABLO_STATE_DIR', 'PABLO_PROJECTS_DIR', 'PABLO_STAMPS_DIR', 'PABLO_LOGS_DIR'] as $var) {
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

    private function seed(string $project, string $branch): void
    {
        $store = new Store($this->tmp.'/state');
        $task = new Task($project, $branch, $this->tmp.'/wt/'.$branch, State::Draft);
        $task->stateEnteredAt = '2026-08-01T10:00:00+00:00';
        $task->displayCache = DisplayCache::empty();
        $store->save($task);
    }

    private function renderTabs(?string $type): \Symfony\UX\TwigComponent\Test\RenderedComponent
    {
        $component = $this->createLiveComponent('TaskBoard', [], static::createClient());
        if (null !== $type) {
            $component->set('type', $type);
        }

        return $component->render();
    }

    /** @return list<string> tab labels in DOM order */
    private function tabLabels(string $html): array
    {
        $crawler = new \Symfony\Component\DomCrawler\Crawler($html);

        return $crawler->filter('.pablo-tabs a')->each(static fn ($n) => trim($n->text()));
    }

    public function testTabsAreAlwaysShownInTheSpecOrder(): void
    {
        $this->assertSame(
            ['All', 'Work', 'Open-source', 'Personal'],
            $this->tabLabels((string) $this->renderTabs(null)),
        );
    }

    public function testAllShowsEveryProjectsTasks(): void
    {
        $this->seed('wallet-kit', 'work-branch');
        $this->seed('bookkeeper', 'oss-branch');
        $this->seed('blog', 'personal-branch');

        $html = (string) $this->renderTabs(null);

        foreach (['work-branch', 'oss-branch', 'personal-branch'] as $branch) {
            $this->assertStringContainsString($branch, $html);
        }
    }

    public function testTypeTabKeepsOnlyMatchingProjectsTasks(): void
    {
        $this->seed('wallet-kit', 'work-branch');
        $this->seed('bookkeeper', 'oss-branch');
        $this->seed('blog', 'personal-branch');

        $html = (string) $this->renderTabs('open-source');

        $this->assertStringContainsString('oss-branch', $html);
        $this->assertStringNotContainsString('work-branch', $html);
        $this->assertStringNotContainsString('personal-branch', $html);
    }

    public function testEmptyTypeShowsEmptyTables(): void
    {
        $this->seed('wallet-kit', 'work-branch');

        $html = (string) $this->renderTabs('personal');

        $this->assertStringNotContainsString('work-branch', $html);
        $this->assertStringContainsString('Nothing is waiting on you right now', $html);
    }

    /** The setType action is what the tabs call; it must filter like setting the prop. */
    public function testSetTypeActionFiltersTasks(): void
    {
        $this->seed('wallet-kit', 'work-branch');
        $this->seed('bookkeeper', 'oss-branch');

        $component = $this->createLiveComponent('TaskBoard', [], static::createClient());
        $component->call('setType', ['type' => 'open-source']);

        $html = (string) $component->render();

        $this->assertStringContainsString('oss-branch', $html);
        $this->assertStringNotContainsString('work-branch', $html);
    }
}
