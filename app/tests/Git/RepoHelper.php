<?php

declare(strict_types=1);

namespace Pablo\Tests\Git;

use Symfony\Component\Process\Process;

final class RepoHelper
{
    /** @param list<string> $args */
    public static function git(string $cwd, array $args): string
    {
        $p = new Process(['git', '-C', $cwd, ...$args]);
        $p->run();
        if (!$p->isSuccessful()) {
            throw new \RuntimeException('git '.implode(' ', $args).' failed: '.$p->getErrorOutput());
        }

        return trim($p->getOutput());
    }

    public static function commitFile(string $repo, string $name, string $content, string $message): string
    {
        file_put_contents($repo.'/'.$name, $content);
        self::git($repo, ['add', $name]);
        self::git($repo, ['commit', '-q', '-m', $message]);

        return self::git($repo, ['rev-parse', 'HEAD']);
    }

    /** @return array{origin: string, clone: string, other: string} */
    public static function makeRepos(string $tmp): array
    {
        $origin = $tmp.'/origin.git';
        $clone = $tmp.'/clone';
        $other = $tmp.'/other';
        (new Process(['git', 'init', '-q', '--bare', '-b', 'main', $origin]))->run();
        foreach ([$clone, $other] as $dest) {
            (new Process(['git', 'clone', '-q', $origin, $dest]))->run();
            self::git($dest, ['config', 'user.email', 'test@example.com']);
            self::git($dest, ['config', 'user.name', 'Test']);
        }
        self::commitFile($clone, 'README.md', "hello\n", 'initial');
        self::git($clone, ['push', '-q', 'origin', 'main']);
        self::git($other, ['pull', '-q', 'origin', 'main']);

        return ['origin' => $origin, 'clone' => $clone, 'other' => $other];
    }
}
