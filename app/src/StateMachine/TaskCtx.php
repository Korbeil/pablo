<?php

declare(strict_types=1);

namespace Pablo\StateMachine;

use Pablo\Agents\AgentLauncherInterface;
use Pablo\Config\ProjectConfig;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Store\Store;

final class TaskCtx
{
    public ?State $previousState = null;

    public function __construct(
        public Task $task,
        public ProjectConfig $cfg,
        public Store $store,
        public AgentLauncherInterface $agents,
    ) {
    }
}
