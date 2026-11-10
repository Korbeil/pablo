<?php

declare(strict_types=1);

namespace Pablo\Tests\Support;

use Pablo\Support\Numbers;
use PHPUnit\Framework\TestCase;

final class NumbersTest extends TestCase
{
    public function testNumericValuesAreCastAnythingElseIsZero(): void
    {
        $this->assertSame(42, Numbers::int('42'));
        $this->assertSame(3, Numbers::int(3.9));
        $this->assertSame(0, Numbers::int(null));
        $this->assertSame(0, Numbers::int('n/a'));
        $this->assertSame(0, Numbers::int([1]));

        $this->assertSame(1.5, Numbers::float('1.5'));
        $this->assertSame(2.0, Numbers::float(2));
        $this->assertSame(0.0, Numbers::float(null));
        $this->assertSame(0.0, Numbers::float('n/a'));
    }
}
