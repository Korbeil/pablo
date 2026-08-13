<?php

declare(strict_types=1);

namespace Pablo\Tests\Provider\Confluence;

use Pablo\Config\ProjectConfig;
use Pablo\Provider\Confluence\Confluence;
use Pablo\Support\PabloError;
use Pablo\Support\Proc;
use PHPUnit\Framework\TestCase;

final class ConfluenceTest extends TestCase
{
    private const PAGE_JSON = [
        'id' => '36307094',
        'title' => 'PIM —Accueil',
        '_links' => ['base' => 'https://acme.atlassian.net/wiki', 'webui' => '/spaces/PIM/overview'],
        'body' => ['storage' => ['representation' => 'storage', 'value' => '<p>Espace dédié aux spécifications…</p>']],
    ];

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-conf-'.uniqid();
        mkdir($this->tmp, 0o777, true);
    }

    protected function tearDown(): void
    {
        Proc::setRunner(null);
    }

    private function cfg(): ProjectConfig
    {
        return new ProjectConfig(
            name: 'acme-pim',
            type: 'work',
            repoPath: $this->tmp,
            primaryBranch: 'main',
            worktreesRoot: $this->tmp.'/wt',
            provider: 'jira',
            identity: 'acme@example.com',
            projectKey: 'PIM',
            syncStrategy: 'rebase',
            syncAutoApply: false,
            syncInterval: 30,
            pollInterval: 10,
            failureSignal: null,
            botWhitelist: [],
            ciIgnoreChecks: [],
            site: 'acme.atlassian.net',
            confluenceSpace: 'PIM',
        );
    }

    public function testMatchUrlExtractsIdFromPagesPath(): void
    {
        $url = 'https://acme.atlassian.net/wiki/spaces/PIM/pages/36307094/PIM+Home';
        $this->assertSame('36307094', Confluence::matchUrl($url));
    }

    public function testMatchUrlExtractsIdFromQuery(): void
    {
        $this->assertSame('36307094', Confluence::matchUrl('https://acme.atlassian.net/wiki?pageId=36307094'));
    }

    public function testMatchUrlReturnsNullForNonConfluence(): void
    {
        $this->assertNull(Confluence::matchUrl('https://acme.atlassian.net/browse/PIM-1'));
        $this->assertNull(Confluence::matchUrl('not a url'));
    }

    public function testFetchByBareId(): void
    {
        $calls = [];
        Proc::setRunner(static function (array $argv) use (&$calls): string {
            $calls[] = $argv;

            return json_encode(self::PAGE_JSON, \JSON_THROW_ON_ERROR);
        });
        $page = Confluence::fetch('36307094', $this->cfg());
        $this->assertSame('36307094', $page->id);
        $this->assertSame('PIM —Accueil', $page->title);
        $this->assertSame('https://acme.atlassian.net/wiki/spaces/PIM/overview', $page->url);
        $this->assertStringContainsString('Espace dédié', $page->body);
        $argv = $calls[0];
        $this->assertSame('acli', $argv[0]);
        $this->assertContains('page', $argv);
        $this->assertContains('view', $argv);
        $this->assertSame('36307094', $argv[array_search('--id', $argv, true) + 1]);
        $this->assertContains('--json', $argv);
        $this->assertSame('storage', $argv[array_search('--body-format', $argv, true) + 1]);
    }

    public function testFetchByUrlParsesId(): void
    {
        $calls = [];
        Proc::setRunner(static function (array $argv) use (&$calls): string {
            $calls[] = $argv;

            return json_encode(self::PAGE_JSON, \JSON_THROW_ON_ERROR);
        });
        $page = Confluence::fetch('https://acme.atlassian.net/wiki/spaces/PIM/pages/36307094/PIM+Home', $this->cfg());
        $this->assertSame('36307094', $calls[0][array_search('--id', $calls[0], true) + 1]);
        $this->assertSame('36307094', $page->id);
    }

    public function testFetchRejectsEmpty(): void
    {
        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('no page id');
        Confluence::fetch('   ', $this->cfg());
    }

    public function testFetchRejectsUnparseableUrl(): void
    {
        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('not a page id or Confluence URL');
        Confluence::fetch('https://acme.atlassian.net/browse/PIM-1', $this->cfg());
    }

    public function testFetchSurfacesUnexpectedResponse(): void
    {
        Proc::setRunner(static fn (array $argv): string => json_encode(['nope' => true], \JSON_THROW_ON_ERROR));
        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('unexpected acli response');
        Confluence::fetch('36307094', $this->cfg());
    }

    public function testFetchHandlesMissingBody(): void
    {
        $payload = self::PAGE_JSON;
        $payload['body'] = null;
        Proc::setRunner(static fn (array $argv): string => json_encode($payload, \JSON_THROW_ON_ERROR));
        $page = Confluence::fetch('36307094', $this->cfg());
        $this->assertSame('', $page->body);
    }

    public function testCliNameAndAuthCheck(): void
    {
        $this->assertSame('acli', Confluence::cliName());
        $this->assertSame(['acli', 'confluence', 'auth', 'status'], Confluence::authCheckCmd());
    }
}
