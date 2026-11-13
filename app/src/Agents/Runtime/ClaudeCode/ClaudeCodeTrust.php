<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime\ClaudeCode;

use Pablo\Support\Files;
use Pablo\Support\PabloPaths;

/**
 * Marks a worktree as trusted by Claude Code.
 *
 * An interactive `claude` session (a Remote Control run) first asks whether
 * you trust a folder it has never seen — and every task is a new worktree.
 * A trusted parent folder does not cover a git checkout (it only covers
 * plain subfolders), so each worktree gets its own
 * `{"hasTrustDialogAccepted": true}` entry in Claude Code's ~/.claude.json
 * just before its launch.
 *
 * Only that key of that entry is ever written; the rest of the file is kept.
 * A missing or unreadable file is left alone (Claude Code creates it on its
 * first run).
 */
final class ClaudeCodeTrust
{
    /**
     * ~/.claude.json, or .claude.json in CLAUDE_CONFIG_DIR when set
     * (PABLO_CLAUDE_JSON overrides both: tests point it elsewhere).
     */
    public static function file(): string
    {
        $override = getenv('PABLO_CLAUDE_JSON');
        if (false !== $override && '' !== $override) {
            return $override;
        }
        $env = getenv('CLAUDE_CONFIG_DIR');

        return false !== $env && '' !== $env
            ? ClaudeCodeRuntime::userConfigDir().'/.claude.json'
            : PabloPaths::home().'/.claude.json';
    }

    /** Trust $dir; whether it is trusted afterwards. Never throws. */
    public static function trust(string $dir, ?string $file = null): bool
    {
        $file ??= self::file();
        $dir = rtrim($dir, '/');
        $raw = '' !== $dir && is_file($file) ? @file_get_contents($file) : false;
        // Objects stay objects (an empty {} must not come back as []).
        $config = false !== $raw ? json_decode($raw, false) : null;
        if (!$config instanceof \stdClass) {
            return false;
        }
        if (!isset($config->projects) || !$config->projects instanceof \stdClass) {
            $config->projects = new \stdClass();
        }
        $entry = isset($config->projects->{$dir}) && $config->projects->{$dir} instanceof \stdClass ? $config->projects->{$dir} : new \stdClass();
        if (true === ($entry->hasTrustDialogAccepted ?? null)) {
            return true;
        }
        $entry->hasTrustDialogAccepted = true;
        $config->projects->{$dir} = $entry;

        return self::write($file, $config);
    }

    /** Atomically replace $file, keeping its permissions. */
    private static function write(string $file, \stdClass $config): bool
    {
        $json = json_encode($config, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
        $tmp = $file.'.pablo-'.getmypid();
        if (false === $json || false === @file_put_contents($tmp, $json."\n")) {
            Files::tryRemove($tmp);

            return false;
        }
        $perms = @fileperms($file);
        @chmod($tmp, false !== $perms ? $perms & 0o777 : 0o600);
        if (!@rename($tmp, $file)) {
            Files::tryRemove($tmp);

            return false;
        }

        return true;
    }
}
