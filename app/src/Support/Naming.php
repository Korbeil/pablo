<?php

declare(strict_types=1);

namespace Pablo\Support;

/**
 * Branch naming convention: [project-key]-[issue-id], lowercased.
 *
 * Duplicates get a -2, -3, ... suffix, trying each in order until a free
 * name is found.
 */
final class Naming
{
    public const SLUG_MAX_WORDS = 4;

    public function branchName(string $projectKey, string $issueId): string
    {
        return strtolower($projectKey).'-'.strtolower($issueId);
    }

    /**
     * Branch name for a prompt-started task: key + short slug of the prompt.
     *
     * Uses iconv ASCII//TRANSLIT (ext-intl is not installed). Only divergence
     * from the Python NFKD-drop reference is 'ß' -> "ss" (iconv) instead of
     * the mangled NFKD drop; iconv's output is the correct one.
     */
    public function slugBranch(string $projectKey, string $prompt): string
    {
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT', $prompt);
        if (false === $normalized) {
            $normalized = $prompt;
        }
        $normalized = mb_strtolower($normalized, 'UTF-8');
        $words = preg_split('/[^a-z0-9]+/', $normalized, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $slug = implode('-', \array_slice($words, 0, self::SLUG_MAX_WORDS)) ?: 'task';

        return strtolower($projectKey).'-'.$slug;
    }

    /**
     * @param array<int, string> $taken
     */
    public function dedupe(string $base, array $taken): string
    {
        $taken = array_fill_keys(array_map('strval', $taken), true);
        if (!isset($taken[$base])) {
            return $base;
        }
        $n = 2;
        while (isset($taken["{$base}-{$n}"])) {
            ++$n;
        }

        return "{$base}-{$n}";
    }
}
