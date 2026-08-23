<?php

declare(strict_types=1);

namespace Pablo\Analytics;

use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Domain\Time;

/**
 * Append-only JSONL analytics under ~/.pablo/analytics/<project>/<YYYY-MM>.jsonl.
 *
 * One event per line: {"ts": ..., "type": ..., "project": ...payload}. The
 * root is resolved per call (never at container compile time — the compiled
 * container would freeze PABLO_ANALYTICS_DIR / HOME; same rule as Store).
 * Recording is strictly best-effort: any failure is swallowed into
 * analytics-errors.log so task flows can never break because of analytics.
 */
final class JsonlAnalytics implements AnalyticsInterface
{
    public function __construct(
        private readonly Time $time = new Time(),
    ) {
    }

    /**
     * Mirrors Store::defaultRoot(): resolved at call time, never as a
     * container parameter.
     */
    public static function root(): string
    {
        $override = getenv('PABLO_ANALYTICS_DIR');
        if (false !== $override && '' !== $override) {
            return $override;
        }

        return (getenv('HOME') ?: '~').'/.pablo/analytics';
    }

    public function taskOpened(Task $task): void
    {
        $this->record($task->project, [
            'type' => 'task_opened',
            'branch' => $task->branch,
            'initial_state' => $task->state->value,
            'issue_provider' => $task->issue?->provider,
            'issue_key' => $task->issue?->key,
            'prompt_len' => null === $task->prompt ? null : mb_strlen($task->prompt),
            'worktree' => $task->worktreePath,
        ]);
    }

    public function stateEntered(Task $task, State $from): void
    {
        $this->record($task->project, [
            'type' => 'state_entered',
            'branch' => $task->branch,
            'from' => $from->value,
            'to' => $task->state->value,
        ]);
    }

    public function agentRunStarted(string $project, string $branch, string $worktree, string $agent, string $backend, string $runId, string $launchedAt, string $prompt): void
    {
        $this->record($project, [
            'type' => 'agent_run_started',
            'branch' => $branch,
            'run_id' => $runId,
            'agent' => $agent,
            'backend' => $backend,
            'worktree' => $worktree,
            'launched_at' => $launchedAt,
            // sha256 prefix of the launch prompt — lets the watcher attribute
            // the right OpenCode session without persisting any prompt text.
            'prompt_fingerprint' => OpenCodeUsage::fingerprint($prompt),
            'prompt_len' => mb_strlen($prompt),
        ]);
    }

    public function agentRunFinished(AgentRunRecord $run): void
    {
        $usage = $run->usage;
        $this->record($run->project, [
            'type' => 'agent_run_finished',
            'branch' => $run->branch,
            'run_id' => $run->runId,
            'agent' => $run->agent,
            'backend' => $run->backend,
            'started_at' => $run->startedAt,
            'finished_at' => $run->finishedAt,
            'duration_s' => $run->durationS,
            'input' => $usage?->input,
            'output' => $usage?->output,
            'reasoning' => $usage?->reasoning,
            'cache_read' => $usage?->cacheRead,
            'cache_write' => $usage?->cacheWrite,
            'tokens_total' => $usage?->totalTokens,
            'cost' => $usage?->cost,
            'models' => null !== $usage ? $usage->models : [],
            'session_id' => $usage?->sessionId,
            'harvest' => null !== $usage ? $usage->quality : 'none',
        ]);
    }

    public function taskClosed(Task $task): void
    {
        $attempts = [];
        foreach ($task->agentLaunches as $label => $launch) {
            $attempts[$label] = $launch->attempts;
        }
        $closedAt = $this->time->utcnow();
        $durationS = max(0, $this->time->parseTs($closedAt)->getTimestamp() - $this->time->parseTs($task->createdAt)->getTimestamp());
        $this->record($task->project, [
            'type' => 'task_closed',
            'branch' => $task->branch,
            'opened_at' => $task->createdAt,
            'closed_at' => $closedAt,
            'final_state' => $task->state->value,
            'merged' => $task->merged,
            'pr_number' => $task->prNumber,
            'agent_runs' => $attempts,
            'duration_s' => $durationS,
        ]);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function record(string $project, array $event): void
    {
        try {
            $line = json_encode(
                ['ts' => $this->time->utcnow(), 'project' => $project, ...$event],
                \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
            );
            if (false === $line) {
                throw new \RuntimeException('json_encode failed');
            }
            $dir = self::root().'/'.$project;
            if (!is_dir($dir)) {
                @mkdir($dir, 0o777, true);
            }
            @file_put_contents($dir.'/'.gmdate('Y-m').'.jsonl', $line."\n", \FILE_APPEND | \LOCK_EX);
        } catch (\Throwable $e) {
            try {
                @file_put_contents(
                    self::root().'/analytics-errors.log',
                    gmdate('c').' '.$e::class.': '.$e->getMessage()."\n",
                    \FILE_APPEND | \LOCK_EX,
                );
            } catch (\Throwable) {
                // nothing left to try — drop the event
            }
        }
    }
}
