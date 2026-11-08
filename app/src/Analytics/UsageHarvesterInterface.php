<?php

declare(strict_types=1);

namespace Pablo\Analytics;

/**
 * Harvests token/cost usage for one concluded agent run from the local
 * agent runtime's own storage. Best-effort: never throws, null when nothing
 * can be attributed to the run.
 */
interface UsageHarvesterInterface
{
    /**
     * @param string      $launchedAt  UTC ISO-8601 launch time
     * @param string|null $fingerprint PromptFingerprint::of() the prompt sent, when known
     */
    public function harvest(string $worktree, string $launchedAt, ?string $fingerprint): ?UsageSnapshot;
}
