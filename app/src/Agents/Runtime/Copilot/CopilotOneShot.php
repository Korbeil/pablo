<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime\Copilot;

use Pablo\Agents\Runtime\RuntimeOneShotInterface;
use Pablo\Support\ProcessRunnerInterface;

/**
 * One `copilot -p` on claude-haiku-5.5: plain-text answer (-s), shell and
 * writes denied, no MCP servers. Copilot cannot skip saving the session, so
 * it stays in its session list.
 */
final class CopilotOneShot implements RuntimeOneShotInterface
{
    public const MODEL = 'claude-haiku-5.5';

    /** @return list<string> */
    public static function argv(string $instruction): array
    {
        return [
            'copilot', '--model', self::MODEL, '-s', '--no-color', '--no-ask-user', '--no-auto-update',
            '--no-custom-instructions', '--disable-builtin-mcps', '--deny-tool', 'shell', '--deny-tool', 'write',
            '-p', $instruction,
        ];
    }

    public function ask(ProcessRunnerInterface $runner, string $cwd, string $instruction, float $timeout): ?string
    {
        try {
            return $runner->run(self::argv($instruction), check: false, timeout: $timeout, cwd: $cwd);
        } catch (\Throwable) {
            // The summary is optional by contract: any runner failure means "no summary".
            return null;
        }
    }
}
