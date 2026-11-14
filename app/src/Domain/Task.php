<?php

declare(strict_types=1);

namespace Pablo\Domain;

final class Task
{
    public ?Issue $issue = null;

    public ?State $stateBeforeWaiting = null;

    public bool $taskAnalystRan = false;

    public bool $startupScriptRan = false;

    /** @var array<string, AgentLaunch> keyed by agent label value */
    public array $agentLaunches = [];

    public string $stateEnteredAt;

    public ?string $needsTestingEnteredAt = null;

    public ?string $lastHandledSignalAt = null;

    public ?string $lastSeenIssueStatus = null;

    public bool $ciIgnored = false;

    public ?int $prNumber = null;

    public bool $merged = false;

    /**
     * YOLO mode for this task: true/false set per task (task:start --yolo,
     * the dashboard's checkbox, task:yolo), null = the project's
     * yolo.enabled decides (see Yolo\YoloPipeline::isOn()).
     */
    public ?bool $yolo = null;

    /** YOLO implementation runs launched so far (capped by yolo.max_rounds). */
    public int $yoloRounds = 0;

    /** The YOLO follow-up run in flight (yolo-implement or yolo-commit), if any. */
    public ?Agent $yoloStep = null;

    public DisplayCache $displayCache;

    public string $createdAt;

    public string $updatedAt;

    public function __construct(
        public string $project,
        public string $branch,
        public string $worktreePath,
        public State $state,
        public ?string $summary = null,
        public ?string $prompt = null,
        ?string $now = null,
    ) {
        // Records are not services, so the wall clock is the documented
        // default here; callers holding an injected Time pass $now instead.
        $now ??= (new Time())->utcnow();
        $this->stateEnteredAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->displayCache = DisplayCache::empty();
    }

    /**
     * Has PABLO triggered an agent on this task whose run has concluded, so
     * the ball is with the user? The single source of truth for the
     * "💭 Waiting for feedback" split. The startup script is not an agent:
     * its run ending hands nothing to the user. Neither does a run while a
     * YOLO follow-up carries the work on.
     */
    public function hasFinishedAgent(): bool
    {
        if (null !== $this->yoloStep) {
            return false;
        }
        foreach ($this->agentLaunches as $launch) {
            if (Agent::StartupScript !== $launch->agent && null !== $launch->finishedAt) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{project: string, branch: string, worktree_path: string, state: string, state_entered_at: string, issue: ?array<string, mixed>, summary: ?string, prompt: ?string, state_before_waiting: ?string, task_analyst_ran: bool, startup_script_ran: bool, agent_launches: array<string, array{launched_at: ?string, attempts: int, finished_at: ?string, run_id: ?string, reported: bool, session_id?: string}>, needs_testing_entered_at: ?string, last_handled_signal_at: ?string, last_seen_issue_status: ?string, ci_ignored: bool, pr_number: ?int, merged: bool, yolo?: bool, yolo_rounds?: int, yolo_step?: string, created_at: string, updated_at: string, cached_tracker_status: ?string, cached_pr_state: ?string, cached_agent_count: ?int, cached_agent_activity: ?string, cached_at: ?string}
     */
    public function toJson(): array
    {
        $cache = $this->displayCache;

        $json = [
            'project' => $this->project,
            'branch' => $this->branch,
            'worktree_path' => $this->worktreePath,
            'state' => $this->state->value,
            'state_entered_at' => $this->stateEnteredAt,
            'issue' => $this->issue?->toJson(),
            'summary' => $this->summary,
            'prompt' => $this->prompt,
            'state_before_waiting' => $this->stateBeforeWaiting?->value,
            'task_analyst_ran' => $this->taskAnalystRan,
            'startup_script_ran' => $this->startupScriptRan,
            'agent_launches' => array_map(
                static fn (AgentLaunch $l) => $l->toJson(),
                $this->agentLaunches,
            ),
            'needs_testing_entered_at' => $this->needsTestingEnteredAt,
            'last_handled_signal_at' => $this->lastHandledSignalAt,
            'last_seen_issue_status' => $this->lastSeenIssueStatus,
            'ci_ignored' => $this->ciIgnored,
            'pr_number' => $this->prNumber,
            'merged' => $this->merged,
        ];
        // YOLO keys only when set: a task that never used it keeps its
        // record exactly as before.
        if (null !== $this->yolo) {
            $json['yolo'] = $this->yolo;
        }
        if (0 !== $this->yoloRounds) {
            $json['yolo_rounds'] = $this->yoloRounds;
        }
        if (null !== $this->yoloStep) {
            $json['yolo_step'] = $this->yoloStep->value;
        }

        return $json + [
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'cached_tracker_status' => $cache->trackerStatus,
            'cached_pr_state' => $cache->prState,
            'cached_agent_count' => $cache->agentCount,
            'cached_agent_activity' => $cache->agentActivity,
            'cached_at' => $cache->at,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromJson(array $data, ?string $now = null): self
    {
        $now ??= (new Time())->utcnow();
        $issue = $data['issue'] ?? null;
        $task = new self(
            (string) $data['project'],
            (string) $data['branch'],
            (string) $data['worktree_path'],
            State::tryFrom((string) ($data['state'] ?? '')) ?? State::InProgress,
            $data['summary'] ?? null,
            $data['prompt'] ?? null,
        );
        $task->issue = null !== $issue ? Issue::fromJson($issue) : null;
        $task->stateBeforeWaiting = State::tryFrom((string) ($data['state_before_waiting'] ?? ''));
        $task->taskAnalystRan = (bool) ($data['task_analyst_ran'] ?? false);
        $task->startupScriptRan = (bool) ($data['startup_script_ran'] ?? false);
        $launches = [];
        foreach (($data['agent_launches'] ?? []) as $label => $record) {
            $launch = AgentLaunch::fromJson((string) $label, (array) $record);
            $launches[$launch->agent->value] = $launch;
        }
        $task->agentLaunches = $launches;
        $task->stateEnteredAt = (string) ($data['state_entered_at'] ?? $now);
        $task->needsTestingEnteredAt = $data['needs_testing_entered_at'] ?? null;
        $task->lastHandledSignalAt = $data['last_handled_signal_at'] ?? null;
        $task->lastSeenIssueStatus = $data['last_seen_issue_status'] ?? null;
        $task->ciIgnored = (bool) ($data['ci_ignored'] ?? false);
        $task->prNumber = $data['pr_number'] ?? null;
        $task->merged = (bool) ($data['merged'] ?? false);
        $task->yolo = isset($data['yolo']) ? (bool) $data['yolo'] : null;
        $task->yoloRounds = (int) ($data['yolo_rounds'] ?? 0);
        $step = Agent::tryFrom((string) ($data['yolo_step'] ?? ''));
        $task->yoloStep = null !== $step && $step->isYoloStep() ? $step : null;
        $task->createdAt = (string) ($data['created_at'] ?? $now);
        $task->updatedAt = (string) ($data['updated_at'] ?? $now);
        $task->displayCache = DisplayCache::fromJson($data);

        return $task;
    }
}
