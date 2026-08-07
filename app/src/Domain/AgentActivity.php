<?php

declare(strict_types=1);

namespace Pablo\Domain;

use Pablo\Agents\SessionInfo;

/**
 * Agent sessions on a task's worktree, as counts rather than as a string.
 *
 * $total can exceed running + waiting: a session in any other status still
 * counts towards the total but contributes no segment to the rendered cell.
 * That asymmetry is long-standing behaviour, so render() preserves it.
 */
final readonly class AgentActivity
{
    public function __construct(
        public int $total,
        public int $running,
        public int $waiting,
    ) {
    }

    public static function none(): self
    {
        return new self(0, 0, 0);
    }

    /** @param array<int, SessionInfo> $sessions */
    public static function fromSessions(array $sessions): self
    {
        return new self(
            \count($sessions),
            \count(array_filter($sessions, static fn (SessionInfo $s) => 'running' === $s->status)),
            \count(array_filter($sessions, static fn (SessionInfo $s) => 'waiting' === $s->status)),
        );
    }

    /** Rebuilds from the poller's display cache (count + rendered activity cell). */
    public static function fromDisplay(?int $total, ?string $activity): self
    {
        if (null === $total || 0 === $total) {
            return self::none();
        }
        $activity ??= '';
        $running = 1 === preg_match('/🏃\s*(\d+)/u', $activity, $m) ? (int) $m[1] : 0;
        $waiting = 1 === preg_match('/💭\s*(\d+)/u', $activity, $m) ? (int) $m[1] : 0;

        return new self($total, $running, $waiting);
    }

    /** The terminal cell. Must stay byte-identical to the historical output. */
    public function render(): string
    {
        if (0 === $this->total) {
            return '-';
        }
        $parts = [];
        if ($this->running) {
            $parts[] = "🏃 {$this->running}";
        }
        if ($this->waiting) {
            $parts[] = "💭 {$this->waiting}";
        }

        return implode(' · ', $parts);
    }

    /** True when at least one agent is blocked waiting for the user. */
    public function isWaiting(): bool
    {
        return $this->waiting > 0;
    }
}
