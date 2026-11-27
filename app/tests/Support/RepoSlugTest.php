<?php

declare(strict_types=1);

namespace Pablo\Tests\Support;

use Pablo\Config\ProjectConfig;
use Pablo\Config\RepoConfig;
use Pablo\Config\StatePollingConfig;
use Pablo\Config\SyncConfig;
use Pablo\Config\TrackerConfig;
use Pablo\Provider\Forge\RepoRef;
use Pablo\Support\PabloError;
use Pablo\Support\RepoSlug;
use Pablo\Tests\FakeGit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RepoSlugTest extends TestCase
{
    /** @return iterable<string, array{string, ?array{0: string, 1: string}}> */
    public static function remotes(): iterable
    {
        yield 'github scp' => ['git@github.com:acme/wallet-kit.git', ['github.com', 'acme/wallet-kit']];
        yield 'github https' => ['https://github.com/acme/wallet-kit', ['github.com', 'acme/wallet-kit']];
        yield 'gitlab nested groups' => ['git@gitlab.com:acme/platform/api.git', ['gitlab.com', 'acme/platform/api']];
        yield 'ssh with port' => ['ssh://git@git.example.com:2222/team/app.git', ['git.example.com', 'team/app']];
        yield 'https with user' => ['https://oauth2:token@gitlab.acme.io/a/b/c.git/', ['gitlab.acme.io', 'a/b/c']];
        yield 'local path' => ['/srv/git/repo.git', null];
    }

    /** @param array{0: string, 1: string}|null $expected */
    #[DataProvider('remotes')]
    public function testParseRemote(string $url, ?array $expected): void
    {
        $this->assertSame($expected, RepoRef::parseRemote($url));
    }

    public function testForgeOfHost(): void
    {
        $this->assertSame('github', RepoRef::forgeOfHost('github.com'));
        $this->assertSame('gitlab', RepoRef::forgeOfHost('gitlab.com'));
        $this->assertSame('gitlab', RepoRef::forgeOfHost('GitLab.Acme.io'));
        $this->assertSame('gitea', RepoRef::forgeOfHost('gitea.com'));
        $this->assertSame('gitea', RepoRef::forgeOfHost('Gitea.Acme.io'));
        $this->assertNull(RepoRef::forgeOfHost('git.example.com'));
    }

    public function testPrUrls(): void
    {
        $this->assertSame('https://github.com/acme/web/pull/4', (new RepoRef('github', 'github.com', 'acme/web'))->prUrl(4));
        $this->assertSame('https://gitlab.acme.io/a/b/web/-/merge_requests/4', (new RepoRef('gitlab', 'gitlab.acme.io', 'a/b/web'))->prUrl(4));
        $this->assertSame('https://gitea.acme.io/acme/web/pulls/4', (new RepoRef('gitea', 'gitea.acme.io', 'acme/web'))->prUrl(4));
    }

    public function testGithubOriginKeepsOwnerAndRepo(): void
    {
        $ref = $this->ref('git@github.com:acme/wallet-kit.git');
        $this->assertEquals(new RepoRef('github', 'github.com', 'acme/wallet-kit'), $ref);
    }

    public function testGitlabOriginKeepsTheFullPath(): void
    {
        $ref = $this->ref('git@gitlab.com:acme/platform/api.git');
        $this->assertEquals(new RepoRef('gitlab', 'gitlab.com', 'acme/platform/api'), $ref);
    }

    public function testASelfHostedGitlabNeedsRepoForge(): void
    {
        try {
            $this->ref('git@git.example.com:team/app.git');
            $this->fail('an unknown host must be refused');
        } catch (PabloError $e) {
            $this->assertStringContainsString('repo.forge: gitlab', $e->getMessage());
        }
        $this->assertEquals(new RepoRef('gitlab', 'git.example.com', 'team/app'), $this->ref('git@git.example.com:team/app.git', forge: 'gitlab'));
    }

    public function testGiteaOriginKeepsOwnerAndRepo(): void
    {
        $this->assertEquals(new RepoRef('gitea', 'gitea.com', 'acme/api'), $this->ref('git@gitea.com:acme/api.git'));
        // an instance under a sub-path: the slug is the path's last two segments
        $this->assertEquals(new RepoRef('gitea', 'git.example.com', 'team/app'), $this->ref('https://git.example.com/gitea/team/app.git', forge: 'gitea'));
    }

    public function testGithubForgeOnAnotherHostIsRefused(): void
    {
        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('not a GitHub URL');
        $this->ref('git@gitlab.com:acme/app.git', forge: 'github');
    }

    public function testTheUpstreamSlugWins(): void
    {
        $this->assertEquals(new RepoRef('gitlab', 'gitlab.com', 'upstream/api'), $this->ref('git@gitlab.com:me/api.git', upstream: 'upstream/api'));
        $this->assertEquals(new RepoRef('github', 'github.com', 'acme/lib'), $this->ref('git@github.com:me/lib.git', upstream: 'acme/lib'));
    }

    public function testAnUpstreamWithoutOriginStaysOnGithub(): void
    {
        $this->assertEquals(new RepoRef('github', 'github.com', 'acme/lib'), $this->ref(null, upstream: 'acme/lib'));
        $this->assertEquals(new RepoRef('gitlab', 'gitlab.com', 'acme/lib'), $this->ref(null, upstream: 'acme/lib', forge: 'gitlab'));
    }

    public function testAnUpstreamWithAMirrorOriginStaysOnGithub(): void
    {
        $this->assertEquals(new RepoRef('github', 'github.com', 'acme/lib'), $this->ref('git@mirror.local:acme/lib.git', upstream: 'acme/lib'));
    }

    public function testNoOriginNoUpstreamFails(): void
    {
        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('has no origin remote');
        $this->ref(null);
    }

    private function ref(?string $origin, ?string $upstream = null, ?string $forge = null): RepoRef
    {
        $git = new FakeGit();
        $git->originUrl = $origin;

        return (new RepoSlug($git))->ref(new ProjectConfig(
            name: 'p',
            type: 'work',
            repo: new RepoConfig(path: '/tmp/p', primaryBranch: 'main', worktreesRoot: '/tmp/wt', forge: $forge),
            tracker: new TrackerConfig(provider: 'jira', identity: 'me', projectKey: 'P', repo: $upstream),
            sync: new SyncConfig(strategy: 'rebase', autoApply: false, intervalMinutes: 30),
            statePolling: new StatePollingConfig(10),
            defaultModel: 'm',
            prDescriptionLocale: 'en',
        ));
    }
}
