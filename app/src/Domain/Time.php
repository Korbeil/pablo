<?php

declare(strict_types=1);

namespace Pablo\Domain;

final class Time
{
    /**
     * UTC ISO-8601 with second precision, e.g. "2026-08-04T12:00:00+00:00".
     * Matches Python's datetime.now(timezone.utc).isoformat(timespec='seconds').
     */
    public function utcnow(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:sP');
    }

    /**
     * Parse an ISO-8601 timestamp (accepting a trailing Z) as UTC.
     */
    public function parseTs(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value);
    }
}
