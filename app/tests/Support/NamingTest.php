<?php

declare(strict_types=1);

namespace Pablo\Tests;

use Pablo\Support\Naming;
use PHPUnit\Framework\TestCase;

final class NamingTest extends TestCase
{
    public function testBranchNameLowercases(): void
    {
        $this->assertSame('xxx-123', Naming::branchName('XXX', '123'));
        $this->assertSame('wk-45', Naming::branchName('WK', '45'));
    }

    public function testSlugBranchTruncatesAndSanitizes(): void
    {
        $this->assertSame(
            'wk-fix-the-callback-verification',
            Naming::slugBranch('WK', 'Fix the callback verification bug!'),
        );
    }

    public function testSlugBranchShortPrompt(): void
    {
        $this->assertSame('xxx-refactor-auth', Naming::slugBranch('XXX', 'Refactor auth'));
    }

    public function testSlugBranchStripsSymbols(): void
    {
        $this->assertSame('a-emit-weird-chars', Naming::slugBranch('A', 'Émit weird  ---   chars?!'));
    }

    public function testDedupeReturnsBaseWhenFree(): void
    {
        $this->assertSame('xxx-123', Naming::dedupe('xxx-123', []));
    }

    public function testDedupeAppendsSuffixes(): void
    {
        $this->assertSame('xxx-123-2', Naming::dedupe('xxx-123', ['xxx-123']));
        $this->assertSame('xxx-123-3', Naming::dedupe('xxx-123', ['xxx-123', 'xxx-123-2']));
    }
}
