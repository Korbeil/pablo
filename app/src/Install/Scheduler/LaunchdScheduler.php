<?php

declare(strict_types=1);

namespace Pablo\Install\Scheduler;

use Pablo\Support\Files;
use Pablo\Support\PabloError;
use Pablo\Support\ProcessRunnerInterface;

/**
 * macOS: launchd agents in ~/Library/LaunchAgents, rendered from the bundled
 * launchd/*.plist.template files.
 *
 * Only ever runs on macOS (SchedulerFactory).
 */
final class LaunchdScheduler implements SchedulerInterface
{
    public const DISPATCH = 'com.pablo.dispatch';
    public const PRUNE = 'com.pablo.prune';
    public const WEB = 'com.pablo.web';

    public function __construct(
        private readonly ProcessRunnerInterface $runner,
        private readonly string $templatesDir,
        private readonly string $agentsDir,
        private readonly string $logDir,
        private readonly string $home,
        private readonly int $uid,
    ) {
    }

    public function install(string $pabloBin, bool $withWeb, string $path, \Closure $info): void
    {
        $this->load(self::DISPATCH, $pabloBin, $path);
        $info(self::DISPATCH." loaded (every 5 minutes; logs: {$this->logDir}/dispatch.log)");
        $this->load(self::PRUNE, $pabloBin, $path);
        $info(self::PRUNE." loaded (daily at 03:00; logs: {$this->logDir}/prune.log)");

        if ($withWeb) {
            $this->load(self::WEB, $pabloBin, $path);
            $info(self::WEB." loaded (the dashboard; logs: {$this->logDir}/web.log)");
        } elseif (ServiceFiles::isOurs($this->plist(self::WEB), self::WEB)) {
            $this->runner->probe(['launchctl', 'bootout', $this->domain().'/'.self::WEB]);
            ServiceFiles::removeIfOurs($this->plist(self::WEB), self::WEB);
            $info('--without-web: removed '.self::WEB);
        }
    }

    public function uninstall(\Closure $info): void
    {
        foreach ([self::DISPATCH, self::PRUNE, self::WEB] as $label) {
            $this->runner->probe(['launchctl', 'bootout', $this->domain().'/'.$label]);
            if (ServiceFiles::removeIfOurs($this->plist($label), $label)) {
                $info('removed '.$this->plist($label));
            }
        }
    }

    public function hasWebService(): bool
    {
        return ServiceFiles::isOurs($this->plist(self::WEB), self::WEB);
    }

    public function dispatchStatus(): SchedulerStatus
    {
        $installed = ServiceFiles::isOurs($this->plist(self::DISPATCH), self::DISPATCH);

        return new SchedulerStatus(
            self::DISPATCH,
            $installed,
            $installed && 0 === $this->runner->probe(['launchctl', 'print', $this->domain().'/'.self::DISPATCH], 10)->exitCode,
        );
    }

    /** @throws PabloError */
    private function load(string $label, string $pabloBin, string $path): void
    {
        Files::ensureDir($this->logDir);
        $content = str_replace(
            ['@PABLO_BIN@', '@LOG_DIR@', '@HOME@', '@PATH@'],
            array_map(
                static fn (string $value): string => htmlspecialchars($value, \ENT_XML1 | \ENT_QUOTES),
                [$pabloBin, $this->logDir, $this->home, $path],
            ),
            ServiceFiles::bundled($this->templatesDir.'/'.$label.'.plist.template'),
        );
        ServiceFiles::write($this->plist($label), $label, $content);

        // bootout first so a re-install picks up the fresh plist (idempotent).
        $this->runner->probe(['launchctl', 'bootout', $this->domain().'/'.$label]);
        $result = $this->runner->probe(['launchctl', 'bootstrap', $this->domain(), $this->plist($label)]);
        if (0 !== $result->exitCode) {
            throw new PabloError("launchctl bootstrap {$label} failed: {$result->output}");
        }
    }

    private function plist(string $label): string
    {
        return $this->agentsDir.'/'.$label.'.plist';
    }

    private function domain(): string
    {
        return 'gui/'.$this->uid;
    }
}
