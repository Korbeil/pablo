<?php

declare(strict_types=1);

namespace Pablo\Housekeeping;

/** One thing `system:prune` removes (or, for a service log, rotates into its archive). */
final readonly class PruneItem
{
    public const AGENT_LOG = 'agent log';
    public const SERVICE_LOG = 'service log';
    public const LOG_ARCHIVE = 'service log archive';
    public const CONTAINER = 'container cache';

    public function __construct(
        public string $kind,
        public string $path,
        /** Its size: freed when removed, moved to $archive when rotated. */
        public int $bytes,
        /** A rotated service log's archive (SERVICE_LOG only). */
        public ?string $archive = null,
    ) {
    }

    /** Bytes this item frees: a rotation only moves them. */
    public function freedBytes(): int
    {
        return self::SERVICE_LOG === $this->kind ? 0 : $this->bytes;
    }

    public function describe(bool $dryRun): string
    {
        if (self::SERVICE_LOG === $this->kind) {
            return ($dryRun ? 'would rotate' : 'rotated')." {$this->kind} {$this->path} into {$this->archive} (".self::humanBytes($this->bytes).')';
        }

        return ($dryRun ? 'would remove' : 'removed')." {$this->kind} {$this->path} (".self::humanBytes($this->bytes).')';
    }

    public static function humanBytes(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $i => $unit) {
            if ($bytes < 1024 ** ($i + 1) || 'GB' === $unit) {
                return 0 === $i ? "{$bytes} B" : \sprintf('%.1f %s', $bytes / 1024 ** $i, $unit);
            }
        }

        return "{$bytes} B";
    }
}
