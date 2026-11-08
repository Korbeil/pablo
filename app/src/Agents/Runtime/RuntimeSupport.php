<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime;

use Pablo\Agents\AgentTemplateError;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Helpers several runtimes share when translating the OpenCode templates
 * and building their command lines.
 */
final class RuntimeSupport
{
    /** Prefix the RTK hook puts in front of a rewritten command. */
    public const RTK_PREFIX = 'rtk ';

    /** Shell word: plain words as-is, anything else single-quoted. */
    public static function word(string $value): string
    {
        return 1 === preg_match('/^[\w.-]+$/', $value) ? $value : escapeshellarg($value);
    }

    /** @param list<string> $argv */
    public static function commandLine(array $argv): string
    {
        return implode(' ', array_map(self::word(...), $argv));
    }

    /** A random RFC 4122 version 4 UUID (the session ids PABLO assigns). */
    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     *
     * @throws AgentTemplateError
     */
    public static function splitFrontmatter(string $markdown, string $name): array
    {
        if (1 !== preg_match('/\A---\R(.*?)\R---\R?(.*)\z/s', $markdown, $m)) {
            throw new AgentTemplateError("{$name}: no frontmatter");
        }
        try {
            $front = Yaml::parse($m[1]);
        } catch (ParseException $e) {
            throw new AgentTemplateError("{$name}: invalid frontmatter: ".$e->getMessage());
        }
        if (!\is_array($front)) {
            throw new AgentTemplateError("{$name}: frontmatter is not a mapping");
        }

        return [$front, $m[2]];
    }

    /**
     * OpenCode caps analysts with a hard `steps:` limit; runtimes without a
     * per-agent equivalent get the budget as an instruction (the 1 h watcher
     * timeout stays the hard backstop).
     */
    public static function withStepBudget(string $prompt, ?int $steps): string
    {
        if (null === $steps) {
            return $prompt;
        }

        return $prompt."\n\n## Step budget\n\n"
            ."You have a budget of {$steps} tool steps for this run. Plan your\n"
            ."investigation to fit it and, at the latest on step {$steps}, stop\n"
            ."calling tools and write the deliverable with what you have.\n";
    }
}
