<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime\ClaudeCode;

use Pablo\Agents\Runtime\UsageWindow;
use Pablo\Analytics\PromptFingerprint;
use Pablo\Analytics\UsageHarvesterInterface;
use Pablo\Analytics\UsageSnapshot;
use Pablo\Domain\Time;
use Pablo\Support\Json;
use Pablo\Support\Numbers;

/**
 * Harvests token usage for one Claude Code run from its local transcripts.
 *
 * Claude Code writes one JSONL transcript per session under
 * <config dir>/projects/<cwd with every non-alphanumeric char as '-'>/<session uuid>.jsonl
 * (<config dir> = CLAUDE_CONFIG_DIR, else ~/.claude). Candidates are the
 * worktree's transcripts touched since the launch (minus a small grace) whose
 * first prompt is not older than that (see UsageWindow). With a prompt fingerprint, the one
 * whose first user message hashes to it is the run ("exact"); without one,
 * every candidate is merged ("window"). Nothing matched means nothing is
 * recorded, never a guess.
 *
 * Tokens come from each assistant message's `usage` (counted once per API
 * message: a message split over several transcript lines repeats it). Cost
 * is not measured: a Pro/Max subscription has no per-run price, so it is
 * recorded as 0.
 */
final class ClaudeCodeUsage implements UsageHarvesterInterface
{
    private const MAX_TRANSCRIPTS = 8;

    public function __construct(
        private readonly Time $time = new Time(),
        private readonly ?string $configDir = null,
    ) {
    }

    /** CLAUDE_CONFIG_DIR, else ~/.claude; resolved per call. */
    public function configDir(): string
    {
        return $this->configDir ?? ClaudeCodeRuntime::userConfigDir();
    }

    /** Claude Code's per-directory transcript folder name. */
    public static function projectKey(string $worktree): string
    {
        return preg_replace('/[^a-zA-Z0-9]/', '-', rtrim($worktree, '/')) ?? $worktree;
    }

    public function transcriptPath(string $worktree, string $sessionId): string
    {
        return $this->configDir().'/projects/'.self::projectKey($worktree).'/'.$sessionId.'.jsonl';
    }

    public function harvest(string $worktree, string $launchedAt, ?string $fingerprint): ?UsageSnapshot
    {
        try {
            $candidates = UsageWindow::candidates(
                glob($this->configDir().'/projects/'.self::projectKey($worktree).'/*.jsonl') ?: [],
                UsageWindow::startS($this->time, $launchedAt),
                $this->read(...),
            );
            if ([] === $candidates) {
                return null;
            }
            $exact = UsageWindow::exact($candidates, $fingerprint);
            if (null !== $exact) {
                return self::snapshot([$exact], 'exact');
            }

            return self::snapshot(\array_slice($candidates, 0, self::MAX_TRANSCRIPTS), 'window');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{session: string, startedS: int, fingerprint: string, messages: array<string, array<string, mixed>>, firstMs: int, lastMs: int}|null
     */
    private function read(string $path): ?array
    {
        $handle = @fopen($path, 'r');
        if (false === $handle) {
            return null;
        }
        $session = basename($path, '.jsonl');
        $startedS = null;
        $fingerprint = '';
        $messages = [];
        $firstMs = 0;
        $lastMs = 0;
        try {
            while (false !== ($line = fgets($handle))) {
                $row = Json::tryDecodeArray($line);
                if (!\is_array($row) || ($row['isSidechain'] ?? false) === true) {
                    continue;
                }
                $type = $row['type'] ?? null;
                $message = \is_array($row['message'] ?? null) ? $row['message'] : null;
                if (null === $message || !\in_array($type, ['user', 'assistant'], true)) {
                    continue;
                }
                $tsMs = UsageWindow::timestampMs($this->time, $row['timestamp'] ?? null);
                if ('user' === $type && null === $startedS) {
                    $text = self::textOf($message['content'] ?? null);
                    if (null === $text) {
                        continue; // a tool result, not a prompt
                    }
                    $startedS = (int) floor($tsMs / 1000);
                    $fingerprint = '' === $text ? '' : PromptFingerprint::of($text);
                }
                if ('assistant' === $type && \is_array($message['usage'] ?? null)) {
                    $id = (string) ($message['id'] ?? $row['uuid'] ?? \count($messages));
                    $messages[$id] = $message;
                    if ($tsMs > 0) {
                        $firstMs = 0 === $firstMs ? $tsMs : min($firstMs, $tsMs);
                        $lastMs = max($lastMs, $tsMs);
                    }
                }
            }
        } finally {
            fclose($handle);
        }
        if (null === $startedS) {
            return null;
        }

        return ['session' => $session, 'startedS' => $startedS, 'fingerprint' => $fingerprint, 'messages' => $messages, 'firstMs' => $firstMs, 'lastMs' => $lastMs];
    }

    /**
     * @param list<array{session: string, startedS: int, fingerprint: string, messages: array<string, array<string, mixed>>, firstMs: int, lastMs: int}> $transcripts
     */
    private static function snapshot(array $transcripts, string $quality): UsageSnapshot
    {
        $input = $output = $cacheRead = $cacheWrite = 0;
        $models = [];
        $sessions = [];
        $firstMs = 0;
        $lastMs = 0;
        foreach ($transcripts as $transcript) {
            $sessions[] = $transcript['session'];
            foreach ($transcript['messages'] as $message) {
                $usage = \is_array($message['usage'] ?? null) ? $message['usage'] : [];
                $input += Numbers::int($usage['input_tokens'] ?? null);
                $output += Numbers::int($usage['output_tokens'] ?? null);
                $cacheRead += Numbers::int($usage['cache_read_input_tokens'] ?? null);
                $cacheWrite += Numbers::int($usage['cache_creation_input_tokens'] ?? null);
                $model = (string) ($message['model'] ?? '');
                if ('' !== $model && '<synthetic>' !== $model) {
                    $models[$model] = true;
                }
            }
            if ($transcript['firstMs'] > 0) {
                $firstMs = 0 === $firstMs ? $transcript['firstMs'] : min($firstMs, $transcript['firstMs']);
            }
            $lastMs = max($lastMs, $transcript['lastMs']);
        }
        ksort($models);

        return new UsageSnapshot(
            input: $input,
            output: $output,
            // Thinking tokens are already part of output_tokens.
            reasoning: 0,
            cacheRead: $cacheRead,
            cacheWrite: $cacheWrite,
            totalTokens: $input + $output + $cacheRead + $cacheWrite,
            cost: 0.0,
            models: array_keys($models),
            sessionId: implode(',', $sessions),
            spanMs: $firstMs > 0 && $lastMs > $firstMs ? $lastMs - $firstMs : null,
            quality: $quality,
        );
    }

    /** The prompt text of a user message, or null for a tool-result turn. */
    private static function textOf(mixed $content): ?string
    {
        if (\is_string($content)) {
            return $content;
        }
        if (!\is_array($content)) {
            return null;
        }
        $chunks = [];
        foreach ($content as $block) {
            if (!\is_array($block)) {
                continue;
            }
            if ('tool_result' === ($block['type'] ?? null)) {
                return null;
            }
            if ('text' === ($block['type'] ?? null) && \is_string($block['text'] ?? null)) {
                $chunks[] = $block['text'];
            }
        }

        return [] === $chunks ? null : implode('', $chunks);
    }
}
