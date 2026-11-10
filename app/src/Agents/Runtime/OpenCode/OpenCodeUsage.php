<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime\OpenCode;

use Pablo\Analytics\PromptFingerprint;
use Pablo\Analytics\UsageHarvesterInterface;
use Pablo\Analytics\UsageSnapshot;
use Pablo\Domain\Time;
use Pablo\Support\Numbers;
use Pablo\Support\ProcessRunnerInterface;

/**
 * Harvests token/cost usage for one agent run from the local OpenCode
 * storage (an ADE that keeps its own usage is asked first, see
 * AdeInterface::usage()): sessions
 * whose directory matches the worktree and whose creation is not before the
 * launch time (minus a small grace), then — when a prompt fingerprint is
 * available — exporting each candidate and matching it against the sha256 of
 * its first user message. Without a fingerprint every candidate in the window
 * is merged ("window" quality); nothing matched means no usage is recorded,
 * never a guess.
 */
final class OpenCodeUsage implements UsageHarvesterInterface
{
    private const SESSION_GRACE_S = 5;
    private const SESSION_MAX_COUNT = 200;
    private const MAX_EXPORTS = 8;

    public function __construct(
        private readonly ProcessRunnerInterface $runner,
        private readonly Time $time = new Time(),
    ) {
    }

    public function harvest(string $worktree, string $launchedAt, ?string $fingerprint): ?UsageSnapshot
    {
        try {
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
     * Session ids on $worktree created at/after $launchedAt (minus grace),
     * oldest first.
     *
     * @return list<string>
     */
    private function sessionIdsForWorktree(string $worktree, string $launchedAt): array
    {
        $probe = $this->runner->probe([
            'opencode', 'session', 'list', '--format', 'json', '--max-count', (string) self::SESSION_MAX_COUNT,
        ], cwd: $worktree);
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
        // OpenCode v2 renamed the export to a session subcommand; its payload
        // keeps the same `messages[].info.{role,tokens,…}` shape.
        $probe = $this->runner->probe(['opencode', 'session', 'export', $sessionId]);
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
                $input += Numbers::int($tokens['input'] ?? null);
                $output += Numbers::int($tokens['output'] ?? null);
                $reasoning += Numbers::int($tokens['reasoning'] ?? null);
                $total += Numbers::int($tokens['total'] ?? null);
                $cache = \is_array($tokens['cache'] ?? null) ? $tokens['cache'] : [];
                $cacheRead += Numbers::int($cache['read'] ?? null);
                $cacheWrite += Numbers::int($cache['write'] ?? null);
                $messageCost += Numbers::float($info['cost'] ?? null);
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
            $sessionCost = Numbers::float(\is_array($export['info'] ?? null) ? ($export['info']['cost'] ?? null) : null);
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

            return '' === $text ? '' : PromptFingerprint::of($text);
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
}
