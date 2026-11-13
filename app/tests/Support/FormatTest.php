<?php

declare(strict_types=1);

namespace Pablo\Tests\Support;

use Pablo\Support\Format;
use PHPUnit\Framework\TestCase;

final class FormatTest extends TestCase
{
    public function testDuration(): void
    {
        $this->assertSame('-', Format::duration(-1));
        $this->assertSame('-', Format::duration(30, noData: true));
        $this->assertSame('45s', Format::duration(45));
        $this->assertSame('12m', Format::duration(12 * 60 + 5));
        $this->assertSame('2h', Format::duration(7_200));
        $this->assertSame('1h20m', Format::duration(4_800));
        $this->assertSame('50h', Format::duration(50 * 3_600));
        $this->assertSame('2d2h', Format::duration(50 * 3_600, true));
        $this->assertSame('47h', Format::duration(47 * 3_600, true));
    }

    public function testTokens(): void
    {
        $this->assertSame('999', Format::tokens(999));
        $this->assertSame('1.5k', Format::tokens(1_500));
        $this->assertSame('12k', Format::tokens(12_345));
        $this->assertSame('2.5M', Format::tokens(2_500_000));
    }
}
