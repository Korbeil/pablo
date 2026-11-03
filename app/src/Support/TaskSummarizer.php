<?php

declare(strict_types=1);

namespace Pablo\Support;

use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Agents\Runtime\AgentRuntimeResolver;

/**
 * Produces a very short task summary for prompt-started tasks by asking a
 * small LLM instead of truncating the prompt: the free `opencode/big-pickle`
 * under OpenCode, `haiku` under Claude Code (one throwaway `claude -p`, no
 * tools, no MCP servers, nothing persisted).
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

    /** Claude Code model used for the one-shot summary. */
    public const CLAUDE_MODEL = 'haiku';

    /** Hard cap on words in the returned summary. */
    public const MAX_WORDS = 7;

    private const TIMEOUT_S = 60.0;

    private const DELETE_TIMEOUT_S = 15.0;

    public function __construct(
        private readonly ProcessRunnerInterface $runner,
        private readonly AgentRuntimeResolver $runtimes = new AgentRuntimeResolver(),
    ) {
    }

    private static function instruction(string $prompt): string
    {
        // The hard wording (label semantics + explicit "output nothing except
        // the summary, then stop") is deliberate: big-pickle occasionally
        // appends a conversational follow-up ("I'm ready to help…") to softer
        // phrasings, which would end up in the branch name.
        return 'You label tasks with a short branch name. Reply with only the summary of the task: '
            .'at most '.self::MAX_WORDS.' words, plain lowercase text, no quotes, no list, no code block. '
            .'Output nothing except the summary. Do not continue the conversation, do not ask questions, '
            ."do not offer help.\n\nTask:\n".$prompt;
    }

    /** @return list<string> argv for the summarizing opencode call */
    public static function argv(string $prompt): array
    {
        return ['opencode', 'run', '-m', self::MODEL, '--format', 'json', self::instruction($prompt)];
    }

    /**
     * @return list<string> argv for the summarizing Claude Code call: no
     *                      tools, no MCP servers, no transcript left behind
     */
    public static function claudeArgv(string $prompt): array
    {
        return [
            'claude', '-p', '--model', self::CLAUDE_MODEL, '--output-format', 'json',
            '--no-session-persistence', '--strict-mcp-config', '--tools', '',
            // `--`: the variadic --tools would otherwise swallow the prompt.
            '--', self::instruction($prompt),
        ];
    }

    public function summarize(string $repoPath, string $prompt): ?string
    {
        try {
            $claude = AgentRuntimeInterface::CLAUDE_CODE === $this->runtimes->resolveName();
        } catch (\Throwable) {
            return null;
        }
        if ($claude) {
            try {
                $out = $this->runner->run(self::claudeArgv($prompt), check: false, timeout: self::TIMEOUT_S, cwd: $repoPath);
            } catch (\Throwable) {
                return null;
            }

            return self::clean(self::parseClaude($out) ?? '');
        }

        // OpenCode v2 removed `run --dir`; running from $repoPath's working
        // directory attaches the one-shot session to that project instead of
        // to whatever project the pablo process itself sits in.
        try {
            $out = $this->runner->run(self::argv($prompt), check: false, timeout: self::TIMEOUT_S, cwd: $repoPath);
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

    /**
     * The answer from `claude -p --output-format json`: one JSON object whose
     * `result` holds the text (null on an error result or odd output).
     */
    public static function parseClaude(string $raw): ?string
    {
        $data = json_decode(trim($raw), true);
        if (!\is_array($data) || true === ($data['is_error'] ?? false) || !\is_string($data['result'] ?? null)) {
            return null;
        }

        return $data['result'];
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
