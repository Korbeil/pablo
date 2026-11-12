<?php

declare(strict_types=1);

namespace Pablo\Install;

use Symfony\Component\Console\Command\CompleteCommand;
use Symfony\Component\Console\Command\DumpCompletionCommand;

/**
 * The shell-completion script `pablo system:install` puts where the user's
 * shell loads it on its own: the very script `pablo completion <shell>`
 * prints, plus a marker line that makes the file PABLO's — so a second
 * install rewrites it, uninstall removes exactly it, and a file of the
 * user's at that path is never touched.
 */
final class ShellCompletion
{
    public const MARKER = '# pablo: shell completion written by pablo system:install (removed by pablo system:uninstall)';

    public const SHELLS = ['bash', 'zsh', 'fish'];

    /** Always `pablo`: the script completes the command on the PATH, whatever binary installs it. */
    private const COMMAND_NAME = 'pablo';

    /** Where $shell (a path or a name) loads completions from, or null for a shell without one. */
    public static function path(string $shell, string $home): ?string
    {
        return match (basename($shell)) {
            'bash' => $home.'/.local/share/bash-completion/completions/pablo',
            'zsh' => $home.'/.local/share/zsh/site-functions/_pablo',
            'fish' => $home.'/.config/fish/completions/pablo.fish',
            default => null,
        };
    }

    /**
     * The script of `pablo completion <shell>`, with the marker line (after
     * zsh's `#compdef`, which must stay first). Rendered here rather than by
     * running the command: it names the script after argv[0], which is the
     * downloaded file's name when the installer runs a fresh binary.
     */
    public static function script(string $shell): string
    {
        $resources = \dirname((string) (new \ReflectionClass(DumpCompletionCommand::class))->getFileName(), 2).'/Resources';
        $template = (string) file_get_contents($resources.'/completion.'.$shell);
        $script = str_replace(['{{ COMMAND_NAME }}', '{{ VERSION }}'], [self::COMMAND_NAME, CompleteCommand::COMPLETION_API_VERSION], $template);
        if (str_starts_with($script, '#compdef')) {
            [$first, $rest] = explode("\n", $script, 2) + [1 => ''];

            return $first."\n".self::MARKER."\n".$rest;
        }

        return self::MARKER."\n".$script;
    }

    /**
     * Install the completion of $shell (a path or a name, e.g. $SHELL).
     *
     * @return list<string> what happened, for the install log
     */
    public static function install(string $shell, string $home): array
    {
        $name = basename($shell);
        $path = self::path($name, $home);
        if (null === $path) {
            return [\sprintf('no shell completion installed for %s (supported: %s) — see pablo completion --help', '' !== $name ? $name : 'an unknown shell', implode(', ', self::SHELLS))];
        }
        if (is_link($path) || (file_exists($path) && !self::isOurs($path))) {
            return ["⚠ {$path} exists and is not PABLO's: left alone (pablo completion {$name} prints the script)"];
        }

        $script = self::script($name);
        if (is_file($path) && file_get_contents($path) === $script) {
            $messages = ["shell completion for {$name} is up to date ({$path})"];
        } else {
            $dir = \dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0o777, true);
            }
            if (false === @file_put_contents($path, $script)) {
                return ["⚠ could not write {$path}: no shell completion (pablo completion {$name} prints the script)"];
            }
            $messages = ["installed the {$name} completion: {$path}"];
        }

        return [...$messages, ...self::notes($name, $path)];
    }

    /**
     * Remove every completion file PABLO wrote (whichever shell it was for).
     *
     * @return list<string> what was removed
     */
    public static function remove(string $home): array
    {
        $removed = [];
        foreach (self::SHELLS as $shell) {
            $path = (string) self::path($shell, $home);
            if (!is_link($path) && is_file($path) && self::isOurs($path)) {
                @unlink($path);
                $removed[] = "removed {$path}";
            }
        }

        return $removed;
    }

    private static function isOurs(string $path): bool
    {
        $content = @file_get_contents($path);

        return false !== $content && \in_array(self::MARKER, explode("\n", $content), true);
    }

    /** @return list<string> */
    private static function notes(string $shell, string $path): array
    {
        return match ($shell) {
            'bash' => ['   loaded by the bash-completion package (v2+) in new shells'],
            'zsh' => ['   zsh loads it when '.\dirname($path).' is in fpath before compinit — if TAB does not complete pablo, add to ~/.zshrc:',
                '   fpath=('.\dirname($path).' $fpath); autoload -Uz compinit && compinit'],
            default => [],
        };
    }
}
