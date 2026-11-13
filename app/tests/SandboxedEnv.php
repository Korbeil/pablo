<?php

declare(strict_types=1);

namespace Pablo\Tests;

/**
 * Process environment changes (HOME, PABLO_* overrides...) undone after the
 * test: setEnv() records a variable's original value the first time it is
 * touched, restoreEnv() (from tearDown) puts every one back, unsetting those
 * that were not set.
 */
trait SandboxedEnv
{
    /** @var array<string, string|false> */
    private array $sandboxedEnv = [];

    /** Sets $name, or unsets it when $value is null. */
    protected function setEnv(string $name, ?string $value): void
    {
        if (!\array_key_exists($name, $this->sandboxedEnv)) {
            $this->sandboxedEnv[$name] = getenv($name);
        }
        putenv(null === $value ? $name : "{$name}={$value}");
    }

    protected function restoreEnv(): void
    {
        foreach ($this->sandboxedEnv as $name => $value) {
            putenv(false === $value ? $name : "{$name}={$value}");
        }
        $this->sandboxedEnv = [];
    }
}
