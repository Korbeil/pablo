<?php

declare(strict_types=1);

namespace Pablo\Install\Scheduler;

/**
 * The background services of a PABLO install: the dispatcher every five
 * minutes, and the dashboard unless --without-web. One implementation per
 * platform (SchedulerFactory picks it); the unit/plist files are bundled,
 * written — never symlinked, they may come from inside the binary — and
 * point at ~/.local/bin/pablo.
 *
 * Files are PABLO's when they carry their `marker:` line (or are a symlink,
 * which is what the clone install created); anything else at those paths is
 * left alone: install refuses, uninstall skips it.
 */
interface SchedulerInterface
{
    /**
     * @param \Closure(string): void $info
     *
     * @throws \Pablo\Support\PabloError
     */
    public function install(string $pabloBin, bool $withWeb, \Closure $info): void;

    /** @param \Closure(string): void $info */
    public function uninstall(\Closure $info): void;

    /**
     * Whether PABLO's dashboard service is installed — false after
     * `--without-web`, which `pablo update` must keep.
     */
    public function hasWebService(): bool;

    /**
     * Whether the dispatcher's timer/agent is PABLO's and currently active —
     * read-only, for system:doctor.
     */
    public function dispatchStatus(): SchedulerStatus;
}
