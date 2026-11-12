<?php

declare(strict_types=1);

namespace Pablo\Support;

/**
 * Checked JSON decoding. A CLI answering an empty or HTML page instead of
 * JSON must surface as a clear PabloError naming what was being read, not as
 * a TypeError on a null further down; best-effort readers that deliberately
 * tolerate junk (pidfiles, analytics lines, usage harvesters) use
 * tryDecodeArray() and keep their own fallback.
 */
final class Json
{
    private const EXCERPT_LENGTH = 200;

    /**
     * Decodes a JSON object (or array) into an associative array.
     *
     * @param string $what what is being parsed, for the error ("gh pr view output")
     *
     * @return array<mixed>
     *
     * @throws PabloError when $json is not valid JSON or not an object/array
     */
    public static function decodeArray(string $json, string $what): array
    {
        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new PabloError("cannot parse {$what}: {$e->getMessage()}, got: ".self::excerpt($json), 0, $e);
        }
        if (!\is_array($data)) {
            throw new PabloError("cannot parse {$what}: expected a JSON object or array, got: ".self::excerpt($json));
        }

        return $data;
    }

    /**
     * Decodes a JSON array (a list) — `gh … list --json`, `gh api` pages.
     *
     * @return list<mixed>
     *
     * @throws PabloError when $json is not valid JSON or not a JSON array
     */
    public static function decodeList(string $json, string $what): array
    {
        $data = self::decodeArray($json, $what);
        if (!array_is_list($data)) {
            throw new PabloError("cannot parse {$what}: expected a JSON array, got: ".self::excerpt($json));
        }

        return $data;
    }

    /**
     * Best-effort decode: the array, or null for anything that is not a valid
     * JSON object/array (junk, truncated line, empty file, scalar).
     *
     * @return array<mixed>|null
     */
    public static function tryDecodeArray(string $json): ?array
    {
        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($data) ? $data : null;
    }

    private static function excerpt(string $json): string
    {
        $trimmed = trim($json);
        if ('' === $trimmed) {
            return '(empty output)';
        }

        return var_export(mb_substr($trimmed, 0, self::EXCERPT_LENGTH), true);
    }
}
