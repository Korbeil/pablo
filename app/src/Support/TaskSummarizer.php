<?php

declare(strict_types=1);

namespace Pablo\Support;

use Pablo\Agents\Runtime\AgentRuntimeRegistry;

/**
 * Produces a very short task summary for prompt-started tasks by asking a
 * small LLM instead of truncating the prompt: one throwaway call through the
 * selected runtime's RuntimeOneShotInterface (which picks the model and keeps
 * the call tool-less).
 *
 * Best-effort: any failure (missing CLI, timeout, empty/odd output) yields
 * null so the caller can fall back to plain word truncation. Runs through
 * the injected ProcessRunnerInterface — never shell_exec — so tests script
 * the outcome with FakeProcessRunner.
 */
final class TaskSummarizer
{
    /** Hard cap on words in the returned summary. */
    public const MAX_WORDS = 7;

    private const TIMEOUT_S = 60.0;

    public function __construct(
        private readonly ProcessRunnerInterface $runner,
        private readonly AgentRuntimeRegistry $runtimes = new AgentRuntimeRegistry(),
    ) {
    }

    public static function instruction(string $prompt): string
    {
        // The hard wording (label semantics + explicit "output nothing except
        // the summary, then stop") is deliberate: small models occasionally
        // append a conversational follow-up ("I'm ready to help…") to softer
        // phrasings, which would end up in the branch name.
        return 'You label tasks with a short branch name. Reply with only the summary of the task: '
            .'at most '.self::MAX_WORDS.' words, plain lowercase text, no quotes, no list, no code block. '
            .'Output nothing except the summary. Do not continue the conversation, do not ask questions, '
            ."do not offer help.\n\nTask:\n".$prompt;
    }

    public function summarize(string $repoPath, string $prompt): ?string
    {
        try {
            $oneShot = $this->runtimes->resolve()->oneShot();
        } catch (\Throwable) {
            return null;
        }
        $answer = $oneShot->ask($this->runner, $repoPath, self::instruction($prompt), self::TIMEOUT_S);

        return null !== $answer ? self::clean($answer) : null;
    }

    public static function clean(string $raw): ?string
    {
        // ANSI noise is stripped, then the answer is capped to MAX_WORDS.
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
