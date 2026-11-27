<?php

declare(strict_types=1);

namespace Pablo\Tests\Provider\Forge;

use Pablo\Config\ProjectConfig;
use Pablo\Config\RepoConfig;
use Pablo\Config\StatePollingConfig;
use Pablo\Config\SyncConfig;
use Pablo\Config\TrackerConfig;
use Pablo\Domain\Time;
use Pablo\Provider\Forge\ForgeRegistry;
use Pablo\Provider\Forge\Gitea\GiteaForge;
use Pablo\Provider\Forge\Github\GithubForge;
use Pablo\Provider\Forge\Gitlab\GitlabForge;
use Pablo\Support\PabloError;
use Pablo\Support\RepoSlug;
use Pablo\Tests\FakeGit;
use Pablo\Tests\FakeProcessRunner;
use PHPUnit\Framework\TestCase;

final class ForgeRegistryTest extends TestCase
{
    private ForgeRegistry $forges;

    protected function setUp(): void
    {
        $git = new FakeGit();
        $git->originUrl = static fn (string $repo): ?string => [
            '/r/gh' => 'git@github.com:acme/web.git',
            '/r/gl' => 'git@gitlab.com:acme/web.git',
            '/r/gl2' => 'git@gitlab.com:acme/api.git',
            '/r/self' => 'https://git.acme.io/team/app.git',
            '/r/odd' => 'https://git.acme.io/team/app.git',
            '/r/gt' => 'git@gitea.com:acme/web.git',
            '/r/gt2' => 'https://gitea.acme.io/acme/api.git',
        ][$repo] ?? null;
        $runner = new FakeProcessRunner();
        $this->forges = new ForgeRegistry(new GithubForge($runner, new Time()), new GitlabForge($runner), new GiteaForge($runner), new RepoSlug($git));
    }

    private static function cfg(string $repo, ?string $forge = null): ProjectConfig
    {
        return new ProjectConfig(
            name: basename($repo),
            type: 'work',
            repo: new RepoConfig(path: $repo, primaryBranch: 'main', worktreesRoot: '/wt', forge: $forge),
            tracker: new TrackerConfig(provider: 'jira', identity: 'me', projectKey: 'P'),
            sync: new SyncConfig(strategy: 'rebase', autoApply: false, intervalMinutes: 30),
            statePolling: new StatePollingConfig(10),
            defaultModel: 'm',
            prDescriptionLocale: 'en',
        );
    }

    public function testEachProjectGetsItsForge(): void
    {
        $this->assertInstanceOf(GithubForge::class, $this->forges->for(self::cfg('/r/gh')));
        $gitlab = $this->forges->for(self::cfg('/r/gl'));
        $this->assertInstanceOf(GitlabForge::class, $gitlab);
        $this->assertSame('gitlab.com', $gitlab->host());
    }

    public function testOneGitlabForgePerHost(): void
    {
        $this->assertSame($this->forges->for(self::cfg('/r/gl')), $this->forges->for(self::cfg('/r/gl2')));
        $selfHosted = $this->forges->for(self::cfg('/r/self', 'gitlab'));
        $this->assertInstanceOf(GitlabForge::class, $selfHosted);
        $this->assertSame('git.acme.io', $selfHosted->host());
    }

    public function testGiteaProjectsGetAGiteaForgePerHost(): void
    {
        $gitea = $this->forges->for(self::cfg('/r/gt'));
        $this->assertInstanceOf(GiteaForge::class, $gitea);
        $this->assertSame('gitea.com', $gitea->host());
        $other = $this->forges->for(self::cfg('/r/gt2'));
        $this->assertInstanceOf(GiteaForge::class, $other);
        $this->assertSame('gitea.acme.io', $other->host());
        $selfHosted = $this->forges->for(self::cfg('/r/self', 'gitea'));
        $this->assertInstanceOf(GiteaForge::class, $selfHosted);
        $this->assertSame('git.acme.io', $selfHosted->host());
        $this->assertSame($selfHosted, $this->forges->for(self::cfg('/r/odd', 'gitea')));
    }

    public function testAnUnknownHostWithoutRepoForgeFails(): void
    {
        $this->expectException(PabloError::class);
        $this->forges->for(self::cfg('/r/odd'));
    }
}
