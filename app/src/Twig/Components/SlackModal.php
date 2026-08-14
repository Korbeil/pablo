<?php

declare(strict_types=1);

namespace Pablo\Twig\Components;

use Pablo\Dashboard\Dashboard;
use Pablo\Domain\State;
use Pablo\Listing\Listing;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The two paste-ready Slack blocks rendered by the dashboard.
 *
 * The only part of the dashboard that talks to the network: Listing::queueTasks
 * does a live `gh` PR lookup per queued task. That is why it loads on demand
 * behind a spinner rather than on page load, and why nothing here polls.
 */
#[AsLiveComponent]
final class SlackModal
{
    use DefaultActionTrait;

    #[LiveProp]
    public bool $open = false;

    #[LiveProp]
    public bool $loaded = false;

    #[LiveProp]
    public string $reviewBlock = '';

    #[LiveProp]
    public string $testingBlock = '';

    #[LiveProp]
    public ?string $error = null;

    public function __construct(
        private readonly Store $store,
        private readonly Dashboard $dashboard,
    ) {
    }

    /**
     * Opening fetches in the same round trip, so the button carries the
     * loading state and the modal is never shown empty. Re-opening reuses
     * what was already fetched; "refresh" forces a new lookup.
     */
    #[LiveAction]
    public function open(): void
    {
        $this->open = true;
        if (!$this->loaded) {
            $this->load();
        }
    }

    #[LiveAction]
    public function refresh(): void
    {
        $this->loaded = false;
        $this->load();
    }

    #[LiveAction]
    public function load(): void
    {
        $this->error = null;
        try {
            $projects = $this->dashboard->projects();
            $this->reviewBlock = $this->block($projects, State::WaitingReview);
            $this->testingBlock = $this->block($projects, State::NeedsTesting);
        } catch (PabloError $e) {
            $this->error = $e->getMessage();
        }
        $this->loaded = true;
    }

    #[LiveAction]
    public function close(): void
    {
        $this->open = false;
    }

    /** @param array<string, \Pablo\Config\ProjectConfig> $projects */
    private function block(array $projects, State $state): string
    {
        return Listing::renderSlack(
            Listing::queueTasks($projects, $this->store, $state->value),
            $state->value,
        );
    }
}
