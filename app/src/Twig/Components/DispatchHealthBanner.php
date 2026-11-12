<?php

declare(strict_types=1);

namespace Pablo\Twig\Components;

use Pablo\Dashboard\Dashboard;
use Pablo\Dispatch\DispatchHealth;
use Pablo\Dispatch\DispatchRecord;
use Pablo\Dispatch\JobHealth;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * A banner listing the background dispatcher's failing jobs (and a run that
 * aborted on its CLI preflight). Renders nothing when all is well.
 *
 * Read-only and local: it reads the DispatchHealth files under
 * ~/.pablo/stamps/, never a CLI. Not filtered by project type — a broken
 * dispatcher matters whatever tab is open. Re-renders every minute.
 */
#[AsLiveComponent]
final class DispatchHealthBanner
{
    use DefaultActionTrait;

    public const POLL_MS = 60000;

    public function __construct(
        private readonly DispatchHealth $health,
        private readonly Dashboard $dashboard,
    ) {
    }

    /** @return list<JobHealth> */
    public function failing(): array
    {
        return $this->health->failing($this->dashboard->projects());
    }

    /** The last run, only when it aborted on its CLI preflight. */
    public function preflightFailure(): ?DispatchRecord
    {
        $last = $this->health->lastDispatch();

        return null !== $last && DispatchHealth::OUTCOME_PREFLIGHT_FAILED === $last->outcome ? $last : null;
    }
}
