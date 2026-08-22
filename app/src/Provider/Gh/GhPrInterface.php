<?php

declare(strict_types=1);

namespace Pablo\Provider\Gh;

/**
 * GitHub PR plumbing via the gh CLI: CI status, review evaluation, draft/ready
 * toggling, merge detection.
 *
 * Abstraction so engine services and commands stay testable: tests inject a
 * fake implementing this interface instead of calling real gh.
 */
interface GhPrInterface
{
    public function prForBranch(string $repoSlug, string $branch): ?PrInfo;

    /**
     * @param list<string> $branches
     *
     * @return array<string, PrInfo>
     */
    public function prsForBranches(string $repoSlug, array $branches): array;

    /**
     * @param list<array{0: string, 1: list<string>}> $repoBranches [slug, branches]
     *
     * @return array<string, array<string, PrInfo>> slug => branch => PrInfo
     */
    public function prsForBranchesBulk(array $repoBranches): array;

    /**
     * @param list<CiCheck> $rollup
     * @param list<string>  $ignoreChecks
     */
    public function evaluateCi(array $rollup, array $ignoreChecks = []): string;

    /** @param list<string> $ignoreChecks */
    public function ciStatus(string $repoSlug, int $prNumber, array $ignoreChecks = []): string;

    public function readyAnchor(string $repoSlug, int $prNumber): \DateTimeImmutable;

    public function fetchReviews(string $repoSlug, int $prNumber): PrReviews;

    /**
     * @param list<ReviewNode> $reviews
     * @param list<string>     $botWhitelist
     */
    public function evaluateReviews(array $reviews, \DateTimeImmutable $anchor, string $author, array $botWhitelist): ?string;

    public function markReady(string $repoSlug, int $prNumber): void;

    public function markDraft(string $repoSlug, int $prNumber): void;

    public function isMerged(string $repoSlug, int $prNumber): bool;

    /** @return array<int, string> */
    public function rerunCi(string $repoSlug, string $branch): array;
}
