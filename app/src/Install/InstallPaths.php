<?php

declare(strict_types=1);

namespace Pablo\Install;

use Pablo\Agents\Runtime\ClaudeCodeRuntime;
use Pablo\Agents\Runtime\CopilotRuntime;
use Pablo\Support\PabloPaths;

/**
 * Where `pablo system:install` puts things, resolved per call from HOME and
 * the runtimes' own overrides (CLAUDE_CONFIG_DIR, COPILOT_HOME) — never at
 * container compile time.
 */
final class InstallPaths
{
    public static function binDir(): string
    {
        return PabloPaths::home().'/.local/bin';
    }

    /** The `pablo` command: the binary itself, or a shim from a checkout. */
    public static function binPath(): string
    {
        return self::binDir().'/pablo';
    }

    /** OpenCode's user config dir: agents/, commands/, skill/. */
    public static function opencodeDir(): string
    {
        return PabloPaths::home().'/.config/opencode';
    }

    public static function claudeDir(): string
    {
        return ClaudeCodeRuntime::userConfigDir();
    }

    public static function copilotDir(): string
    {
        return CopilotRuntime::userConfigDir();
    }

    /** The legacy (clone install) location of the Claude Code `--agents` file. */
    public static function legacyClaudeDir(): string
    {
        return PabloPaths::pabloDir().'/claude';
    }

    /** Whether $name is an executable file in one of the PATH directories. */
    public static function hasCommand(string $name): bool
    {
        foreach (explode(\PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ('' !== $dir && is_file($dir.'/'.$name) && is_executable($dir.'/'.$name)) {
                return true;
            }
        }

        return false;
    }
}
