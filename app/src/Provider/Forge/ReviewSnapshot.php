<?php

declare(strict_types=1);

namespace Pablo\Provider\Forge;

/**
 * Everything one forge's reviewVerdict() reads of one PR, as a batched
 * read (ForgeInterface::pollSnapshot()) collected it: evaluates to the very
 * verdict the per-PR call would have answered.
 */
interface ReviewSnapshot
{
    /**
     * @param list<string> $botWhitelist
     *
     * @return ReviewVerdict::APPROVED|ReviewVerdict::CHANGES|null
     */
    public function verdict(array $botWhitelist): ?string;
}
