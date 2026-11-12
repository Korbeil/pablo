<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime\OpenCode;

use Pablo\Agents\Runtime\RuntimeOneShotInterface;
use Pablo\Support\Json;
use Pablo\Support\ProcessRunnerInterface;

/**
 * One `opencode run` on the free `opencode/big-pickle`, its session deleted
 * afterwards so it does not pollute the shared store (and OpenChamber's
 * session list).
 */
final class OpenCodeOneShot implements RuntimeOneShotInterface
{
    public const MODEL = 'opencode/big-pickle';

    private const DELETE_TIMEOUT_S = 15.0;

    public function model(): string
    {
        return self::MODEL;
    }

    /** @return list<string> */
    public static function argv(string $instruction): array
    {
        return ['opencode', 'run', '-m', self::MODEL, '--format', 'json', $instruction];
    }

    public function ask(ProcessRunnerInterface $runner, string $cwd, string $instruction, float $timeout): ?string
    {
        // OpenCode v2 removed `run --dir`; running from $cwd attaches the
        // one-shot session to that project instead of to whatever project the
        // pablo process itself sits in.
        try {
            $out = $runner->run(self::argv($instruction), check: false, timeout: $timeout, cwd: $cwd);
        } catch (\Throwable) {
            return null;
        }

        $result = self::parse($out);

        // Deleted once the answer is in, best-effort, whether or not it is usable.
        if (null !== $result['session']) {
            try {
                $runner->run(['opencode', 'session', 'delete', $result['session']], check: false, timeout: self::DELETE_TIMEOUT_S);
            } catch (\Throwable) {
            }
        }

        return $result['text'];
    }

    /**
     * Splits `opencode run --format json` output into the answer text
     * (concatenated `text` event parts) and the session id carried by
     * every event. Tolerates noise: non-JSON lines are skipped.
     *
     * @return array{text: ?string, session: ?string}
     */
    public static function parse(string $raw): array
    {
        $text = null;
        $session = null;
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ('' === $line || '{' !== $line[0]) {
                continue;
            }
            $ev = Json::tryDecodeArray($line);
            if (!\is_array($ev)) {
                continue;
            }
            if (\is_string($ev['sessionID'] ?? null) && '' !== $ev['sessionID']) {
                $session = $ev['sessionID'];
            }
            if ('text' === ($ev['type'] ?? null) && \is_string($ev['part']['text'] ?? null)) {
                $text = ($text ?? '').$ev['part']['text'];
            }
        }

        return ['text' => $text, 'session' => $session];
    }
}
