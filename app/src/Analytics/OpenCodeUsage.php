<?php

declare(strict_types=1);

namespace Pablo\Analytics;

use Pablo\Domain\Time;
use Pablo\Support\ProcessRunnerInterface;

/**
 * Harvests token/cost usage for one agent run from the local agent CLIs.
 *
 * OpenChamber-launched runs live behind OpenChamber's own OpenCode server,
 * invisible to the plain CLI's project-scoped listing/export, so those are
 * aggregated from `openchamber session list --dir` rows (window-based).
 * Everything else falls back to the shared local OpenCode storage: sessions
 * whose directory matches the worktree and whose creation is not before the
 * launch time (minus a small grace), then — when a prompt fingerprint is
 * available — exporting each candidate and matching it against the sha256 of
 * its first user message. Without a fingerprint every candidate in the window
 * is merged ("window" quality); nothing matched means no usage is recorded,
 * never a guess.
 */
final class OpenCodeUsage
{
    private const SESSION_GRACE_S = 5;
    private const SESSION_MAX_COUNT = 200;
    private const MAX_EXPORTS = 8;

    public function __construct(
        private readonly ProcessRunnerInterface $runner,
        private readonly Time $time = new Time(),
    ) {
    }

    /** Short non-reversible prompt fingerprint used for session attribution. */
    public static function fingerprint(string $prompt): string
    {
        return substr(hash('sha256', $prompt), 0, 16);
    }

