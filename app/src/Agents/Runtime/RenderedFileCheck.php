<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime;

use Pablo\Agents\AgentTemplateError;
use Pablo\Doctor\AgentFileState;
use Pablo\Doctor\AgentFileStatus;
use Pablo\Doctor\LinkedFileCheck;

/**
 * A runtime's staleness check of one file against a fresh render: when the
 * templates cannot be rendered, the file is reported unverifiable instead
 * of failing the doctor.
 */
final class RenderedFileCheck
{
    /**
     * A PABLO symlink compared against the render (for a linked directory,
     * its $file), see LinkedFileCheck::check().
     *
     * @param callable(): string $render
     */
    public static function linked(string $label, string $dest, callable $render, ?string $file = null): AgentFileStatus
    {
        try {
            $fresh = $render();
        } catch (AgentTemplateError $e) {
            return self::unverifiable($label, $e);
        }

        return LinkedFileCheck::check($label, $dest, $fresh, $file);
    }

    /**
     * A generated file (not a link) compared against the render.
     *
     * @param callable(): string $render
     */
    public static function generated(string $label, string $path, callable $render): AgentFileStatus
    {
        try {
            $fresh = $render();
        } catch (AgentTemplateError $e) {
            return self::unverifiable($label, $e);
        }

        return LinkedFileCheck::generated($label, $path, $fresh);
    }

    private static function unverifiable(string $label, AgentTemplateError $e): AgentFileStatus
    {
        return new AgentFileStatus($label, AgentFileState::Unverifiable, 'cannot verify freshness: '.$e->getMessage());
    }
}
