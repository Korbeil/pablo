<?php

declare(strict_types=1);

namespace Pablo\Twig\Components;

use Pablo\Support\PabloError;
use Pablo\Task\StartResult;
use Pablo\Task\TaskStarter;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The dashboard's one write exception: a modal that starts a new task via
 * the same TaskStarter the CLI uses, in two modes:
 *
 * - "issue": a paste-ready issue URL or issue key (ABC-123); no project
 *   is needed — the starter scans every configured project;
 * - "prompt": a free-form prompt textarea plus a project dropdown.
 *
 * Following the Slack modal's shape for the dashboard's other exception:
 * nothing runs on page load or on poll, only on the explicit submit
 * button (which carries the loading state — prompt mode runs the LLM
 * branch summarizer and launches task-analyst, so it can take tens of
 * seconds).
 */
#[AsLiveComponent]
final class NewTaskModal
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public string $mode = 'issue';

    #[LiveProp(writable: true)]
    public string $text = '';

    #[LiveProp(writable: true)]
    public string $project = '';

    #[LiveProp]
    public bool $open = false;

    #[LiveProp]
    public ?string $message = null;

    #[LiveProp]
    public ?string $error = null;

    public function __construct(
        private readonly TaskStarter $starter,
    ) {
    }

    /** @return array<string, list<string>> type => project names, types in Config::PROJECT_TYPES order */
    public function projectChoices(): array
    {
        $projected = $this->starter->projects();
        ksort($projected);

        $groups = [];
        foreach (\Pablo\Config\Config::PROJECT_TYPES as $type) {
            foreach ($projected as $cfg) {
                if ($cfg->type === $type) {
                    $groups[$cfg->type] ??= [];
                    $groups[$cfg->type][] = $cfg->name;
                }
            }
        }

        return $groups;
    }

    #[LiveAction]
    public function open(): void
    {
        $this->open = true;
    }

    #[LiveAction]
    public function close(): void
    {
        $this->open = false;
    }

    #[LiveAction]
    public function useIssueMode(): void
    {
        $this->mode = 'issue';
    }

    #[LiveAction]
    public function usePromptMode(): void
    {
        $this->mode = 'prompt';
    }

    #[LiveAction]
    public function start(): void
    {
        $this->error = null;
        $this->message = null;

        $text = trim($this->text);
        if ('' === $text) {
            $this->error = 'issue' === $this->mode
                ? 'Paste an issue URL or an issue key (e.g. ABC-123).'
                : 'Write what PABLO should work on.';

            return;
        }

        $project = 'prompt' === $this->mode ? ($this->project ?: null) : null;
        if ('prompt' === $this->mode && null === $project) {
            $this->error = 'Pick a project for the prompt task.';

            return;
        }

        try {
            $this->message = $this->render($this->starter->start($text, $project));
            $this->open = false;
        } catch (PabloError $e) {
            $this->error = $e->getMessage();
        }
    }

    private function render(StartResult $result): string
    {
        if ($result->reused) {
            return \sprintf(
                'Task for %s already exists — reusing it (state %s): %s (branch %s)',
                $result->issueKey,
                $result->reusedState,
                $result->worktreePath,
                $result->branch,
            );
        }

        return null !== $result->issueKey
            ? \sprintf(
                'Started %s (%s) in project %s — worktree %s (branch %s), task-analyst is running',
                $result->issueKey,
                $result->issueTitle,
                $result->project,
                $result->worktreePath,
                $result->branch,
            )
            : \sprintf(
                'Started task in project %s — worktree %s (branch %s), task-analyst is running',
                $result->project,
                $result->worktreePath,
                $result->branch,
            );
    }
}
