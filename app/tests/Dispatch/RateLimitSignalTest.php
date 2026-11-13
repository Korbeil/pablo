<?php

declare(strict_types=1);

namespace Pablo\Tests\Dispatch;

use Pablo\Dispatch\JobResult;
use Pablo\Dispatch\PoolJob;
use Pablo\Dispatch\RateLimitSignal;
use Pablo\Dispatch\WorkerOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RateLimitSignalTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function rateLimited(): iterable
    {
        yield 'gh primary' => ['gh failed: API rate limit exceeded for user ID 123.'];
        yield 'gh graphql primary' => ['gh failed: GraphQL: API rate limit already exceeded for user ID 123.'];
        yield 'gh secondary' => ['gh failed: HTTP 403: You have exceeded a secondary rate limit. Please wait a few minutes before you try again.'];
        yield 'abuse detection' => ['gh failed: You have triggered an abuse detection mechanism.'];
        yield 'HTTP 429' => ['acli failed: HTTP 429'];
        yield 'too many requests' => ['acli failed: 429 Too Many Requests'];
        yield 'linear' => ['linear failed: RATELIMITED'];
    }

    #[DataProvider('rateLimited')]
    public function testAFailedJobRateLimited(string $error): void
    {
        $this->assertTrue(RateLimitSignal::in($this->outcome('', '', new JobResult($error))));
    }

    #[DataProvider('rateLimited')]
    public function testAFailedTaskCheckRateLimited(string $error): void
    {
        // The job worked, but a task's check hit the limit (Poller reports it as an event).
        $outcome = $this->outcome("[a] wk-1: ci-red\n[a] wk-2: poll check failed: {$error}\n", '', new JobResult(null, 1));

        $this->assertTrue(RateLimitSignal::in($outcome));
    }

    public function testACrashedWorkerStderr(): void
    {
        $this->assertTrue(RateLimitSignal::in($this->outcome('', "PabloError: gh failed: API rate limit exceeded\n", null, 'poll worker exited with code 1 without a result')));
    }

    public function testOrdinaryOutputIsNot(): void
    {
        $outcome = $this->outcome("[a] fix-rate-limit: draft → ready-to-review\n[a] ratelimit-429: ci-red\n", '', new JobResult(null));

        $this->assertFalse(RateLimitSignal::in($outcome));
        $this->assertFalse(RateLimitSignal::in($this->outcome('', '', new JobResult('gh failed: HTTP 502'))));
    }

    private function outcome(string $stdout, string $stderr, ?JobResult $result, ?string $failure = null): WorkerOutcome
    {
        return new WorkerOutcome(new PoolJob('a', 'poll', '/repo'), $stdout, $stderr, $result, 0.1, $failure);
    }
}
