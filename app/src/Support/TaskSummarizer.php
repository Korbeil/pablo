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

    public function __construct(private readonly ProcessRunnerInterface $runner)
    {
    }

    /** @return list<string> argv for the summarizing opencode call */
    public static function argv(string $repoPath, string $prompt): array
    {
        $instruction = 'Summarize the following task in at most '.self::MAX_WORDS.' words. '
            .'Reply with the summary only: plain text, no quotes, no list, no code block, nothing else.'
            ."\n\nTask:\n".$prompt;

        return ['opencode', 'run', '-m', self::MODEL, '--dir', $repoPath, $instruction];
    }

    public function summarize(string $repoPath, string $prompt): ?string
    {
        try {
            $out = $this->runner->run(self::argv($repoPath, $prompt), check: false, timeout: self::TIMEOUT_S);
        } catch (\Throwable) {
            return null;
        }

        return self::clean($out);
    }

    public static function clean(string $raw): ?string
    {
        // `opencode run` stdout is just the answer; the ANSI model-header
        // lines go to stderr and are ignored. Strip escape sequences anyway
        // in case of future UI noise, then cap to MAX_WORDS.
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
