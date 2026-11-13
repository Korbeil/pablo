<?php

declare(strict_types=1);

namespace Pablo\Hooks;

use Pablo\Config\ProjectConfig;
use Pablo\Domain\Agent;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Support\PabloError;
use Pablo\Support\RepoSlug;

/**
 * The user's own notification hook (`hooks` in ~/.pablo/config.yaml or a
 * project config): one shell command, run detached on two events —
 * `transition` (a task settled into a new state) and `agent-finished` (an
 * auto-launched agent's analysis is ready). PABLO itself sends nothing
 * anywhere: whatever the command does is the user's choice.
 *
 * Best-effort by contract: never throws, never blocks the caller.
 */
final class Hooks
{
    public const EVENT_TRANSITION = 'transition';
    public const EVENT_AGENT_FINISHED = 'agent-finished';

    public function __construct(
        private readonly HookRunnerInterface $runner = new NullHookRunner(),
        private readonly ?RepoSlug $repoSlug = null,
    ) {
    }

    /**
     * $task has settled into its current state, coming from $from through
     * $entered (every state entered on the way, pass-through ones included,
     * in order). Nothing fires when the state did not change, or when the
     * project's `hooks.states` filter matches none of the entered states.
     *
     * @param list<State> $entered
     */
    public function transition(ProjectConfig $cfg, Task $task, ?State $from, array $entered): void
    {
        if (null === $cfg->hookCommand || $from === $task->state) {
            return;
        }
        if (null !== $cfg->hookStates && [] === array_intersect(
            array_map(static fn (State $s): string => $s->value, $entered),
            array_map(static fn (State $s): string => $s->value, $cfg->hookStates),
        )) {
            return;
        }
        $this->fire($cfg, $task, self::EVENT_TRANSITION, [
            'PABLO_FROM' => $from->value ?? '',
            'PABLO_TO' => $task->state->value,
        ]);
    }

    /** An auto-launched agent's run was stamped finished: the user's move. */
    public function agentFinished(ProjectConfig $cfg, Task $task, Agent $agent): void
    {
        if (null === $cfg->hookCommand || !$cfg->hookAgentFinished || Agent::StartupScript === $agent) {
            return; // the startup script is setup, not an analysis to read
        }
        $this->fire($cfg, $task, self::EVENT_AGENT_FINISHED, ['PABLO_AGENT' => $agent->value]);
    }

    /**
     * The environment the command runs with, for $event.
     *
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    public function environment(ProjectConfig $cfg, Task $task, string $event, array $extra = []): array
    {
        return array_merge([
            'PABLO_EVENT' => $event,
            'PABLO_PROJECT' => $task->project,
            'PABLO_TASK' => $task->branch,
            'PABLO_STATE' => $task->state->value,
            'PABLO_FROM' => '',
            'PABLO_TO' => '',
            'PABLO_AGENT' => '',
            'PABLO_PR_URL' => $this->prUrl($cfg, $task),
            'PABLO_ISSUE_KEY' => $task->issue->key ?? '',
            'PABLO_WORKTREE' => $task->worktreePath,
        ], $extra);
    }

    /** @param array<string, string> $extra */
    private function fire(ProjectConfig $cfg, Task $task, string $event, array $extra): void
    {
        try {
            $this->runner->run((string) $cfg->hookCommand, $this->environment($cfg, $task, $event, $extra), $task->worktreePath);
        } catch (\Throwable) {
            // a hook must never fail what fired it
        }
    }

    private function prUrl(ProjectConfig $cfg, Task $task): string
    {
        if (null === $task->prNumber || null === $this->repoSlug) {
            return '';
        }
        try {
            return 'https://github.com/'.$this->repoSlug->for($cfg).'/pull/'.$task->prNumber;
        } catch (PabloError) { // no GitHub origin: the hook just gets no PR URL
            return '';
        }
    }
}
