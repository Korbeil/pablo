<?php

declare(strict_types=1);

namespace Pablo\Install\Scheduler;

use Pablo\Support\PabloError;
use Pablo\Support\ProcessRunnerInterface;

/**
 * Linux: systemd user units in ~/.config/systemd/user, written from the
 * bundled systemd/ directory.
 *
 * One of the two files PortabilityTest lets name systemctl: it only ever runs
 * on Linux (SchedulerFactory), the engine itself stays portable.
 */
final class SystemdScheduler implements SchedulerInterface
{
    public const DISPATCH_UNITS = ['pablo-dispatch.service', 'pablo-dispatch.timer'];
    public const WEB_UNIT = 'pablo-web.service';

    public function __construct(
        private readonly ProcessRunnerInterface $runner,
        private readonly string $sourceDir,
        private readonly string $unitDir,
        private readonly string $user,
    ) {
    }

    public function install(string $pabloBin, bool $withWeb, \Closure $info): void
    {
        foreach (self::DISPATCH_UNITS as $unit) {
            $this->writeUnit($unit);
            $info("wrote {$this->unitDir}/{$unit}");
        }
        // User units only start at boot if the user lingers; otherwise systemd
        // waits for a login that never comes on a headless box, and everything
        // stays down after a reboot even though it is `enabled`.
        if (0 !== $this->runner->probe(['loginctl', 'enable-linger', $this->user])->exitCode) {
            $info('⚠ could not enable lingering — PABLO units will NOT start until you log in');
        }
        $this->systemctl(['daemon-reload']);
        $this->systemctl(['enable', '--now', 'pablo-dispatch.timer']);
        $info('pablo-dispatch.timer enabled (every 5 minutes; logs: journalctl --user -u pablo-dispatch.service)');

        $web = $this->unitDir.'/'.self::WEB_UNIT;
        if ($withWeb) {
            $this->writeUnit(self::WEB_UNIT);
            $info("wrote {$web}");
            $this->systemctl(['daemon-reload']);
            $this->systemctl(['enable', '--now', self::WEB_UNIT]);
            // A running dashboard keeps serving the old binary until restarted.
            $this->systemctl(['restart', self::WEB_UNIT]);
            $info('pablo-web.service enabled (the dashboard; logs: journalctl --user -u pablo-web.service)');
        } elseif (ServiceFiles::isOurs($web, self::WEB_UNIT)) {
            $this->runner->probe(['systemctl', '--user', 'disable', '--now', self::WEB_UNIT]);
            ServiceFiles::removeIfOurs($web, self::WEB_UNIT);
            $this->systemctl(['daemon-reload']);
            $info('--without-web: removed pablo-web.service');
        }
    }

    public function uninstall(\Closure $info): void
    {
        $this->runner->probe(['systemctl', '--user', 'disable', '--now', 'pablo-dispatch.timer']);
        $this->runner->probe(['systemctl', '--user', 'disable', '--now', self::WEB_UNIT]);
        foreach ([...self::DISPATCH_UNITS, self::WEB_UNIT] as $unit) {
            if (ServiceFiles::removeIfOurs($this->unitDir.'/'.$unit, $unit)) {
                $info("removed {$this->unitDir}/{$unit}");
            }
        }
        $this->runner->probe(['systemctl', '--user', 'daemon-reload']);
    }

    public function hasWebService(): bool
    {
        return ServiceFiles::isOurs($this->unitDir.'/'.self::WEB_UNIT, self::WEB_UNIT);
    }

    public function dispatchStatus(): SchedulerStatus
    {
        $timer = 'pablo-dispatch.timer';
        $installed = ServiceFiles::isOurs($this->unitDir.'/'.$timer, $timer);

        return new SchedulerStatus(
            $timer,
            $installed,
            $installed && 0 === $this->runner->probe(['systemctl', '--user', 'is-active', '--quiet', $timer], 10)->exitCode,
        );
    }

    private function writeUnit(string $unit): void
    {
        ServiceFiles::write($this->unitDir.'/'.$unit, $unit, ServiceFiles::bundled($this->sourceDir.'/'.$unit));
    }

    /**
     * @param list<string> $arguments
     *
     * @throws PabloError
     */
    private function systemctl(array $arguments): void
    {
        $result = $this->runner->probe(['systemctl', '--user', ...$arguments]);
        if (0 !== $result->exitCode) {
            throw new PabloError('systemctl --user '.implode(' ', $arguments).' failed: '.$result->output);
        }
    }
}
