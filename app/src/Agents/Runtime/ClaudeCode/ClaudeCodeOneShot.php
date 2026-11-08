<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime\ClaudeCode;

use Pablo\Agents\Runtime\RuntimeOneShotInterface;
use Pablo\Support\ProcessRunnerInterface;

/**
 * One throwaway `claude -p` on haiku: no tools, no MCP servers, no
 * transcript left behind.
 */
final class ClaudeCodeOneShot implements RuntimeOneShotInterface
{
    public const MODEL = 'haiku';

    public function model(): string
    {
        return self::MODEL;
    }

    /** @return list<string> */
    public static function argv(string $instruction): array
    {
        return [
            'claude', '-p', '--model', self::MODEL, '--output-format', 'json',
            '--no-session-persistence', '--strict-mcp-config', '--tools', '',
            // `--`: the variadic --tools would otherwise swallow the prompt.
            '--', $instruction,
        ];
    }

    public function ask(ProcessRunnerInterface $runner, string $cwd, string $instruction, float $timeout): ?string
    {
        try {
            $out = $runner->run(self::argv($instruction), check: false, timeout: $timeout, cwd: $cwd);
        } catch (\Throwable) {
            return null;
        }

        return self::parse($out);
    }

    /**
     * The answer from `claude -p --output-format json`: one JSON object whose
     * `result` holds the text (null on an error result or odd output).
     */
    public static function parse(string $raw): ?string
    {
        $data = json_decode(trim($raw), true);
        if (!\is_array($data) || true === ($data['is_error'] ?? false) || !\is_string($data['result'] ?? null)) {
            return null;
        }

        return $data['result'];
    }
}
