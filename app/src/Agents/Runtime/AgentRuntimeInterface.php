<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime;

use Pablo\Analytics\UsageHarvesterInterface;
use Pablo\Doctor\CliProbe;
use Pablo\Support\ProcessRunnerInterface;

/**
 * The agent CLI that actually runs PABLO's agents (OpenCode, Claude Code,
 * GitHub Copilot CLI, …). Orthogonal to the ADE (Ade\AdeInterface), which
 * only decides where that command runs.
 *
 * Everything that differs between runtimes lives behind this interface and
 * the role interfaces it hands out — code outside Agents\Runtime never
 * names a concrete runtime. Selected globally (`agent_runtime` in
 * ~/.pablo/config.yaml, overridable by PABLO_AGENT_RUNTIME) and resolved per
 * call by AgentRuntimeRegistry — never at container compile time.
 */
interface AgentRuntimeInterface
{
    /** The `agent_runtime` value, e.g. 'opencode'. */
    public function name(): string;

    /** Human name, e.g. 'Claude Code'. */
    public function label(): string;

    /** The CLI binary the runtime needs on PATH. */
    public function binary(): string;

    /** Model documented as the default for this runtime (written by system:install). */
    public function defaultModel(): string;

    /**
     * A session id PABLO assigns up front, or null when the runtime picks its
     * own (see RuntimeSessionsInterface::findSession()).
     */
    public function newSessionId(): ?string;

    /**
     * Shell command (no `cd`, no redirection) running $agent once on $prompt,
     * exiting when the agent is done. Every argument is shell-escaped.
     *
     * $detachedIn: the worktree, when the command runs detached in the
     * background with no terminal (and is run from that directory). A runtime
     * may then prepare the worktree and run differently, as long as the
     * command still ends on its own (Claude Code's Remote Control sessions).
     */
    public function launchCommand(string $agent, string $prompt, ?string $model, ?string $sessionId = null, ?string $detachedIn = null): string;

    /**
     * Shell command (no `cd`) an ADE terminal runs to show $agent working on
     * $prompt in the runtime's own interactive interface, where the user then
     * carries on the same session; it ends when the user quits. Null when the
     * runtime has none: the terminal runs launchCommand() instead.
     */
    public function interactiveCommand(string $agent, string $prompt, ?string $model): ?string;

    /** Why $model cannot be used with this runtime, or null when it can. */
    public function modelError(string $model): ?string;

    public function sessions(): RuntimeSessionsInterface;

    public function agentFiles(): RuntimeAgentFilesInterface;

    /** Token/cost usage read back from the runtime's own storage. */
    public function usage(ProcessRunnerInterface $runner): UsageHarvesterInterface;

    public function oneShot(): RuntimeOneShotInterface;

    /** How system:doctor checks, installs and documents the runtime's CLI. */
    public function probe(): CliProbe;
}
