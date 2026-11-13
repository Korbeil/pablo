<?php

declare(strict_types=1);

namespace Pablo\Dispatch;

/**
 * Whether a job's failure text looks like an API rate limit: GitHub's primary
 * ("API rate limit exceeded") and secondary ("You have exceeded a secondary
 * rate limit", formerly "abuse detection") limits as `gh` reports them, an
 * HTTP 429 ("Too Many Requests"), Linear's RATELIMITED error code.
 *
 * Only failure text is read — the job's error, its stderr, and the stdout
 * lines reporting a failure (`<branch>: poll check failed: …`): a branch name
 * such as `fix-rate-limit` in an ordinary event line is not a signal. A false
 * positive only makes the rest of the tick sequential, i.e. what the
 * dispatcher did before it polled projects concurrently.
 */
final class RateLimitSignal
{
    private const PATTERNS = [
        '/\brate limit/i',
        '/\bratelimit(ed)?\b/i',
        '/abuse detection/i',
        '/too many requests/i',
        '/\bHTTP 429\b/i',
    ];

    public static function in(WorkerOutcome $outcome): bool
    {
        $texts = [$outcome->ok() ? '' : $outcome->error(), $outcome->errorOutput];
        foreach (preg_split('/\R/', $outcome->output) ?: [] as $line) {
            if (1 === preg_match('/\b(failed|error)\b/i', $line)) {
                $texts[] = $line;
            }
        }

        return self::matches(implode("\n", $texts));
    }

    public static function matches(string $text): bool
    {
        foreach (self::PATTERNS as $pattern) {
            if (1 === preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }
}
