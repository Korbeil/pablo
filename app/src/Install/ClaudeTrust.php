<?php

declare(strict_types=1);

namespace Pablo\Install;

use Pablo\Agents\Runtime\ClaudeCodeRuntime;
use Pablo\Support\PabloPaths;

/**
 * Marks PABLO's worktrees as trusted by Claude Code.
 *
 * In an Orca terminal, agents run as an interactive `claude` session, which
 * first asks whether you trust a folder it has never seen — and every task is
 * a new worktree. A trusted parent folder does not cover a git checkout (it
 * only covers plain subfolders), so each worktree gets its own
 * `{"hasTrustDialogAccepted": true}` entry in Claude Code's ~/.claude.json:
 * before each interactive launch, and for the existing tasks at install.
 * Closing a task removes its entry again (forget()).
 *
 * Only that key of that entry is ever written; the rest of the file is kept.
 * A missing or unreadable file is left alone (Claude Code creates it on its
 * first run, and then asks once).
 */
final class ClaudeTrust
{
    /**
     * ~/.claude.json, or .claude.json in CLAUDE_CONFIG_DIR when set
     * (PABLO_CLAUDE_JSON overrides both: tests point it nowhere).
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

    /**
     * Trust $dirs; returns one line per folder added, or why nothing was.
     *
     * @param list<string> $dirs
     *
     * @return list<string>
     */
    public static function trust(array $dirs, ?string $file = null): array
    {
        $file ??= self::file();
        if (!is_file($file)) {
            return ["{$file} not found: Claude Code will ask whether you trust each worktree"];
        }
        $raw = @file_get_contents($file);
        // Objects stay objects (an empty {} must not come back as []).
        $config = false !== $raw ? json_decode($raw, false) : null;
        if (!$config instanceof \stdClass) {
            return ["{$file} is not a JSON object: worktrees not marked as trusted"];
        }
        if (!isset($config->projects) || !$config->projects instanceof \stdClass) {
            $config->projects = new \stdClass();
        }
        $projects = $config->projects;

        $added = [];
        foreach (array_unique(array_map(static fn (string $d): string => rtrim($d, '/'), $dirs)) as $dir) {
            $entry = isset($projects->{$dir}) && $projects->{$dir} instanceof \stdClass ? $projects->{$dir} : new \stdClass();
            if ('' === $dir || true === ($entry->hasTrustDialogAccepted ?? null)) {
                continue;
            }
            $entry->hasTrustDialogAccepted = true;
            $projects->{$dir} = $entry;
            $added[] = $dir;
        }
        if ([] === $added) {
            return [];
        }
        if (!self::write($file, $config)) {
            return ["could not write {$file}: worktrees not marked as trusted"];
        }

        return array_map(static fn (string $dir): string => "Claude Code now trusts {$dir} (in {$file})", $added);
    }

    /**
     * Drop the entries of $dirs, closed tasks' worktrees that no longer
     * exist (with whatever Claude Code recorded there since). Best-effort:
     * returns whether the file changed, never throws.
     *
     * @param list<string> $dirs
     */
    public static function forget(array $dirs, ?string $file = null): bool
    {
        $file ??= self::file();
        $raw = is_file($file) ? @file_get_contents($file) : false;
        $config = false !== $raw ? json_decode($raw, false) : null;
        if (!$config instanceof \stdClass || !($config->projects ?? null) instanceof \stdClass) {
            return false;
        }
        $removed = false;
        foreach ($dirs as $dir) {
            $dir = rtrim($dir, '/');
            if ('' !== $dir && !is_dir($dir) && property_exists($config->projects, $dir)) {
                unset($config->projects->{$dir});
                $removed = true;
            }
        }

        return $removed && self::write($file, $config);
    }

    /** Atomically replace $file, keeping its permissions. */
    private static function write(string $file, \stdClass $config): bool
    {
        $json = json_encode($config, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
        $tmp = $file.'.pablo-'.getmypid();
        if (false === $json || false === @file_put_contents($tmp, $json."\n")) {
            @unlink($tmp);

            return false;
        }
        $perms = @fileperms($file);
        @chmod($tmp, false !== $perms ? $perms & 0o777 : 0o600);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }
}
