<?php

declare(strict_types=1);

namespace Pablo\Tests\Provider\Tracker;

use Pablo\Config\ProjectConfig;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Provider\Git\GitRepo;
use Pablo\Provider\Tracker\Github;
use Pablo\Provider\Tracker\ProviderRegistry;
use Pablo\Support\PabloError;
use Pablo\Support\Proc;
use PHPUnit\Framework\TestCase;

final class GithubTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-gh-'.uniqid();
        mkdir($this->tmp, 0o777, true);
        GitRepo::setOriginUrl(static fn (string $repo): string => 'git@github.com:acme/wallet-kit.git');
        Proc::setRunner(function (array $argv, bool $check = true, ?float $timeout = null): string {
            $joined = implode(' ', $argv);
            $responses = $this->responses();
            foreach ($responses as $key => $out) {
                if (str_contains($joined, $key)) {
                    return $out;
                }
            }
            throw new \LogicException('unexpected CLI call: '.$joined);
        });
    }

    protected function tearDown(): void
    {
        GitRepo::setOriginUrl(null);
        Proc::setRunner(null);
    }

    /** @var array<string, string> */
    private array $canned = [];

    /** @return array<string, string> */
    private function responses(): array
    {
        return $this->canned;
    }

    /** @param array<string, string> $responses */
    private function configure(array $responses): void
    {
        $this->canned = $responses;
    }

    private function cfg(?string $issueRepo = null): ProjectConfig
    {
        return new ProjectConfig(
            name: 'wallet-kit',
            type: 'open-source',
            repoPath: $this->tmp,
            primaryBranch: 'main',
            worktreesRoot: $this->tmp.'/wt',
            provider: 'github',
            identity: 'octocat',
            projectKey: 'WK',
            issueRepo: $issueRepo,
            syncStrategy: 'rebase',
            syncAutoApply: false,
            syncInterval: 30,
            pollInterval: 10,
            failureSignal: 'qa-failed',
            botWhitelist: [],
            ciIgnoreChecks: [],
            defaultModel: 'openrouter/test/model',
            prDescriptionLocale: 'en',
        );
    }

    public function testGetProviderUnknown(): void
    {
        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('unknown provider');
        ProviderRegistry::get('gitlab');
    }

    public function testMatchUrlSameRepo(): void
    {
        $provider = new Github();
        $this->assertSame('45', $provider->matchUrl('https://github.com/acme/wallet-kit/issues/45', $this->cfg()));
    }

    public function testMatchUrlOtherRepoReturnsNull(): void
    {
        GitRepo::setOriginUrl(static fn () => 'https://github.com/acme/wallet-kit.git');
        $provider = new Github();
        $this->assertNull($provider->matchUrl('https://github.com/acme/other/issues/45', $this->cfg()));
        $this->assertNull($provider->matchUrl('https://example.com/x', $this->cfg()));
    }

    public function testTrackerSlugFallbackToOrigin(): void
    {
        $this->assertSame('acme/wallet-kit', (new Github())->trackerSlug($this->cfg()));
    }

    public function testTrackerSlugUsesConfiguredRepo(): void
    {
        $this->assertSame('acme/upstream', (new Github())->trackerSlug($this->cfg('acme/upstream')));
    }

    public function testMatchUrlUsesConfiguredTrackerRepo(): void
    {
        $provider = new Github();
        $this->assertSame('2333', $provider->matchUrl('https://github.com/afup/web/issues/2333', $this->cfg('afup/web')));
    }

    public function testGetIssueParses(): void
    {
        $this->configure(['issue view 45' => json_encode([
            'number' => 45,
            'title' => 'Fix callback verification',
            'state' => 'OPEN',
            'url' => 'https://github.com/acme/wallet-kit/issues/45',
        ], \JSON_THROW_ON_ERROR)]);
        $issue = (new Github())->getIssue('45', $this->cfg());
        $this->assertSame('45', $issue->key);
        $this->assertSame('Fix callback verification', $issue->title);
        $this->assertSame('WK', $issue->projectKey);
    }

    public function testListAssignedParses(): void
    {
        $this->configure(['issue list' => json_encode([
            ['number' => 1, 'title' => 'A', 'state' => 'OPEN', 'url' => 'u1'],
            ['number' => 2, 'title' => 'B', 'state' => 'CLOSED', 'url' => 'u2'],
        ], \JSON_THROW_ON_ERROR)]);
        $issues = (new Github())->listAssigned($this->cfg());
        $this->assertSame(['1', '2'], array_map(static fn ($i) => $i->key, $issues));
    }

    public function testFailureSignalFiltersLabelAndOrders(): void
    {
        $this->configure(['issues/7/events' => json_encode([
            ['event' => 'labeled', 'label' => ['name' => 'qa-failed'], 'created_at' => '2026-07-20T10:00:00Z'],
            ['event' => 'labeled', 'label' => ['name' => 'other'], 'created_at' => '2026-07-21T10:00:00Z'],
            ['event' => 'closed', 'created_at' => '2026-07-22T10:00:00Z'],
            ['event' => 'labeled', 'label' => ['name' => 'qa-failed'], 'created_at' => '2026-07-19T10:00:00Z'],
        ], \JSON_THROW_ON_ERROR)]);
        $task = new Task('wallet-kit', 'wk-45', $this->tmp, State::NeedsTesting);
        $task->prNumber = 7;
        $stamps = (new Github())->failureSignalEvents($task, $this->cfg());
        $this->assertCount(2, $stamps);
        $this->assertEquals(new \DateTimeImmutable('2026-07-20T10:00:00Z'), $stamps[\count($stamps) - 1]);
    }

    public function testRunCliErrorSurfacesStderr(): void
    {
        Proc::setRunner(null);
        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('gh auth login');
        Proc::run(['sh', '-c', "echo 'gh: To get started with GitHub CLI, run gh auth login' >&2; exit 4"], check: true);
    }
}
