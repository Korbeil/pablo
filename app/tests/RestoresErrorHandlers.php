<?php

declare(strict_types=1);

namespace Pablo\Tests;

/**
 * Unwinds the global error/exception handlers a booted kernel installs.
 *
 * FrameworkBundle::boot() registers Symfony's ErrorHandler as both handlers,
 * and neither Kernel::shutdown() nor KernelTestCase removes it — PHPUnit then
 * flags the test as risky for leaking handlers.
 *
 * It registers with $replace=false, so only the *first* boot in a process
 * actually pushes anything; a blind restore_*_handler() would pop PHPUnit's own
 * handlers on every boot after that. Hence snapshot-then-compare rather than an
 * unconditional restore.
 */
trait RestoresErrorHandlers
{
    /** @var array{0: mixed, 1: mixed}|null */
    private ?array $handlerSnapshot = null;

    /** Call before booting a kernel. */
    protected function snapshotErrorHandlers(): void
    {
        $this->handlerSnapshot = [self::currentErrorHandler(), self::currentExceptionHandler()];
    }

    /** Call after shutting the kernel down. */
    protected function restoreErrorHandlers(): void
    {
        if (null === $this->handlerSnapshot) {
            return;
        }
        [$error, $exception] = $this->handlerSnapshot;
        $this->handlerSnapshot = null;

        if (self::currentErrorHandler() !== $error) {
            restore_error_handler();
        }
        if (self::currentExceptionHandler() !== $exception) {
            restore_exception_handler();
        }
    }

    private static function currentErrorHandler(): mixed
    {
        $handler = set_error_handler(null);
        restore_error_handler();

        return $handler;
    }

    private static function currentExceptionHandler(): mixed
    {
        $handler = set_exception_handler(null);
        restore_exception_handler();

        return $handler;
    }
}
