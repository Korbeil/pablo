<?php

declare(strict_types=1);

namespace Pablo\Dispatch;

use Pablo\Support\Json;

/**
 * How a dispatcher job ended in a child process, sent back to the parent as
 * the last line of the child's stdout (`internal:dispatch-job`), then read
 * out of it — the rest of the output is the job's own, printed as-is.
 */
final readonly class JobResult
{
    private const MARKER = '@pablo-dispatch-result ';

    /**
     * @param string|null $error         the job's failure (an exception's message), null on success
     * @param int         $checkFailures per-task checks that failed during a successful run
     */
    public function __construct(
        public ?string $error,
        public int $checkFailures = 0,
    ) {
    }

    public function ok(): bool
    {
        return null === $this->error;
    }

    /** The line the child prints last (with its newline). */
    public function line(): string
    {
        return self::MARKER.json_encode(['error' => $this->error, 'check_failures' => $this->checkFailures], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE)."\n";
    }

    /**
     * Split a child's stdout into its own output and its result line: null
     * when it has none (the child crashed or was killed before printing it).
     *
     * @return array{0: string, 1: ?self}
     */
    public static function extract(string $stdout): array
    {
        $at = strrpos($stdout, self::MARKER);
        if (false === $at || ($at > 0 && "\n" !== $stdout[$at - 1])) {
            return [$stdout, null];
        }
        $end = strpos($stdout, "\n", $at);
        $json = substr($stdout, $at + \strlen(self::MARKER), false === $end ? null : $end - $at - \strlen(self::MARKER));
        $data = Json::tryDecodeArray($json);
        if (null === $data) {
            return [$stdout, null];
        }
        $rest = substr($stdout, 0, $at).(false === $end ? '' : substr($stdout, $end + 1));
        $error = $data['error'] ?? null;
        $checks = $data['check_failures'] ?? 0;

        return [$rest, new self(\is_string($error) ? $error : null, \is_int($checks) ? $checks : 0)];
    }
}
