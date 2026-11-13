<?php

declare(strict_types=1);

namespace Pablo\Provider\Gh;

/**
 * What GhPrInterface::pollSnapshot() read of a repository's pull requests in
 * one go, for one poll cycle (never persisted).
 *
 * Only complete answers are kept: whatever a batched read could not give in
 * full (a PR it could not resolve, more check runs, reviews or threads than
 * one page) is absent, and the caller asks the per-PR call instead, which
 * paginates. Each map answers exactly what its per-PR call would have.
 */
final readonly class PrSnapshot
{
    /**
     * @param array<string, ?PrInfo>     $branchPrs head branch => prForBranch() (null: no PR)
     * @param array<int, string>         $states    PR number => prState()
     * @param array<int, list<CiCheck>>  $rollups   PR number => the statusCheckRollup ciStatus() evaluates
     * @param array<int, ReviewSnapshot> $reviews   PR number => what reviewVerdict() evaluates
     */
    public function __construct(
        public array $branchPrs = [],
        public array $states = [],
        public array $rollups = [],
        public array $reviews = [],
    ) {
    }
}
