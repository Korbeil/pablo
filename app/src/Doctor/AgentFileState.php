<?php

declare(strict_types=1);

namespace Pablo\Doctor;

/**
 * Verdict for one installed opencode agent file. Stale is soft (cosmetic —
 * the old prompt keeps working; regenerate to refresh); missing, broken
 * symlinks and non-PABLO files are hard: that agent is absent from
 * opencode, so its frontmatter guardrails are not in effect.
 */
enum AgentFileState
{
    case Ok;
    case Stale;
    case Missing;
    case BrokenSymlink;
    case ForeignFile;
    /** Freshness could not be judged (template unreadable / installed file unreadable) — always soft. */
    case Unverifiable;

    public function icon(): string
    {
        return match ($this) {
            self::Ok => '✅',
            self::Stale, self::Unverifiable => '⚠️',
            self::Missing, self::BrokenSymlink, self::ForeignFile => '❌',
        };
    }

    public function hard(): bool
    {
        return match ($this) {
            self::Ok, self::Stale, self::Unverifiable => false,
            self::Missing, self::BrokenSymlink, self::ForeignFile => true,
        };
    }
}
