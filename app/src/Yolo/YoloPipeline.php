<?php

declare(strict_types=1);

namespace Pablo\Yolo;

use Pablo\Agents\AgentLauncherInterface;
use Pablo\Config\ProjectConfig;
use Pablo\Domain\Agent;
use Pablo\Domain\AgentLaunch;
use Pablo\Domain\Task;
use Pablo\Domain\Time;
use Pablo\StateMachine\StateMachine;
use Pablo\Support\PabloError;

/**
 * YOLO mode: what the user does by hand after a state agent's plan, done by
 * PABLO itself, in that agent's own session.
 *
 *   state agent (read-only plan, unchanged)
 *     → yolo-implement: the runtime's build agent executes the plan
 *     → yolo-commit:    the same session runs /pablo-commit-and-pr
 *
 * Each step starts only once the previous run has finished (its watcher, or
 * the poller's sweep, calls onAgentFinished()). The PABLO agents stay
 * read-only and /pablo-commit-and-pr stays the only path that commits: YOLO
 * only takes the user's place at the keyboard. Every implementation counts
 * as one round; past yolo.max_rounds the plan waits for the user as usual.
 */
final class YoloPipeline
{
    public function __construct(
        private readonly StateMachine $stateMachine,
        private readonly Time $time,
        private readonly YoloPrompts $prompts = new YoloPrompts(),
    ) {
    }

    /** Whether $task runs in YOLO mode: its own choice, else its project's. */
    public static function isOn(Task $task, ProjectConfig $cfg): bool
    {
        return $task->yolo ?? $cfg->yolo->enabled;
    }

    /**
     * $finished's run on $task has concluded: launch the next YOLO step, if
     * any. Mutates $task (the caller holds its lock and saves it after).
     *
     * @return string|null what happened, for the caller's log; null when YOLO had nothing to do
     */
    public function onAgentFinished(ProjectConfig $cfg, Task $task, Agent $finished, AgentLauncherInterface $agents): ?string
    {
        if ($finished->isYoloStep()) {
            return $this->afterStep($cfg, $task, $finished, $agents);
        }
        if (!self::isOn($task, $cfg) || !\in_array($finished, $this->stateMachine->agentsFor($task->state), true) || !$finished->runsModel()) {
            return null;
        }
        if (null !== $task->yoloStep) {
            return null; // a follow-up is already carrying the work on
        }
        if ($task->yoloRounds >= $cfg->yolo->maxRounds) {
            return "YOLO stopped after {$task->yoloRounds} round(s) (yolo.max_rounds): {$finished->value}'s plan waits for you";
        }
        // No session to continue (launched before YOLO was turned on): a
        // fresh one, whose prompt says to analyse first.
        $session = $task->agentLaunches[$finished->value]->sessionId ?? $agents->newSessionId();
        ++$task->yoloRounds;
        $this->launch($task, Agent::YoloImplement, $session, $this->prompts->implement($this->stateMachine->promptFor($finished, $task)), $agents);

        return "{$finished->value} finished → yolo-implement (round {$task->yoloRounds}/{$cfg->yolo->maxRounds})";
    }

    private function afterStep(ProjectConfig $cfg, Task $task, Agent $finished, AgentLauncherInterface $agents): ?string
    {
        if ($task->yoloStep !== $finished) {
            return null; // a stale or superseded run
        }
        $task->yoloStep = null;
        if (Agent::YoloCommit === $finished) {
            return 'yolo-commit finished';
        }
        if (!self::isOn($task, $cfg)) {
            return 'yolo-implement finished, YOLO mode turned off meanwhile: the changes wait for you';
        }
        if (!\in_array($task->state, StateMachine::COMMIT_ALLOWED_FROM, true)) {
            // Paused, or moved on while the implementation ran: committing
            // from here would be refused anyway.
            return "yolo-implement finished, no commit from {$task->state->value}: the changes wait for you";
        }
        $session = $task->agentLaunches[Agent::YoloImplement->value]->sessionId ?? null;
        if (null === $session) {
            return 'yolo-implement finished without a session to continue: the changes wait for you';
        }
        try {
            $prompt = $this->prompts->commit();
        } catch (PabloError $e) {
            return 'yolo-implement finished, no commit: '.$e->getMessage();
        }
        $this->launch($task, Agent::YoloCommit, $session, $prompt, $agents);

        return 'yolo-implement finished → yolo-commit';
    }

    private function launch(Task $task, Agent $step, string $session, string $prompt, AgentLauncherInterface $agents): void
    {
        $task->agentLaunches[$step->value] = new AgentLaunch($step, $this->time->utcnow(), 1, sessionId: $session);
        $task->yoloStep = $step;
        $agents->launchFollowUp($task->worktreePath, $step, $session, $prompt, $task->project, $task->branch);
    }
}