    public function harvest(string $worktree, string $launchedAt, ?string $fingerprint): ?UsageSnapshot
    {
        try {
            $openchamber = $this->harvestFromOpenChamber($worktree, $launchedAt);
            if (null !== $openchamber) {
                return $openchamber;
            }

            $candidates = $this->sessionIdsForWorktree($worktree, $launchedAt);
            if ([] === $candidates) {
                return null;
            }
            if (null !== $fingerprint && '' !== $fingerprint) {
                foreach ($candidates as $id) {
                    $export = $this->exportSession($id);
                    if (null !== $export && self::firstUserMessageHash($export) === $fingerprint) {
                        return $this->snapshotFromExports([$id => $export], 'exact');
                    }
                }
            }

            $exports = [];
            foreach ($candidates as $id) {
                if (\count($exports) >= self::MAX_EXPORTS) {
                    break;
                }
                $export = $this->exportSession($id);
                if (null !== $export) {
                    $exports[$id] = $export;
                }
            }

            return [] !== $exports ? $this->snapshotFromExports($exports, 'window') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Aggregates session-level totals from the OpenChamber daemon: its
     * `session list` rows carry pre-summed tokens, cache, cost and model per
     * session. Tried first because OpenChamber-launched runs live behind
     * OpenChamber's own OpenCode server, where the plain CLI's project-scoped
     * listing and export cannot see them. Rows hold no transcripts, so
     * attribution here is always window-based.
     */
    private function harvestFromOpenChamber(string $worktree, string $launchedAt): ?UsageSnapshot
    {
        try {
            $probe = $this->runner->probe([
                'openchamber', 'session', 'list', '--dir', $worktree,
                '--limit', (string) self::SESSION_MAX_COUNT, '--json',
            ]);
            if (0 !== $probe->exitCode) {
                return null;
            }
            $decoded = json_decode($probe->output, true);
            $sessions = \is_array($decoded) ? ($decoded['sessions'] ?? null) : null;
            if (!\is_array($sessions)) {
                return null;
            }
            $windowStartS = $this->time->parseTs($launchedAt)->getTimestamp() - self::SESSION_GRACE_S;
            $hits = [];
            foreach ($sessions as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $createdMs = self::intOf(self::arrayOf($row, 'time')['created'] ?? null);
                if (0 === $createdMs || (int) floor($createdMs / 1000) < $windowStartS) {
                    continue;
                }
                $hits[] = ['created' => $createdMs, 'row' => $row];
            }
            if ([] === $hits) {
                return null;
            }
            usort($hits, static fn (array $a, array $b): int => $a['created'] <=> $b['created']);

            $input = $output = $reasoning = $cacheRead = $cacheWrite = 0;
            $cost = 0.0;
            $models = [];
            $ids = [];
            $minCreatedMs = 0;
            $maxUpdatedMs = 0;
            foreach ($hits as $hit) {
                $row = $hit['row'];
                $tokens = self::arrayOf($row, 'tokens');
                $cache = self::arrayOf($tokens, 'cache');
                $input += self::intOf($tokens['input'] ?? null);
                $output += self::intOf($tokens['output'] ?? null);
                $reasoning += self::intOf($tokens['reasoning'] ?? null);
                $cacheRead += self::intOf($cache['read'] ?? null);
                $cacheWrite += self::intOf($cache['write'] ?? null);
                $cost += self::floatOf($row['cost'] ?? null);
                $id = trim((string) ($row['id'] ?? ''));
                if ('' !== $id) {
                    $ids[] = $id;
                }
                $model = self::arrayOf($row, 'model');
                $modelId = trim((string) ($model['id'] ?? ''));
                if ('' !== $modelId) {
                    $providerId = trim((string) ($model['providerID'] ?? ''));
                    $models['' !== $providerId ? $providerId.'/'.$modelId : $modelId] = true;
                }
                $minCreatedMs = 0 === $minCreatedMs ? $hit['created'] : min($minCreatedMs, $hit['created']);
                $updatedMs = self::intOf(self::arrayOf($row, 'time')['updated'] ?? null);
                $maxUpdatedMs = max($maxUpdatedMs, $updatedMs);
            }

            return new UsageSnapshot(
                input: $input,
                output: $output,
                reasoning: $reasoning,
                cacheRead: $cacheRead,
                cacheWrite: $cacheWrite,
                // OpenChamber rows omit the `total` counter; OpenCode's own
                // total is the sum of all components (input + output +
                // reasoning + cache read + cache write).
                totalTokens: $input + $output + $reasoning + $cacheRead + $cacheWrite,
                cost: $cost,
                models: array_keys($models),
                sessionId: implode(',', $ids),
                spanMs: $minCreatedMs > 0 && $maxUpdatedMs > $minCreatedMs ? $maxUpdatedMs - $minCreatedMs : null,
                quality: 'window',
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Session ids on $worktree created at/after $launchedAt (minus grace),
     * oldest first.
     *
     * @return list<string>
     */
    private function sessionIdsForWorktree(string $worktree, string $launchedAt): array
    {
        $probe = $this->runner->probe([
            'opencode', 'session', 'list', '--format', 'json', '--max-count', (string) self::SESSION_MAX_COUNT,
        ]);
        if (0 !== $probe->exitCode) {
            return [];
        }
        $decoded = json_decode($probe->output, true);
        if (!\is_array($decoded)) {
            return [];
        }
        $windowStartS = $this->time->parseTs($launchedAt)->getTimestamp() - self::SESSION_GRACE_S;
        $wanted = rtrim($worktree, '/');
        $hits = [];
        foreach ($decoded as $row) {
            if (!\is_array($row)) {
                continue;
            }
            if (rtrim((string) ($row['directory'] ?? ''), '/') !== $wanted) {
                continue;
            }
            $createdMs = $row['created'] ?? null;
            if (!is_numeric($createdMs)) {
                continue;
            }
            if ((int) floor(((int) $createdMs) / 1000) < $windowStartS) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            if ('' === $id) {
                continue;
            }
            $hits[] = ['created' => (int) $createdMs, 'id' => $id];
        }
        usort($hits, static fn (array $a, array $b) => $a['created'] <=> $b['created']);

        return array_map(static fn (array $hit) => $hit['id'], $hits);
    }

    /** @return array<string, mixed>|null */
    private function exportSession(string $sessionId): ?array
    {
        $probe = $this->runner->probe(['opencode', 'export', $sessionId]);
        if (0 !== $probe->exitCode) {
            return null;
        }
        $decoded = json_decode($probe->output, true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, array<string, mixed>> $exports sessionId => export payload
     */
    private function snapshotFromExports(array $exports, string $quality): UsageSnapshot
    {
        $input = $output = $reasoning = $cacheRead = $cacheWrite = $total = 0;
        $cost = 0.0;
        $models = [];
        $minCreatedMs = 0;
        $maxCompletedMs = 0;
        foreach ($exports as $export) {
            $messages = $export['messages'] ?? [];
            if (!\is_array($messages)) {
                continue;
            }
            $messageCost = 0.0;
            foreach ($messages as $message) {
                if (!\is_array($message)) {
                    continue;
                }
                $info = $message['info'] ?? null;
                if (!\is_array($info) || 'assistant' !== ($info['role'] ?? null)) {
                    continue;
                }
                $tokens = \is_array($info['tokens'] ?? null) ? $info['tokens'] : [];
                $input += self::intOf($tokens['input'] ?? null);
                $output += self::intOf($tokens['output'] ?? null);
                $reasoning += self::intOf($tokens['reasoning'] ?? null);
                $total += self::intOf($tokens['total'] ?? null);
                $cache = \is_array($tokens['cache'] ?? null) ? $tokens['cache'] : [];
                $cacheRead += self::intOf($cache['read'] ?? null);
                $cacheWrite += self::intOf($cache['write'] ?? null);
                $messageCost += self::floatOf($info['cost'] ?? null);
                $modelId = (string) ($info['modelID'] ?? '');
                if ('' !== $modelId) {
                    $models[$modelId] = true;
                }
                $times = \is_array($info['time'] ?? null) ? $info['time'] : [];
                $createdMs = $times['created'] ?? null;
                $completedMs = $times['completed'] ?? null;
                if (is_numeric($createdMs)) {
                    $minCreatedMs = 0 === $minCreatedMs ? (int) $createdMs : min($minCreatedMs, (int) $createdMs);
                }
                if (is_numeric($completedMs)) {
                    $maxCompletedMs = max($maxCompletedMs, (int) $completedMs);
                }
            }
            $sessionCost = self::floatOf(\is_array($export['info'] ?? null) ? ($export['info']['cost'] ?? null) : null);
            // Subscription plans report zero per-message cost; fall back to the
            // session-level total when the messages carry none.
            $cost += $messageCost > 0.0 ? $messageCost : $sessionCost;
        }
        ksort($models);

        return new UsageSnapshot(
            input: $input,
            output: $output,
            reasoning: $reasoning,
            cacheRead: $cacheRead,
            cacheWrite: $cacheWrite,
            totalTokens: $total,
            cost: $cost,
            models: array_keys($models),
            sessionId: implode(',', array_keys($exports)),
            spanMs: $minCreatedMs > 0 && $maxCompletedMs > $minCreatedMs ? $maxCompletedMs - $minCreatedMs : null,
            quality: $quality,
        );
    }

    /**
     * sha256 fingerprint of the first user message's text parts, or '' when
     * the export has no user message (which never matches a real fingerprint).
     *
     * @param array<string, mixed> $export
     */
    private static function firstUserMessageHash(array $export): string
    {
        $messages = $export['messages'] ?? [];
        if (!\is_array($messages)) {
            return '';
        }
        foreach ($messages as $message) {
            if (!\is_array($message)) {
                continue;
            }
            $info = $message['info'] ?? null;
            if (!\is_array($info) || 'user' !== ($info['role'] ?? null)) {
                continue;
            }
            $text = self::textPartsOf(\is_array($message['parts'] ?? null) ? $message['parts'] : []);

            return '' === $text ? '' : substr(hash('sha256', $text), 0, 16);
        }

        return '';
    }

    /**
     * @param array<int, mixed> $parts
     */
    private static function textPartsOf(array $parts): string
    {
        $chunks = [];
        foreach ($parts as $part) {
            if (\is_array($part) && 'text' === ($part['type'] ?? null) && isset($part['text']) && \is_string($part['text'])) {
                $chunks[] = $part['text'];
            }
        }

        return implode('', $chunks);
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return array<string, mixed>
     */
    private static function arrayOf(array $source, string $key): array
    {
        $value = $source[$key] ?? null;

        return \is_array($value) ? $value : [];
    }

    private static function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function floatOf(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
