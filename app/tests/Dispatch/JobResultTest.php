<?php

declare(strict_types=1);

namespace Pablo\Tests\Dispatch;

use Pablo\Dispatch\JobResult;
use PHPUnit\Framework\TestCase;

final class JobResultTest extends TestCase
{
    public function testTheResultLineRoundTripsAndIsCutOutOfTheOutput(): void
    {
        $stdout = "[a] wk-1: draft → ci-red\n[a] wk-2: poll check failed: boom\n".(new JobResult(null, 1))->line();

        [$rest, $result] = JobResult::extract($stdout);

        $this->assertSame("[a] wk-1: draft → ci-red\n[a] wk-2: poll check failed: boom\n", $rest);
        $this->assertNotNull($result);
        $this->assertTrue($result->ok());
        $this->assertSame(1, $result->checkFailures);
    }

    public function testAFailure(): void
    {
        [$rest, $result] = JobResult::extract((new JobResult("gh failed: \"quoted\"\nsecond line"))->line());

        $this->assertSame('', $rest);
        $this->assertNotNull($result);
        $this->assertFalse($result->ok());
        $this->assertSame("gh failed: \"quoted\"\nsecond line", $result->error);
    }

    public function testNoResultLineMeansNoResult(): void
    {
        $this->assertSame(['[a] partial output', null], JobResult::extract('[a] partial output'));
        $this->assertSame(['', null], JobResult::extract(''));
    }

    public function testTheMarkerMidLineIsNotAResult(): void
    {
        $stdout = '[a] an event mentioning @pablo-dispatch-result {"error":null}'."\n";

        $this->assertSame([$stdout, null], JobResult::extract($stdout));
    }

    public function testATruncatedResultLineIsNoResult(): void
    {
        $line = (new JobResult(null))->line();
        $cut = substr($line, 0, -6);

        [$rest, $result] = JobResult::extract($cut);

        $this->assertNull($result);
        $this->assertSame($cut, $rest);
    }
}
