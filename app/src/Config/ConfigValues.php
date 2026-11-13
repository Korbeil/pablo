<?php

declare(strict_types=1);

namespace Pablo\Config;

use Pablo\Support\PabloError;

/**
 * Reads values out of a parsed project file, shared by the per-section
 * named constructors of ProjectConfig's value objects.
 */
final class ConfigValues
{
    /**
     * A required key, by dotted path; missing throws naming the file.
     *
     * @param array<string, mixed> $data
     */
    public static function required(array $data, string $dotted, string $file): mixed
    {
        $node = $data;
        foreach (explode('.', $dotted) as $part) {
            if (!\is_array($node) || !\array_key_exists($part, $node)) {
                throw new PabloError("{$file}: missing required key '{$dotted}'");
            }
            $node = $node[$part];
        }

        return $node;
    }

    /**
     * An optional key, by dotted path (null when missing).
     *
     * @param array<string, mixed> $data
     */
    public static function optional(array $data, string $dotted): mixed
    {
        $node = $data;
        foreach (explode('.', $dotted) as $part) {
            if (!\is_array($node) || !\array_key_exists($part, $node)) {
                return null;
            }
            $node = $node[$part];
        }

        return $node;
    }

    /**
     * <section>.<key>: the project's value, else the global default, else an
     * error naming the key.
     *
     * @param array<string, mixed> $project
     * @param array<string, mixed> $defaults
     */
    public static function merged(array $project, array $defaults, string $section, string $key): mixed
    {
        foreach ([$project, $defaults] as $source) {
            $value = $source[$section] ?? null;
            if (\is_array($value) && \array_key_exists($key, $value)) {
                return $value[$key];
            }
        }
        throw new PabloError("no value for {$section}.{$key} — set it in the project config or ~/.pablo/config.yaml");
    }
}
