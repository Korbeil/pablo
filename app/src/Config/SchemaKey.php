<?php

declare(strict_types=1);

namespace Pablo\Config;

/**
 * One key of the config schema (see ConfigSchema): a mapping of known
 * child keys, or a leaf with its value check.
 */
final class SchemaKey
{
    /**
     * @param array<string, SchemaKey>|null   $children a mapping's known keys, null for a leaf
     * @param (\Closure(mixed): ?string)|null $check    a leaf's check: the problem ("must be …"), or null when valid
     * @param bool                            $deferred checked by validate and the doctor only: the code reading the key
     *                                                  refuses a bad value itself, so loading the file does not fail on it
     * @param string|null                     $closed   a mapping whose unknown keys are errors, not warnings: the noun
     *                                                  for them ("type" → "has unknown type 'x'")
     * @param string                          $mapping  how a mapping is described when the value is not one
     */
    public function __construct(
        public readonly ?array $children = null,
        public readonly ?\Closure $check = null,
        public readonly bool $deferred = false,
        public readonly ?string $closed = null,
        public readonly string $mapping = 'a mapping',
    ) {
    }

    /** @param \Closure(mixed): ?string $check */
    public static function leaf(\Closure $check, bool $deferred = false): self
    {
        return new self(check: $check, deferred: $deferred);
    }

    /** @param array<string, SchemaKey> $children */
    public static function map(array $children, bool $deferred = false, ?string $closed = null, string $mapping = 'a mapping'): self
    {
        return new self(children: $children, deferred: $deferred, closed: $closed, mapping: $mapping);
    }
}
