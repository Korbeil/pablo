<?php

declare(strict_types=1);

namespace Pablo\Install;

use Pablo\Support\Files;

/**
 * Puts ~/.local/bin on the PATH when it is not already there: a block
 * appended to the shell's rc file between markers (so a second install never
 * duplicates it and uninstall removes exactly it), or for fish a conf.d file
 * of its own, so no existing file is edited.
 */
final class PathRc
{
    public const BEGIN = '# >>> pablo >>>';
    public const END = '# <<< pablo <<<';

    /**
     * The rc file for $shell (a path or a name), or null for a shell we do not
     * know.
     */
    public static function rcFile(string $shell, string $home, string $osFamily, ?string $zdotdir = null): ?string
    {
        return match (basename($shell)) {
            'zsh' => (null !== $zdotdir && '' !== $zdotdir ? $zdotdir : $home).'/.zshrc',
            'bash' => $home.('Darwin' === $osFamily ? '/.bash_profile' : '/.bashrc'),
            'fish' => $home.'/.config/fish/conf.d/pablo.fish',
            default => null,
        };
    }

    /**
     * Make sure $binDir is on the PATH.
     *
     * @return array{rc: string|null, message: string} rc: the file a new shell must source, when one was edited
     */
    public static function add(string $binDir, string $path, ?string $rc): array
    {
        if (\in_array($binDir, explode(':', $path), true)) {
            return ['rc' => null, 'message' => "{$binDir} is already on your PATH"];
        }
        if (null === $rc) {
            return ['rc' => null, 'message' => "⚠ {$binDir} is not on your PATH: add it in your shell config (export PATH=\"{$binDir}:\$PATH\")"];
        }
        $current = is_file($rc) ? (string) file_get_contents($rc) : '';
        if (str_contains($current, self::BEGIN)) {
            return ['rc' => $rc, 'message' => "{$rc} already puts {$binDir} on PATH"];
        }

        $dir = \dirname($rc);
        Files::ensureDir($dir);
        if (str_ends_with($rc, '.fish')) {
            $block = self::BEGIN."\n# Added by the PABLO installer (removed by pablo system:uninstall).\n"
                ."contains {$binDir} \$PATH; or set -gx PATH {$binDir} \$PATH\n".self::END."\n";
            file_put_contents($rc, $block);
        } else {
            $block = "\n".self::BEGIN."\n# Added by the PABLO installer (removed by pablo system:uninstall).\n"
                ."case \":\$PATH:\" in *\":{$binDir}:\"*) ;; *) export PATH=\"{$binDir}:\$PATH\" ;; esac\n".self::END."\n";
            // Appending writes through a symlinked rc (dotfiles repos) and keeps its permissions.
            file_put_contents($rc, $block, \FILE_APPEND);
        }

        return ['rc' => $rc, 'message' => "added {$binDir} to PATH in {$rc}"];
    }

    /**
     * Remove the block from every rc file PABLO may have edited.
     *
     * @return list<string> what was removed
     */
    public static function remove(string $home, ?string $zdotdir = null): array
    {
        $removed = [];
        $zshrc = (null !== $zdotdir && '' !== $zdotdir ? $zdotdir : $home).'/.zshrc';
        foreach ([$home.'/.bashrc', $home.'/.bash_profile', $zshrc] as $rc) {
            if (!is_file($rc)) {
                continue;
            }
            $content = (string) file_get_contents($rc);
            if (!str_contains($content, self::BEGIN)) {
                continue;
            }
            $kept = [];
            $skip = false;
            foreach (explode("\n", $content) as $line) {
                if (self::BEGIN === $line) {
                    $skip = true;
                    // The blank line add() put before the block goes with it.
                    if ([] !== $kept && '' === end($kept)) {
                        array_pop($kept);
                    }
                    continue;
                }
                if (self::END === $line) {
                    $skip = false;
                    continue;
                }
                if (!$skip) {
                    $kept[] = $line;
                }
            }
            // Writes through a symlink and keeps the file's permissions.
            file_put_contents($rc, implode("\n", $kept));
            $removed[] = "removed the PABLO PATH block from {$rc}";
        }

        $fish = $home.'/.config/fish/conf.d/pablo.fish';
        if (is_file($fish) && str_contains((string) file_get_contents($fish), self::BEGIN)) {
            Files::tryRemove($fish);
            $removed[] = "removed {$fish}";
        }

        return $removed;
    }
}
