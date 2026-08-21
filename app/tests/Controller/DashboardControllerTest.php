<?php

declare(strict_types=1);

namespace Pablo\Tests\Controller;

use Pablo\Domain\DisplayCache;
use Pablo\Domain\Issue;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Store\Store;
use Pablo\Support\RepoSlug;
use Pablo\Tests\RestoresErrorHandlers;
use Pablo\Tests\UsesGlobalConfig;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end smoke test for the dashboard page.
 *
 * Runs against a fixture ~/.pablo tree so it never reads the developer's real
 * task state, and stubs RepoSlug so no `git remote get-url` runs.
 */
final class DashboardControllerTest extends WebTestCase
{
    use RestoresErrorHandlers;
    use UsesGlobalConfig;

    private string $tmp;

    protected function setUp(): void
    {
        $this->snapshotErrorHandlers();
        $this->tmp = sys_get_temp_dir().'/pablo-web-'.uniqid();
        mkdir($this->tmp.'/projects', 0o777, true);
        mkdir($this->tmp.'/stamps', 0o777, true);
        mkdir($this->tmp.'/logs', 0o777, true);

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

        file_put_contents($this->tmp.'/projects/wallet-kit.yaml', <<<YAML
            name: wallet-kit
            type: work
            repo:
                path: {$this->tmp}/repo
                primary_branch: main
            issue_tracker:
                provider: github
                identity: octocat
                project_key: WK
            YAML);

        file_put_contents($this->tmp.'/projects/bookkeeper.yaml', <<<YAML
            name: bookkeeper
            type: open-source
            repo:
                path: {$this->tmp}/bookkeeper
                primary_branch: main
            issue_tracker:
                provider: github
                identity: octocat
                project_key: BK
            YAML);

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

    private function seed(string $branch, State $state, ?DisplayCache $cache = null, string $project = 'wallet-kit'): void
    {
        $store = new Store($this->tmp.'/state');
        $task = new Task($project, $branch, $this->tmp.'/wt/'.$branch, $state);
        $task->stateEnteredAt = '2026-08-01T10:00:00+00:00';
        $task->displayCache = $cache ?? DisplayCache::empty();
        $store->save($task);
    }

    private function browser(): KernelBrowser
    {
        return static::createClient();
    }

    public function testRendersBothTables(): void
    {
        $this->seed('wk-1-ci-red', State::CiRed);
        $this->seed('wk-2-draft', State::Draft);

        $client = $this->browser();
        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'P.A.B.L.O.');

        $headings = $crawler->filter('.pablo-heading')->each(static fn ($n) => trim($n->text()));
        $joined = implode(' | ', $headings);
        // Same wording as the terminal listing's two sections.
        $this->assertStringContainsString('Waiting for feedback', $joined);
        $this->assertStringContainsString('Other tasks', $joined);

        $this->assertSame(1, $crawler->filter('td:contains("wk-1-ci-red")')->count());
        $this->assertSame(1, $crawler->filter('td:contains("wk-2-draft")')->count());
    }

    public function testAttentionTaskIsInTheFirstTable(): void
    {
        // ci-red alone is not enough — an agent must actually be blocked on us.
        $this->seed('wk-1-ci-red', State::CiRed, new DisplayCache(null, null, 1, '💭 1', '2026-08-06T10:00:00+00:00'));
        $this->seed('wk-2-draft', State::Draft);

        $crawler = $this->browser()->request('GET', '/');

        $tables = $crawler->filter('table');
        $this->assertSame(2, $tables->count());
        $this->assertStringContainsString('wk-1-ci-red', $tables->eq(0)->text());
        $this->assertStringContainsString('wk-2-draft', $tables->eq(1)->text());
    }

    public function testEmptyStateIsFriendly(): void
    {
        $crawler = $this->browser()->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Nothing is waiting on you right now', $crawler->text());
    }

    /** All three live components must be mounted for polling to work at all. */
    public function testLiveComponentsAreMounted(): void
    {
        $crawler = $this->browser()->request('GET', '/');

        $names = $crawler->filter('[data-live-name-value]')
            ->each(static fn ($n) => $n->attr('data-live-name-value'));

        sort($names);
        $this->assertSame(['PollProgress', 'RebaseLog', 'SlackModal', 'TaskBoard'], $names);
    }

