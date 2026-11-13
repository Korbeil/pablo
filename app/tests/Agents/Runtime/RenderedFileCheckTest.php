<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents\Runtime;

use Pablo\Agents\AgentTemplateError;
use Pablo\Agents\Runtime\RenderedFileCheck;
use Pablo\Doctor\AgentFileState;
use Pablo\Tests\TempDir;
use PHPUnit\Framework\TestCase;

final class RenderedFileCheckTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = TempDir::create('rendered-check');
        mkdir($this->tmp.'/skill', 0o777, true);
        file_put_contents($this->tmp.'/file.md', 'fresh');
        file_put_contents($this->tmp.'/skill/SKILL.md', 'fresh');
        symlink($this->tmp.'/file.md', $this->tmp.'/link.md');
        symlink($this->tmp.'/skill', $this->tmp.'/link-dir');
    }

    protected function tearDown(): void
    {
        foreach (['link.md', 'link-dir', 'file.md', 'skill/SKILL.md'] as $file) {
            @unlink($this->tmp.'/'.$file);
        }
        @rmdir($this->tmp.'/skill');
        @rmdir($this->tmp);
    }

    public function testComparesALinkedFileAndALinkedDirectorysFileWithTheRender(): void
    {
        $this->assertSame(AgentFileState::Ok, RenderedFileCheck::linked('a', $this->tmp.'/link.md', static fn (): string => 'fresh')->state);
        $this->assertSame(AgentFileState::Stale, RenderedFileCheck::linked('a', $this->tmp.'/link.md', static fn (): string => 'new')->state);
        $this->assertSame(AgentFileState::Ok, RenderedFileCheck::linked('s', $this->tmp.'/link-dir', static fn (): string => 'fresh', 'SKILL.md')->state);
    }

    public function testComparesAGeneratedFileWithTheRender(): void
    {
        $this->assertSame(AgentFileState::Ok, RenderedFileCheck::generated('g', $this->tmp.'/file.md', static fn (): string => 'fresh')->state);
        $this->assertSame(AgentFileState::Missing, RenderedFileCheck::generated('g', $this->tmp.'/none.md', static fn (): string => 'fresh')->state);
    }

    public function testAFailedRenderIsUnverifiable(): void
    {
        $fail = static fn (): string => throw new AgentTemplateError('broken template');

        foreach ([
            RenderedFileCheck::linked('a', $this->tmp.'/link.md', $fail),
            RenderedFileCheck::generated('g', $this->tmp.'/file.md', $fail),
        ] as $status) {
            $this->assertSame(AgentFileState::Unverifiable, $status->state);
            $this->assertSame('cannot verify freshness: broken template', $status->detail);
        }
    }
}
