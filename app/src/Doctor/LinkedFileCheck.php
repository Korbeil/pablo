<?php

declare(strict_types=1);

namespace Pablo\Doctor;

use Pablo\Support\Files;

/**
 * The verdict for one file `pablo system:install` linked into a runtime's
 * user directory: missing, not PABLO's, a broken link, stale against a fresh
 * render, or fine. Shared by every runtime's staleness check.
 */
final class LinkedFileCheck
{
    /** Renders the agents and (re)creates the links: the fix for every hard failure here. */
    public const INSTALL = 'pablo system:install';

    /**
     * A PABLO symlink: compared against $fresh when given (for a linked
     * directory, against its $file), otherwise only required to resolve.
     */
    public static function check(string $label, string $dest, ?string $fresh, ?string $file = null): AgentFileStatus
    {
        if (!is_link($dest) && !file_exists($dest)) {
            return new AgentFileStatus($label, AgentFileState::Missing, 'missing', 'run: '.self::INSTALL);
        }
        if (!is_link($dest)) {
            // system:install refuses to touch a file that is not its own.
            return new AgentFileStatus($label, AgentFileState::ForeignFile,
                'exists and is not a PABLO symlink', 'move it aside, then re-run '.self::INSTALL);
        }
        $target = realpath($dest);
        if (false === $target) {
            $linkTarget = readlink($dest);

            return new AgentFileStatus($label, AgentFileState::BrokenSymlink,
                'broken symlink → '.(false !== $linkTarget ? $linkTarget : '?'), 're-run '.self::INSTALL.' to relink');
        }

        if (null === $fresh) {
            return new AgentFileStatus($label, AgentFileState::Ok, 'ok');
        }

        return self::compare($label, null !== $file ? $target.'/'.$file : $target, $fresh);
    }

    /** A generated file (not a link) compared against a fresh render. */
    public static function generated(string $label, string $path, string $fresh): AgentFileStatus
    {
        return is_file($path)
            ? self::compare($label, $path, $fresh)
            : new AgentFileStatus($label, AgentFileState::Missing, 'missing ('.$path.')', 'run: '.self::INSTALL);
    }

    public static function compare(string $label, string $realPath, string $fresh): AgentFileStatus
    {
        $installed = Files::tryRead($realPath);
        if (null === $installed) {
            return new AgentFileStatus($label, AgentFileState::Unverifiable, "cannot read installed file: {$realPath}");
        }

        if ($installed !== $fresh) {
            return new AgentFileStatus($label, AgentFileState::Stale, 'stale — differs from freshly rendered',
                'run: pablo system:generate-agents');
        }

        return new AgentFileStatus($label, AgentFileState::Ok, 'ok');
    }
}
