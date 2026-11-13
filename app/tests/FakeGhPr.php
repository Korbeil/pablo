<?php

declare(strict_types=1);

namespace Pablo\Tests;

use Pablo\Provider\Gh\GhPrInterface;
use Pablo\Provider\Gh\PrInfo;
use Pablo\Provider\Gh\PrSnapshot;
use Pablo\Provider\Gh\ReviewVerdict;

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

    /** @var list<string> every read call, as "method:argument" (e.g. "prState:7", "prForBranch:wk-45") */
    public array $calls = [];

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
        $this->calls[] = "prForBranch:{$branch}";
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

    public function ciStatus(string $repoSlug, int $prNumber, array $ignoreChecks = []): string
    {
        $this->calls[] = "ciStatus:{$prNumber}";
        if (null !== $this->onCiStatus) {
            return ($this->onCiStatus)($repoSlug, $prNumber, $ignoreChecks);
        }

        return $this->ci;
    }

    /** @var ReviewVerdict::APPROVED|ReviewVerdict::CHANGES|null canned reviewVerdict() */
    public ?string $verdict = null;

    public function reviewVerdict(string $repoSlug, int $prNumber, array $botWhitelist): ?string
    {
        $this->calls[] = "reviewVerdict:{$prNumber}";

        return $this->verdict;
    }

    /** canned pollSnapshot(): empty by default, so every read falls back to its per-PR call */
    public ?PrSnapshot $snapshot = null;

    /** @var callable|null fn(slug, branches, prNumbers, reviewPrNumbers): PrSnapshot, overrides */
    public $onPollSnapshot;

    /** @var list<array{branches: list<string>, prs: list<int>, reviews: list<int>}> every pollSnapshot() call */
    public array $snapshotCalls = [];

    public function pollSnapshot(string $repoSlug, array $branches, array $prNumbers, array $reviewPrNumbers): PrSnapshot
    {
        $this->snapshotCalls[] = ['branches' => $branches, 'prs' => $prNumbers, 'reviews' => $reviewPrNumbers];
        if (null !== $this->onPollSnapshot) {
            return ($this->onPollSnapshot)($repoSlug, $branches, $prNumbers, $reviewPrNumbers);
        }

        return $this->snapshot ?? new PrSnapshot();
    }

    public function markReady(string $repoSlug, int $prNumber): void
    {
        $this->readies[] = $prNumber;
    }

    public function markDraft(string $repoSlug, int $prNumber): void
    {
        $this->drafts[] = $prNumber;
    }

    /** canned prState() answer when no override: MERGED when true, else OPEN */
    public bool $merged = false;

    /** @var callable|null fn(slug, pr): string — defaults to MERGED/OPEN from */
    public $prStateOverride;

    public function prState(string $repoSlug, int $prNumber): string
    {
        $this->calls[] = "prState:{$prNumber}";
        if (null !== $this->prStateOverride) {
            return ($this->prStateOverride)($repoSlug, $prNumber);
        }

        return $this->merged ? 'MERGED' : 'OPEN';
    }

    public function rerunCi(string $repoSlug, string $branch): array
    {
        $this->rerunBranches[] = $branch;

        return ['42', '43'];
    }
}
