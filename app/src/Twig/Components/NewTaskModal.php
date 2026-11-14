<?php

declare(strict_types=1);

namespace Pablo\Twig\Components;

use Pablo\Config\Config;
use Pablo\Support\PabloError;
use Pablo\Task\StartResult;
use Pablo\Task\TaskStarter;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
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
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public string $mode = 'issue';

    #[LiveProp(writable: true)]
    public string $text = '';

    #[LiveProp(writable: true, onUpdated: 'onProjectUpdated')]
    public string $project = '';

    /**
     * The YOLO checkbox: null until the user touches it, so the project's
     * yolo.enabled decides (see yoloChecked()).
     */
    #[LiveProp(writable: true)]
    public ?bool $yolo = null;

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
        foreach (Config::PROJECT_TYPES as $type) {
            foreach ($projected as $cfg) {
                if ($cfg->type === $type) {
                    $groups[$cfg->type] ??= [];
                    $groups[$cfg->type][] = $cfg->name;
                }
            }
        }

        return $groups;
    }

    /** What the YOLO checkbox shows: the user's choice, else the project's default. */
    public function yoloChecked(): bool
    {
        if (null !== $this->yolo) {
            return $this->yolo;
        }
        if ('prompt' !== $this->mode || '' === $this->project) {
            return false;
        }

        return ($this->starter->projects()[$this->project] ?? null)?->yolo->enabled ?? false;
    }

    /**
     * The projects with yolo.enabled, for issue mode's help: an untouched
     * checkbox leaves the choice to the issue's project.
     *
     * @return list<string>
     */
    public function yoloProjects(): array
    {
        $names = [];
        foreach ($this->starter->projects() as $cfg) {
            if ($cfg->yolo->enabled) {
                $names[] = $cfg->name;
            }
        }
        sort($names);

        return $names;
    }

    /** Another project: the checkbox shows that project's default again. */
    public function onProjectUpdated(): void
    {
        $this->yolo = null;
    }

    /**
     * Every opening starts from a blank form: the component outlives the
     * modal, so without this a second use would show the previous text and
     * message, and re-submit the previous issue.
     */
    #[LiveAction]
    public function open(): void
    {
        $this->text = '';
        $this->yolo = null;
        $this->error = null;
        $this->message = null;
        $this->open = true;
    }

    #[LiveAction]
    public function close(): void
    {
        $this->open = false;
        $this->error = null;
    }

    #[LiveAction]
    public function dismiss(): void
    {
        $this->message = null;
    }

    #[LiveAction]
    public function useIssueMode(): void
    {
        $this->mode = 'issue';
        $this->yolo = null;
    }

    #[LiveAction]
    public function usePromptMode(): void
    {
        $this->mode = 'prompt';
        $this->yolo = null;
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
            $this->message = $this->render($this->starter->start($text, $project, $this->yolo));
            $this->text = '';
            $this->open = false;
            $this->emit(TaskBoard::TASKS_CHANGED_EVENT);
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

        $yolo = $result->yolo ? ' — YOLO mode: PABLO implements the plan and opens the PR on its own' : '';

        return (null !== $result->issueKey
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
            )).$yolo;
    }
}
