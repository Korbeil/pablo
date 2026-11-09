<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents\Ade\Headless;

use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Agents\Runtime\OpenCode\OpenCodeRuntime;
use Pablo\Agents\Runtime\RuntimeAgentFilesInterface;
use Pablo\Agents\Runtime\RuntimeOneShotInterface;
use Pablo\Agents\Runtime\RuntimeSessionsInterface;
use Pablo\Analytics\UsageHarvesterInterface;
use Pablo\Doctor\CliProbe;
use Pablo\Support\ProcessRunnerInterface;

/**
 * A runtime test doubles extend to replace launchCommand() with a harmless
 * shell command: everything else is OpenCode's, and nothing here ever runs
 * a real agent CLI.
 */
abstract class DelegatingRuntime implements AgentRuntimeInterface
{
    private OpenCodeRuntime $inner;

    public function __construct()
    {
        $this->inner = new OpenCodeRuntime();
    }

    abstract public function launchCommand(string $agent, string $prompt, ?string $model, ?string $sessionId = null, ?string $detachedIn = null): string;

    public function name(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fake';
    }

    public function binary(): string
    {
        return 'sh';
    }

    public function defaultModel(): string
    {
        return $this->inner->defaultModel();
    }

    public function newSessionId(): ?string
    {
        return null;
    }

    public function interactiveCommand(string $agent, string $prompt, ?string $model): ?string
    {
        return null;
    }

    public function modelError(string $model): ?string
    {
        return null;
    }

    public function sessions(): RuntimeSessionsInterface
    {
        return $this->inner->sessions();
    }

    public function agentFiles(): RuntimeAgentFilesInterface
    {
        return $this->inner->agentFiles();
    }

    public function usage(ProcessRunnerInterface $runner): UsageHarvesterInterface
    {
        return $this->inner->usage($runner);
    }

    public function oneShot(): RuntimeOneShotInterface
    {
        return $this->inner->oneShot();
    }

    public function probe(): CliProbe
    {
        return $this->inner->probe();
    }
}
