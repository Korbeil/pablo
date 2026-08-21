<?php

declare(strict_types=1);

namespace Pablo\Provider\Gh;

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
}
