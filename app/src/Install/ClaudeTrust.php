<?php

declare(strict_types=1);

namespace Pablo\Install;

use Pablo\Agents\Runtime\ClaudeCodeRuntime;
use Pablo\Support\PabloPaths;

/**
 * Marks the folders PABLO creates worktrees in as trusted by Claude Code.
 *
 * In an Orca terminal, agents run as an interactive `claude` session, which
 * first asks whether you trust a folder it has never seen — and every task is
 * a new worktree. Trusting their parent folder covers them all, so this adds
 * `{"hasTrustDialogAccepted": true}` for it to Claude Code's ~/.claude.json,
 * unless that folder or one of its parents is trusted already.
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

    /** The default worktrees root, ~/.pablo/worktrees. */
    public static function defaultRoot(): string
    {
        return PabloPaths::home().'/.pablo/worktrees';
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
            return ["{$file} not found: Claude Code will ask once whether you trust the worktrees folder"];
        }
        $raw = @file_get_contents($file);
        // Objects stay objects (an empty {} must not come back as []).
        $config = false !== $raw ? json_decode($raw, false) : null;
        if (!$config instanceof \stdClass) {
            return ["{$file} is not a JSON object: worktrees folder not marked as trusted"];
        }
        if (!isset($config->projects) || !$config->projects instanceof \stdClass) {
            $config->projects = new \stdClass();
        }
        $projects = $config->projects;

        $added = [];
        foreach (array_unique(array_map(static fn (string $d): string => rtrim($d, '/'), $dirs)) as $dir) {
            if ('' === $dir || self::trusted($projects, $dir)) {
                continue;
            }
            $entry = isset($projects->{$dir}) && $projects->{$dir} instanceof \stdClass ? $projects->{$dir} : new \stdClass();
            $entry->hasTrustDialogAccepted = true;
            $projects->{$dir} = $entry;
            $added[] = $dir;
        }
        if ([] === $added) {
            return [];
        }

        $json = json_encode($config, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
        $tmp = $file.'.pablo-'.getmypid();
        if (false === $json || false === @file_put_contents($tmp, $json."\n")) {
            @unlink($tmp);

            return ["could not write {$file}: worktrees folder not marked as trusted"];
        }
        $perms = @fileperms($file);
        @chmod($tmp, false !== $perms ? $perms & 0o777 : 0o600);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);

            return ["could not replace {$file}: worktrees folder not marked as trusted"];
        }

        return array_map(static fn (string $dir): string => "Claude Code now trusts {$dir} (in {$file})", $added);
    }

    /** Whether $dir or one of its parents is trusted already. */
    private static function trusted(\stdClass $projects, string $dir): bool
    {
        for ($path = $dir; '' !== $path && '/' !== $path && '.' !== $path; $path = \dirname($path)) {
            $entry = $projects->{$path} ?? null;
            if ($entry instanceof \stdClass && true === ($entry->hasTrustDialogAccepted ?? null)) {
                return true;
            }
        }

        return false;
    }
}
