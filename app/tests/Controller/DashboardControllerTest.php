<?php

declare(strict_types=1);

namespace Pablo\Tests\Controller;

use Pablo\Domain\DisplayCache;
use Pablo\Domain\Issue;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Store\Store;
use Pablo\Support\RepoSlug;
use Pablo\Tests\Git\GitFixtures;
use Pablo\Tests\RestoresErrorHandlers;
use Pablo\Tests\TempDir;
use Pablo\Tests\UsesGlobalConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

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
        $this->tmp = TempDir::create('web');
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

        // Real git repos with a GitHub origin, so RepoSlug resolves the
        // PR links without the old static seam.
        GitFixtures::get('dashboard', $this->tmp, static function (string $dir): void {
            foreach (['repo' => 'wallet-kit', 'bookkeeper' => 'bookkeeper'] as $repo => $slug) {
                (new \Symfony\Component\Process\Process(['git', 'init', '-q', $dir.'/'.$repo]))->mustRun();
                (new \Symfony\Component\Process\Process(['git', '-C', $dir.'/'.$repo, 'remote', 'add', 'origin', "git@github.com:acme/{$slug}.git"]))->mustRun();
            }
        });

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
    }

    protected function tearDown(): void
    {
        foreach (['PABLO_STATE_DIR', 'PABLO_PROJECTS_DIR', 'PABLO_STAMPS_DIR', 'PABLO_LOGS_DIR', 'PABLO_ANALYTICS_DIR'] as $var) {
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
        $this->assertStringNotContainsString('Working', $joined);

        $this->assertSame(1, $crawler->filter('td:contains("wk-1-ci-red")')->count());
        $this->assertSame(1, $crawler->filter('td:contains("wk-2-draft")')->count());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function loopbackHosts(): iterable
    {
        yield '127.0.0.1 with port' => ['127.0.0.1:8321'];
        yield '127.0.0.1' => ['127.0.0.1'];
        yield 'localhost with port' => ['localhost:8321'];
        yield 'localhost' => ['localhost'];
        yield 'localhost, any case' => ['LocalHost:9000'];
    }

    #[DataProvider('loopbackHosts')]
    public function testServesTheLoopbackHosts(string $host): void
    {
        $this->browser()->request('GET', '/', server: ['HTTP_HOST' => $host]);

        $this->assertResponseIsSuccessful();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function foreignHosts(): iterable
    {
        // DNS rebinding: a foreign name re-resolved to 127.0.0.1.
        yield 'page' => ['evil.example', '/'];
        yield 'page, with port' => ['evil.example:8321', '/'];
        yield 'loopback as a prefix' => ['127.0.0.1.evil.example', '/'];
        yield 'loopback as a suffix' => ['evil-localhost', '/'];
        yield 'live component action' => ['evil.example', '/_components/NewTaskModal/start'];
    }

    #[DataProvider('foreignHosts')]
    public function testRejectsForeignHosts(string $host, string $path): void
    {
        $client = $this->browser();
        // Thrown rather than rendered, so the kernel does not log the
        // expected error to stderr; the exception is what becomes the 400.
        $client->catchExceptions(false);

        try {
            $client->request('/' === $path ? 'GET' : 'POST', $path, server: ['HTTP_HOST' => $host]);
            $this->fail("a request for host {$host} was served");
        } catch (BadRequestHttpException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertStringContainsString('Untrusted Host', $e->getMessage());
        }
    }

    public function testServesTheConfiguredTrustedHostsToo(): void
    {
        file_put_contents($this->configPath, "\ndashboard_trusted_hosts: [pablo.home.arpa]\n", \FILE_APPEND);
        $client = $this->browser();

        $client->request('GET', '/', server: ['HTTP_HOST' => 'Pablo.Home.Arpa:8321']);
        $this->assertResponseIsSuccessful();
        $client->request('GET', '/', server: ['HTTP_HOST' => '127.0.0.1:8321']);
        $this->assertResponseIsSuccessful();

        // Exact names only: neither a sub-domain nor another host gets in.
        $client->catchExceptions(false);
        foreach (['evil.pablo.home.arpa', 'evil.example'] as $host) {
            try {
                $client->request('GET', '/', server: ['HTTP_HOST' => $host]);
                $this->fail("a request for host {$host} was served");
            } catch (BadRequestHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAnInvalidTrustedHostsValueFailsClosed(): void
    {
        file_put_contents($this->configPath, "\ndashboard_trusted_hosts: ['*.home.arpa']\n", \FILE_APPEND);
        $client = $this->browser();

        $client->request('GET', '/', server: ['HTTP_HOST' => 'localhost']);
        $this->assertResponseIsSuccessful();

        $client->catchExceptions(false);
        $this->expectException(BadRequestHttpException::class);
        $client->request('GET', '/', server: ['HTTP_HOST' => 'x.home.arpa']);
    }

    public function testInvalidProjectFileShowsABannerAndTheOthersStillRender(): void
    {
        file_put_contents($this->tmp.'/projects/broken.yaml', "name: broken\ntype: work\n");
        $this->seed('wk-1-draft', State::Draft);

        $client = $this->browser();
        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[role="alert"]', 'Invalid project config');
        $this->assertSelectorTextContains('[role="alert"]', "broken.yaml: missing required key 'repo.path'");
        $this->assertSame(1, $crawler->filter('td:contains("wk-1-draft")')->count());
    }

    public function testNoBannerWhenEveryProjectLoads(): void
    {
        $client = $this->browser();
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[role="alert"]');
    }

    public function testNoHealthBannerWhenTheDispatcherIsHealthy(): void
    {
        (new \Pablo\Dispatch\DispatchHealth())->recordSuccess('wallet-kit', 'poll', microtime(true));

        $this->browser()->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('.pablo-health-banner');
    }

    public function testHealthBannerListsFailingJobs(): void
    {
        $health = new \Pablo\Dispatch\DispatchHealth();
        $health->recordFailure('wallet-kit', 'sync', microtime(true) - 600, 'first');
        $health->recordFailure('wallet-kit', 'sync', microtime(true), 'git fetch: could not resolve host');
        $health->finishDispatch(microtime(true), microtime(true), \Pablo\Dispatch\DispatchHealth::OUTCOME_PREFLIGHT_FAILED, 'gh: not logged in');

        $crawler = $this->browser()->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $banner = $crawler->filter('.pablo-health-banner');
        $this->assertSame(1, $banner->count());
        $text = (string) preg_replace('/\s+/', ' ', $banner->text());
        $this->assertStringContainsString('wallet-kit sync failing since', $text);
        $this->assertStringContainsString('(2×): git fetch: could not resolve host', $text);
        $this->assertStringContainsString('preflight aborted the run', $text);
        $this->assertStringContainsString('gh: not logged in', $text);
    }

    public function testTablesAreOrderedAttentionRest(): void
    {
        // ci-red alone is not enough — an agent must actually be blocked on us.
        $this->seed('wk-1-ci-red', State::CiRed, new DisplayCache(null, null, 1, '💭 1', '2026-08-06T10:00:00+00:00'));
        // A running agent no longer earns its own table; it lands in rest.
        $this->seed('wk-2-running', State::Draft, new DisplayCache(null, null, 1, '🏃 1', '2026-08-06T10:00:00+00:00'));
        $this->seed('wk-3-draft', State::Draft);

        $crawler = $this->browser()->request('GET', '/');

        $tables = $crawler->filter('table');
        $this->assertSame(2, $tables->count());
        $this->assertStringContainsString('wk-1-ci-red', $tables->eq(0)->text());
        $this->assertStringContainsString('wk-2-running', $tables->eq(1)->text());
        $this->assertStringContainsString('wk-3-draft', $tables->eq(1)->text());
    }

    public function testEmptyStateIsFriendly(): void
    {
        $crawler = $this->browser()->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Nothing is waiting on you right now', $crawler->text());
    }

    /** Every live component must be mounted (the new-task and task-actions modals are the dashboard's write exceptions, Slack modal the network exception). */
    public function testLiveComponentsAreMounted(): void
    {
        $crawler = $this->browser()->request('GET', '/');

        $names = $crawler->filter('[data-live-name-value]')
            ->each(static fn ($n) => $n->attr('data-live-name-value'));

        sort($names);
        $this->assertSame(['DispatchHealthBanner', 'NewTaskModal', 'PollProgress', 'RebaseLog', 'SlackModal', 'TaskActionsModal', 'TaskBoard'], $names);
    }

    /** The theme switcher must be mounted with its three state glyphs. */
    public function testThemeToggleIsMounted(): void
    {
        $crawler = $this->browser()->request('GET', '/');

        $toggle = $crawler->filter('[data-controller="theme"]');
        $this->assertSame(1, $toggle->count());
        $this->assertSame('theme#toggle', $toggle->attr('data-action'));
        // The bare token powers the CSS hide-everything base rule; without it
        // the glyphs are unrestrained and all three show at once.
        $this->assertSame(3, $crawler->filter('.pablo-theme-icon')->count());
        $this->assertSame(1, $crawler->filter('.pablo-theme-icon-light')->count());
        $this->assertSame(1, $crawler->filter('.pablo-theme-icon-dark')->count());
        $this->assertSame(1, $crawler->filter('.pablo-theme-icon-system')->count());
    }

    /**
     * One tick polls every due project, so there is exactly one countdown bar
     * however many projects are configured; per-project last-run times live in
     * the recap beside it.
     */
    public function testPollProgressShowsOneSharedCountdown(): void
    {
        (new \Pablo\Dispatch\Stamps())->write('wallet-kit', 'poll', (float) time(), 0.0);

        $crawler = $this->browser()->request('GET', '/');

        $poll = $crawler->filter('[data-controller="countdown"]');
        $this->assertSame(1, $poll->count());
        $this->assertSame(1, $crawler->filter('progress')->count());
        $this->assertNotEmpty($poll->attr('data-countdown-end-value'));
        $this->assertStringContainsString('Next poll', $poll->text());
    }

    public function testPollRecapListsEveryProjectWithItsLastRun(): void
    {
        (new \Pablo\Dispatch\Stamps())->write('wallet-kit', 'poll', (float) time(), 0.0);

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

    /** Each row links to the task's analytics history, which exists even before any event was logged. */
    public function testRowLinksToTheTaskHistory(): void
    {
        putenv('PABLO_ANALYTICS_DIR='.$this->tmp.'/analytics');
        $this->seed('feat/wk-5', State::Draft);
        $client = $this->browser();

        $crawler = $client->request('GET', '/');
        $link = $crawler->filter('a[href="/analytics/task/wallet-kit/feat/wk-5"]');
        $this->assertSame(1, $link->count());
        $this->assertSame('Task history of wallet-kit/feat/wk-5', $link->attr('aria-label'));

        $history = $client->request('GET', '/analytics/task/wallet-kit/feat/wk-5');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('No analytics recorded for this task yet.', $history->text());
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

    public function testAYoloTaskCarriesItsBadgeAndTheStepRunning(): void
    {
        $this->seed('wk-6', State::Draft);
        $task = new Task('wallet-kit', 'wk-7', $this->tmp.'/wt/wk-7', State::CiRed);
        $task->stateEnteredAt = '2026-08-01T10:00:00+00:00';
        $task->yolo = true;
        $task->yoloStep = \Pablo\Domain\Agent::YoloImplement;
        (new Store($this->tmp.'/state'))->save($task);

        $crawler = $this->browser()->request('GET', '/');

        $badges = $crawler->filter('.pablo-yolo');
        $this->assertSame(1, $badges->count());
        $this->assertSame('implement', trim($badges->text()));
        $this->assertStringContainsString('yolo-implement running', (string) $badges->attr('title'));
    }

    public function testTheNewTaskModalOffersTheYoloCheckbox(): void
    {
        $crawler = $this->browser()->request('GET', '/');

        $this->assertSame(1, $crawler->filter('input[type="checkbox"][data-model="yolo"]')->count());
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

        $modal = $crawler->filter('[data-live-name-value="SlackModal"]');
        $this->assertSame(0, $modal->filter('textarea')->count());
        $this->assertSame(0, $modal->filter('.modal.is-active')->count());
        // The new-task modal is rendered shut, too — open state lives in the component.
        $modal = $crawler->filter('[data-live-name-value="NewTaskModal"]');
        $this->assertSame(0, $modal->filter('.modal.is-active')->count());
    }

    /**
     * ?type= is hydrated into every live component on a full page load, so all
     * three sections agree on the filter without any tab clicks.
     */
    public function testUrlTypeFiltersAllThreeSections(): void
    {
        $this->seed('wk-draft', State::Draft, project: 'wallet-kit');
        $this->seed('bk-draft', State::Draft, project: 'bookkeeper');
        (new \Pablo\Dispatch\Stamps())->write('bookkeeper', 'poll', (float) time(), 0.0);

        $crawler = $this->browser()->request('GET', '/?type=open-source');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('bk-draft', $crawler->text());
        $this->assertStringNotContainsString('wk-draft', $crawler->text());
        $this->assertStringContainsString('bookkeeper', $crawler->text());
        $this->assertStringNotContainsString('wallet-kit', $crawler->text());
    }
}