    /**
     * One tick polls every due project, so there is exactly one countdown bar
     * however many projects are configured; per-project last-run times live in
     * the recap beside it.
     */
    public function testPollProgressShowsOneSharedCountdown(): void
    {
        file_put_contents($this->tmp.'/stamps/wallet-kit.poll', (string) time());

        $crawler = $this->browser()->request('GET', '/');

        $poll = $crawler->filter('[data-controller="countdown"]');
        $this->assertSame(1, $poll->count());
        $this->assertSame(1, $crawler->filter('progress')->count());
        $this->assertNotEmpty($poll->attr('data-countdown-end-value'));
        $this->assertStringContainsString('Next poll', $poll->text());
    }

    public function testPollRecapListsEveryProjectWithItsLastRun(): void
    {
        file_put_contents($this->tmp.'/stamps/wallet-kit.poll', (string) time());

        $crawler = $this->browser()->request('GET', '/');

        $recap = $crawler->filter('.pablo-poll-recap-item');
        $this->assertSame(2, $recap->count(), 'one row per configured project');
        $this->assertStringContainsString('wallet-kit', $crawler->text());
        $this->assertStringContainsString('bookkeeper', $crawler->text());
        $this->assertStringContainsString('just now', $crawler->text());
    }

    public function testPollRecapMarksANeverPolledProject(): void
    {
        $crawler = $this->browser()->request('GET', '/');

        $this->assertStringContainsString('never polled', $crawler->text());
    }

    public function testPrBadgeLinksToGithub(): void
    {
        $this->seed('wk-3', State::Draft, new DisplayCache(null, '📖 open #42', 0, '-', '2026-08-06T10:00:00+00:00'));

        $crawler = $this->browser()->request('GET', '/');

        $link = $crawler->filter('a[href="https://github.com/acme/wallet-kit/pull/42"]');
        $this->assertSame(1, $link->count());
    }

    /** The tracker status chip must link to the issue; without an issue it stays an unlinked badge. */
    public function testTrackerStatusLinksToIssueUrl(): void
    {
        $task = new Task('wallet-kit', 'wk-4', $this->tmp.'/wt/wk-4', State::Draft);
        $task->issue = new Issue('github', 'WK-7', 'https://github.com/acme/wallet-kit/issues/7', 'Fix the thing');
        $task->stateEnteredAt = '2026-08-01T10:00:00+00:00';
        $task->displayCache = new DisplayCache('In Review', null, 0, '-', '2026-08-06T10:00:00+00:00');
        (new Store($this->tmp.'/state'))->save($task);

        $crawler = $this->browser()->request('GET', '/');

        $link = $crawler->filter('td a.tag.pablo-chip[href="https://github.com/acme/wallet-kit/issues/7"]');
        $this->assertSame(1, $link->count());
        $this->assertStringContainsString('In Review', $link->text());
    }

    /** A cached status with no stored issue must not render a broken link. */
    public function testTrackerStatusWithoutIssueStaysAnUnlinkedChip(): void
    {
        $this->seed('wk-5', State::Draft, new DisplayCache('In Review', null, 0, '-', '2026-08-06T10:00:00+00:00'));

        $crawler = $this->browser()->request('GET', '/');

        $link = $crawler->filter('td a.tag.pablo-chip');
        $this->assertSame(0, $link->count());
        $this->assertSame(1, $crawler->filter('td span.tag.pablo-chip')->count());
    }

    /** The Slack modal must not fetch anything until the user asks. */
    public function testSlackModalIsClosedAndEmptyOnLoad(): void
    {
        $crawler = $this->browser()->request('GET', '/');

        $this->assertSame(0, $crawler->filter('textarea')->count());
        $this->assertSame(0, $crawler->filter('.modal.is-active')->count());
    }

    /**
     * ?type= is hydrated into every live component on a full page load, so all
     * three sections agree on the filter without any tab clicks.
     */
    public function testUrlTypeFiltersAllThreeSections(): void
    {
        $this->seed('wk-draft', State::Draft, project: 'wallet-kit');
        $this->seed('bk-draft', State::Draft, project: 'bookkeeper');
        file_put_contents($this->tmp.'/stamps/bookkeeper.poll', (string) time());

        $crawler = $this->browser()->request('GET', '/?type=open-source');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('bk-draft', $crawler->text());
        $this->assertStringNotContainsString('wk-draft', $crawler->text());
        $this->assertStringContainsString('bookkeeper', $crawler->text());
        $this->assertStringNotContainsString('wallet-kit', $crawler->text());
    }
}
