<?php

declare(strict_types=1);

namespace Pablo\Provider\Forge;

final class PrInfo
{
    public function __construct(
        public int $number,
        public string $title,
        public string $state, // OPEN | CLOSED | MERGED
        public bool $isDraft,
        public string $url,
        public ?string $mergedAt,
        public ?string $baseRefName = null,
    ) {
    }

    /**
     * The prState() answer from a PR's raw state and mergedAt: a PR with a
     * mergedAt is MERGED whatever its state says.
     */
    public static function normalizeState(string $state, mixed $mergedAt): string
    {
        $state = strtoupper($state);

        return 'MERGED' === $state || !empty($mergedAt) ? 'MERGED' : $state;
    }
}
