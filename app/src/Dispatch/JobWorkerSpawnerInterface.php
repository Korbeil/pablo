<?php

declare(strict_types=1);

namespace Pablo\Dispatch;

/**
 * Starts one dispatcher job of one project in a child process of the running
 * PABLO (ProcessJobWorkerSpawner), or a scripted fake in tests. Reaches the
 * outside world: always a required constructor argument, never defaulted
 * (tests/RequiredCollaboratorsTest.php).
 */
interface JobWorkerSpawnerInterface
{
    /**
     * Start the child and return at once, without waiting for it.
     *
     * @throws \Pablo\Support\PabloError when it cannot be started
     */
    public function start(string $project, string $job): JobWorkerInterface;
}
