<?php

declare(strict_types=1);

namespace Pablo\Tests;

use Pablo\Provider\Gh\GhPrInterface;
use Pablo\Provider\Gh\PrInfo;

/**
 * Test double for GhPrInterface. Mirrors the old GhPr::set* seams as
 * configurable callables/records instead of global mutable state.
 */
final class FakeGhPr implements GhPrInterface
{
    /** @var list<int> recorded pr numbers sent to markDraft() */
    public array $drafts = [];

    /** @var list<int> recorded pr numbers sent to markReady() */
    public array $readies = [];

    /** @var list<string> recorded rerunCi() branches */
    public array $rerunBranches = [];

    /** @var array<string, PrInfo> branch => PR returned by prForBranch()/prsForBranches()/Bulk */
    public array $prByBranch = [];

    /** @var callable|null fn(slug, branch): ?PrInfo */
    public $onPrForBranch;

    /** @var string canned ciStatus(): 'red'|'green'|'pending' */
    public string $ci = 'green';

    /** @var callable|null fn(slug, pr, ignoreChecks): string */
    public $onCiStatus;

    public function prForBranch(string $repoSlug, string $branch): ?PrInfo
    {
        if (null !== $this->onPrForBranch) {
            return ($this->onPrForBranch)($repoSlug, $branch);
        }

        return $this->prByBranch[$branch] ?? null;
    }

    public function prsForBranches(string $repoSlug, array $branches): array
    {
        return array_intersect_key($this->prByBranch, array_fill_keys($branches, true));
    }

    public function prsForBranchesBulk(array $repoBranches): array
    {
        $all = [];
        foreach ($repoBranches as [$slug, $branches]) {
            $all[$slug] = $this->prsForBranches($slug, $branches);
        }

        return $all;
    }

    public function evaluateCi(array $rollup, array $ignoreChecks = []): string
    {
        foreach ($rollup as $check) {
            if ($check->failed) {
                return 'red';
            }
        }

        return 'green';
    }

    public function ciStatus(string $repoSlug, int $prNumber, array $ignoreChecks = []): string
    {
        if (null !== $this->onCiStatus) {
            return ($this->onCiStatus)($repoSlug, $prNumber, $ignoreChecks);
        }

        return $this->ci;
    }

    public function readyAnchor(string $repoSlug, int $prNumber): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2000-01-01T00:00:00Z');
    }

    /** @var callable|null fn(slug, pr): \Pablo\Provider\Gh\PrReviews */
    public $onFetchReviews;

    public function fetchReviews(string $repoSlug, int $prNumber): \Pablo\Provider\Gh\PrReviews
    {
        if (null !== $this->onFetchReviews) {
            return ($this->onFetchReviews)($repoSlug, $prNumber);
        }

        return new \Pablo\Provider\Gh\PrReviews('', []);
    }

    public function evaluateReviews(array $reviews, \DateTimeImmutable $anchor, string $author, array $botWhitelist): ?string
    {
        if (null !== $this->evaluateReviewsOverride) {
            return ($this->evaluateReviewsOverride)($reviews, $anchor, $author, $botWhitelist);
        }

        return null;
    }

    public function markReady(string $repoSlug, int $prNumber): void
    {
        $this->readies[] = $prNumber;
    }

    public function markDraft(string $repoSlug, int $prNumber): void
    {
        $this->drafts[] = $prNumber;
    }

    /** @var callable|null fn(slug, pr): bool */
    public $isMergedOverride;

    /** @var callable|null fn(reviews, anchor, author, whitelist): ?string */
    public $evaluateReviewsOverride;

    public function isMerged(string $repoSlug, int $prNumber): bool
    {
        if (null !== $this->isMergedOverride) {
            return ($this->isMergedOverride)($repoSlug, $prNumber);
        }

        return false;
    }

    public function rerunCi(string $repoSlug, string $branch): array
    {
        $this->rerunBranches[] = $branch;

        return ['42', '43'];
    }
}
