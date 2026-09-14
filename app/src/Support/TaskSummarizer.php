<?php

declare(strict_types=1);

namespace Pablo\Support;

/**
 * Produces a very short task summary for prompt-started tasks by asking a
 * small free LLM (`opencode/big-pickle`) instead of truncating the prompt.
 *
 * Best-effort: any failure (missing CLI, timeout, empty/odd output) yields
 * null so the caller can fall back to plain word truncation. Runs through
 * the injected ProcessRunnerInterface — never shell_exec — so tests script
 * the outcome with FakeProcessRunner.
 */
final class TaskSummarizer
{
    /** Free opencode model used for the one-shot summary. */
    public const MODEL = 'opencode/big-pickle';

    /** Hard cap on words in the returned summary. */
    public const MAX_WORDS = 7;

    private const TIMEOUT_S = 60.0;

    private const DELETE_TIMEOUT_S = 15.0;

    public function __construct(private readonly ProcessRunnerInterface $runner)
    {
    }

    /** @return list<string> argv for the summarizing opencode call */
    public static function argv(string $repoPath, string $prompt): array
    {
        $instruction = 'Summarize the following task in at most '.self::MAX_WORDS.' words. '
            .'Reply with the summary only: plain text, no quotes, no list, no code block, nothing else.'
            ."\n\nTask:\n".$prompt;

        return ['opencode', 'run', '-m', self::MODEL, '--dir', $repoPath, '--format', 'json', $instruction];
    }

    public function summarize(string $repoPath, string $prompt): ?string
    {
        try {
            $out = $this->runner->run(self::argv($repoPath, $prompt), check: false, timeout: self::TIMEOUT_S);
        } catch (\Throwable) {
            return null;
        }

        $result = self::parse($out);

        // The one-shot session would otherwise pollute the shared opencode
        // store (and thus OpenChamber's session list) — delete it after the
        // answer is in, best-effort, whether or not the summary is usable.
        if (null !== $result['session']) {
            try {
                $this->runner->run(['opencode', 'session', 'delete', $result['session']], check: false, timeout: self::DELETE_TIMEOUT_S);
            } catch (\Throwable) {
            }
        }

        return self::clean($result['text'] ?? '');
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
            $ev = json_decode($line, true);
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

    public static function clean(string $raw): ?string
    {
        // The answer comes from the parsed JSON event stream (see parse())
        // concatenated; ANSI noise is stripped anyway, then capped to MAX_WORDS.
        $text = preg_replace('/\s+/', ' ', trim($raw)) ?? '';
        $text = preg_replace('/\x1B(?:[@-Z\\-_]|\[[0-?]*[ -\/]*[@-~])/', '', $text) ?? '';
        $text = trim($text, " \t\"'`*_");
        $words = preg_split('/\s+/', $text, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $words = \array_slice($words, 0, self::MAX_WORDS);
        if ([] === $words) {
            return null;
        }
        $summary = implode(' ', $words);

        return trim($summary, " \t\"'`*_.,:;!");
    }
}
