<?php

declare(strict_types=1);

namespace Pablo\Poller;

use Pablo\Provider\Gh\GhPr;
use Pablo\Provider\Gh\GhPrInterface;
use Pablo\Provider\Gh\PrInfo;
use Pablo\Provider\Gh\ReviewVerdict;

/**
 * The pull-request reads of one project's poll cycle, so a task costs each
 * `gh` lookup once instead of once per caller (the transition checks, then
 * the display cache).
 *
 * Lives for one pollProject() call only, never persisted. A head branch's
 * PR or a PR's CI status, once fetched, answers every later read of the
 * cycle; a failed fetch is not remembered (the next read retries, as
 * separate calls always did). After a transition the poller forget()s the
 * task's entries: entering a state can flip the PR on GitHub (ready/draft),
 * so what follows reads it fresh, exactly as before.
 */
final class PollPrs
{
    /** @var array<string, ?PrInfo> head branch => its newest PR (null: none) */
    private array $branchPrs = [];

    /** @var array<int, array<string, string>> PR number => ignore-list key => CI status */
    private array $ciStatuses = [];

    /** Why the repository's slug could not be resolved, if it could not. */
    private ?\Throwable $repoError = null;

    public function __construct(
        private readonly GhPrInterface $gh,
        private readonly string $slug,
    ) {
    }

    /**
     * The cycle of a project whose repository slug could not be resolved:
     * every task fails on that error (ensureRepo()), as it did when each
     * task resolved the slug itself.
     */
    public static function unavailable(GhPrInterface $gh, \Throwable $error): self
    {
        $prs = new self($gh, '');
        $prs->repoError = $error;

        return $prs;
    }

    /** Throws the slug resolution's error, if any. */
    public function ensureRepo(): void
    {
        if (null !== $this->repoError) {
            throw $this->repoError;
        }
    }

    /** GhPrInterface::prForBranch(), fetched once per cycle. */
    public function prForBranch(string $branch): ?PrInfo
    {
        if (!\array_key_exists($branch, $this->branchPrs)) {
            $this->branchPrs[$branch] = $this->gh->prForBranch($this->slug, $branch);
        }

        return $this->branchPrs[$branch];
    }

    /**
     * Fetches $branch's PR ahead of time when it is not known yet, without
     * failing: a later prForBranch() retries a fetch that failed here.
     */
    public function warm(string $branch): void
    {
        try {
            $this->prForBranch($branch);
        } catch (\Throwable) {
            // only an optimisation: the reads that need it fetch it themselves
        }
    }

    /**
     * GhPrInterface::prState(), answered from $branch's PR when it was
     * already fetched this cycle and is PR $prNumber (`gh pr list` returns
     * the same state and mergedAt as `gh pr view`).
     */
    public function prState(int $prNumber, string $branch): string
    {
        $pr = $this->branchPrs[$branch] ?? null;
        if (null !== $pr && $pr->number === $prNumber) {
            return GhPr::normalizeState($pr->state, $pr->mergedAt);
        }

        return $this->gh->prState($this->slug, $prNumber);
    }

    /**
     * GhPrInterface::ciStatus(), fetched once per cycle: a draft task asks
     * twice (CI red, then CI green) and nothing changes in between.
     *
     * @param list<string> $ignoreChecks
     */
    public function ciStatus(int $prNumber, array $ignoreChecks): string
    {
        $key = implode("\0", $ignoreChecks);

        return $this->ciStatuses[$prNumber][$key] ??= $this->gh->ciStatus($this->slug, $prNumber, $ignoreChecks);
    }

    /**
     * @param list<string> $botWhitelist
     *
     * @return ReviewVerdict::APPROVED|ReviewVerdict::CHANGES|null
     */
    public function reviewVerdict(int $prNumber, array $botWhitelist): ?string
    {
        return $this->gh->reviewVerdict($this->slug, $prNumber, $botWhitelist);
    }

    /** Drops what this cycle knows of a task's PRs: the next reads go to GitHub. */
    public function forget(string $branch, ?int ...$prNumbers): void
    {
        unset($this->branchPrs[$branch]);
        foreach ($prNumbers as $prNumber) {
            if (null !== $prNumber) {
                unset($this->ciStatuses[$prNumber]);
            }
        }
    }
}
