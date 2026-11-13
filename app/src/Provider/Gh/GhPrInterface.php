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

    /** @param list<string> $ignoreChecks */
    public function ciStatus(string $repoSlug, int $prNumber, array $ignoreChecks = []): string;

    /**
     * The review verdict since GitHub's last "ready for review" event, by
     * the rules of ReviewVerdict::evaluate().
     *
     * @param list<string> $botWhitelist
     *
     * @return ReviewVerdict::APPROVED|ReviewVerdict::CHANGES|null
     */
    public function reviewVerdict(string $repoSlug, int $prNumber, array $botWhitelist): ?string;

    /**
     * One poll cycle's reads of a repository in as few calls as possible
     * (one GraphQL query per SNAPSHOT_PRS_PER_QUERY PRs / SNAPSHOT_BRANCHES_PER_QUERY
     * branches): prForBranch() of each $branches, prState() and the
     * ciStatus() rollup of each $prNumbers, reviewVerdict()'s inputs of each
     * $reviewPrNumbers. Never throws: whatever it could not read in full is
     * absent from the snapshot, for the per-PR call to answer.
     *
     * @param list<string> $branches
     * @param list<int>    $prNumbers
     * @param list<int>    $reviewPrNumbers a subset of $prNumbers
     */
    public function pollSnapshot(string $repoSlug, array $branches, array $prNumbers, array $reviewPrNumbers): PrSnapshot;

    public function markReady(string $repoSlug, int $prNumber): void;

    public function markDraft(string $repoSlug, int $prNumber): void;

    /** OPEN | CLOSED | MERGED (a PR with a mergedAt is MERGED whatever its state says). */
    public function prState(string $repoSlug, int $prNumber): string;

    /** @return array<int, string> */
    public function rerunCi(string $repoSlug, string $branch): array;
}
