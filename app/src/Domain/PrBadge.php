<?php

declare(strict_types=1);

namespace Pablo\Domain;

use Pablo\Provider\Gh\PrInfo;

/**
 * The pull-request state of a task, as structure rather than as a string.
 *
 * Listing renders this back to the terminal cell it has always produced, and
 * the dashboard renders it as a Bulma tag. The poller's display cache stores
 * the *rendered* form, so fromDisplay() parses our own output back — every
 * kind round-trips, which PrBadgeTest pins down.
 */
final readonly class PrBadge
{
    public function __construct(
        public PrBadgeKind $kind,
        public ?int $number = null,
    ) {
    }

    public static function none(): self
    {
        return new self(PrBadgeKind::None);
    }

    public static function fromTask(Task $task, ?PrInfo $pr): self
    {
        if ($task->merged) {
            return new self(PrBadgeKind::Merged);
        }
        if (null === $task->prNumber) {
            return self::none();
        }
        if (null === $pr) {
            return new self(PrBadgeKind::Unknown, $task->prNumber);
        }
        if ('MERGED' === $pr->state) {
            return new self(PrBadgeKind::Merged, $pr->number);
        }

        return new self($pr->isDraft ? PrBadgeKind::Draft : PrBadgeKind::Open, $pr->number);
    }

    /** Parses a cell previously produced by render(); anything else is None. */
    public static function fromDisplay(string $cell): self
    {
        $cell = trim($cell);
        if ('' === $cell || '-' === $cell) {
            return self::none();
        }
        if (1 === preg_match('/^✅ merged(?: #(\d+))?$/u', $cell, $m)) {
            // A trailing unmatched group is absent from $m, so "✅ merged"
            // (a merged task that never recorded its number) yields null.
            return new self(PrBadgeKind::Merged, isset($m[1]) ? (int) $m[1] : null);
        }
        if (1 === preg_match('/^📝 draft #(\d+)$/u', $cell, $m)) {
            return new self(PrBadgeKind::Draft, (int) $m[1]);
        }
        if (1 === preg_match('/^📖 open #(\d+)$/u', $cell, $m)) {
            return new self(PrBadgeKind::Open, (int) $m[1]);
        }
        if (1 === preg_match('/^#(\d+)$/', $cell, $m)) {
            return new self(PrBadgeKind::Unknown, (int) $m[1]);
        }

        return self::none();
    }

    /** The terminal cell. Must stay byte-identical to the historical output. */
    public function render(): string
    {
        $suffix = null !== $this->number ? " #{$this->number}" : '';

        return match ($this->kind) {
            PrBadgeKind::None => '-',
            PrBadgeKind::Merged => '✅ merged'.$suffix,
            PrBadgeKind::Draft => '📝 draft'.$suffix,
            PrBadgeKind::Open => '📖 open'.$suffix,
            PrBadgeKind::Unknown => ltrim($suffix),
        };
    }

    public function exists(): bool
    {
        return PrBadgeKind::None !== $this->kind;
    }

    /** The GitHub PR URL, or null when the number is unknown. */
    public function url(?string $repoSlug): ?string
    {
        if (null === $repoSlug || null === $this->number) {
            return null;
        }

        return "https://github.com/{$repoSlug}/pull/{$this->number}";
    }
}
