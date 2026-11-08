<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime\Copilot;

use Pablo\Analytics\PromptFingerprint;
use Pablo\Analytics\UsageHarvesterInterface;
use Pablo\Analytics\UsageSnapshot;
use Pablo\Domain\Time;

/**
 * Harvests token usage for one GitHub Copilot CLI run from its local
 * session state.
 *
 * Copilot writes one event log per session under
 * <COPILOT_HOME or ~/.copilot>/session-state/<session uuid>/events.jsonl.
 * Candidates are the logs touched since the launch (minus a small grace)
 * whose first event is not older than that, and whose recorded working
 * directory, when present, is the worktree. With a prompt fingerprint, the
 * one whose first user message hashes to it is the run ("exact"); without
 * one, every candidate run in the worktree is merged ("window"). Nothing
 * matched means nothing is recorded, never a guess.
 *
 * Tokens come from the latest `session.shutdown` (or
 * `session.usage_checkpoint`) event's `data.modelMetrics.<model>.usage`,
 * whose totals are cumulative. That format is not documented by GitHub, so
 * every field is read defensively. Cost is recorded as 0, like Claude Code:
 * a Copilot plan bills credits, not a per-run price.
 */
final class CopilotUsage implements UsageHarvesterInterface
{
    private const SESSION_GRACE_S = 5;
    private const MAX_SESSIONS = 8;

    /** Event types carrying cumulative usage totals. */
    private const USAGE_EVENTS = ['session.shutdown', 'session.usage_checkpoint'];

    public function __construct(
        private readonly Time $time = new Time(),
        private readonly ?string $configDir = null,
    ) {
    }

    /** COPILOT_HOME, else ~/.copilot; resolved per call. */
    public function configDir(): string
    {
        return $this->configDir ?? CopilotRuntime::userConfigDir();
    }

    public function harvest(string $worktree, string $launchedAt, ?string $fingerprint): ?UsageSnapshot
    {
        try {
            $windowStartS = $this->time->parseTs($launchedAt)->getTimestamp() - self::SESSION_GRACE_S;
            $worktree = rtrim($worktree, '/');
            $candidates = [];
            foreach (glob($this->configDir().'/session-state/*/events.jsonl') ?: [] as $path) {
                if ((int) @filemtime($path) < $windowStartS) {
                    continue;
                }
                $session = $this->read($path);
                if (null === $session || $session['startedS'] < $windowStartS) {
                    continue;
                }
                if (null !== $session['cwd'] && rtrim($session['cwd'], '/') !== $worktree) {
                    continue;
                }
                $candidates[] = $session;
            }
            if ([] === $candidates) {
                return null;
            }
            usort($candidates, static fn (array $a, array $b): int => $a['startedS'] <=> $b['startedS']);

            if (null !== $fingerprint && '' !== $fingerprint) {
                foreach ($candidates as $session) {
                    if ($session['fingerprint'] === $fingerprint) {
                        return self::snapshot([$session], 'exact');
                    }
                }
            }

            // Without an exact match, only sessions known to run in the worktree.
            $inWorktree = array_values(array_filter($candidates, static fn (array $s): bool => null !== $s['cwd']));
            if ([] === $inWorktree) {
                return null;
            }

            return self::snapshot(\array_slice($inWorktree, 0, self::MAX_SESSIONS), 'window');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{session: string, startedS: int, cwd: ?string, fingerprint: string, metrics: array<string, mixed>, firstMs: int, lastMs: int}|null
     */
    private function read(string $path): ?array
    {
        $handle = @fopen($path, 'r');
        if (false === $handle) {
            return null;
        }
        $startedS = null;
        $cwd = null;
        $fingerprint = '';
        $metrics = [];
        $firstMs = 0;
        $lastMs = 0;
        try {
            while (false !== ($line = fgets($handle))) {
                $event = json_decode($line, true);
                if (!\is_array($event)) {
                    continue;
                }
                $type = (string) ($event['type'] ?? '');
                $data = \is_array($event['data'] ?? null) ? $event['data'] : [];
                $tsMs = $this->timestampMs($event['timestamp'] ?? null);
                if ($tsMs > 0) {
                    $firstMs = 0 === $firstMs ? $tsMs : min($firstMs, $tsMs);
                    $lastMs = max($lastMs, $tsMs);
                    $startedS ??= (int) floor($tsMs / 1000);
                }
                if ('session.start' === $type) {
                    $cwd ??= self::cwdOf($data);
                }
                if ('user.message' === $type && '' === $fingerprint && \is_string($data['content'] ?? null)) {
                    $fingerprint = PromptFingerprint::of($data['content']);
                }
                if (\in_array($type, self::USAGE_EVENTS, true) && \is_array($data['modelMetrics'] ?? null)) {
                    $metrics = $data['modelMetrics'];
                }
            }
        } finally {
            fclose($handle);
        }
        if (null === $startedS) {
            return null;
        }

        return [
            'session' => basename(\dirname($path)),
            'startedS' => $startedS,
            'cwd' => $cwd,
            'fingerprint' => $fingerprint,
            'metrics' => $metrics,
            'firstMs' => $firstMs,
            'lastMs' => $lastMs,
        ];
    }

    /** @param array<mixed> $data */
    private static function cwdOf(array $data): ?string
    {
        foreach ([$data['context']['cwd'] ?? null, $data['cwd'] ?? null, $data['workingDirectory'] ?? null] as $candidate) {
            if (\is_string($candidate) && '' !== $candidate) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param list<array{session: string, startedS: int, cwd: ?string, fingerprint: string, metrics: array<string, mixed>, firstMs: int, lastMs: int}> $sessions
     */
    private static function snapshot(array $sessions, string $quality): UsageSnapshot
    {
        $input = $output = $cacheRead = $cacheWrite = 0;
        $models = [];
        $ids = [];
        $firstMs = 0;
        $lastMs = 0;
        foreach ($sessions as $session) {
            $ids[] = $session['session'];
            foreach ($session['metrics'] as $model => $metric) {
                $usage = \is_array($metric) && \is_array($metric['usage'] ?? null) ? $metric['usage'] : [];
                $read = self::intOf($usage['cacheReadTokens'] ?? null);
                $write = self::intOf($usage['cacheWriteTokens'] ?? null);
                // inputTokens includes the cached ones.
                $input += max(0, self::intOf($usage['inputTokens'] ?? null) - $read - $write);
                $output += self::intOf($usage['outputTokens'] ?? null);
                $cacheRead += $read;
                $cacheWrite += $write;
                if ('' !== (string) $model) {
                    $models[(string) $model] = true;
                }
            }
            if ($session['firstMs'] > 0) {
                $firstMs = 0 === $firstMs ? $session['firstMs'] : min($firstMs, $session['firstMs']);
            }
            $lastMs = max($lastMs, $session['lastMs']);
        }
        ksort($models);

        return new UsageSnapshot(
            input: $input,
            output: $output,
            // Reasoning tokens are already part of outputTokens.
            reasoning: 0,
            cacheRead: $cacheRead,
            cacheWrite: $cacheWrite,
            totalTokens: $input + $output + $cacheRead + $cacheWrite,
            cost: 0.0,
            models: array_keys($models),
            sessionId: implode(',', $ids),
            spanMs: $firstMs > 0 && $lastMs > $firstMs ? $lastMs - $firstMs : null,
            quality: $quality,
        );
    }

    private function timestampMs(mixed $ts): int
    {
        if (!\is_string($ts) || '' === $ts) {
            return 0;
        }
        try {
            return (int) round((float) $this->time->parseTs($ts)->format('U.u') * 1000);
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
