<?php

declare(strict_types=1);

namespace Pablo\Tests\Analytics;

use Pablo\Analytics\AnalyticsEvent;
use PHPUnit\Framework\TestCase;

final class AnalyticsEventTest extends TestCase
{
    public function testTimestampParsesTheTsAndIsZeroWhenMissingOrMalformed(): void
    {
        $this->assertSame(1_767_225_600, AnalyticsEvent::fromEvent(['ts' => '2026-01-01T00:00:00+00:00'])->timestamp());
        $this->assertSame(1_767_225_600, AnalyticsEvent::fromEvent(['ts' => '2026-01-01T01:00:00+01:00'])->timestamp());
        $this->assertSame(1_767_225_600, AnalyticsEvent::fromEvent(['ts' => '2026-01-01T00:00:00Z'])->timestamp());
        $this->assertSame(0, AnalyticsEvent::fromEvent(['ts' => 'not a date'])->timestamp());
    }
}
