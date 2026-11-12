<?php

declare(strict_types=1);

namespace Pablo\Config;

/**
 * What ConfigValidator found: the files it read and their problems.
 */
final class ConfigReport
{
    /**
     * @param list<string>      $files       every file checked, ~/.pablo/config.yaml first
     * @param list<ConfigIssue> $issues
     * @param string            $globalFile  the path of ~/.pablo/config.yaml (PABLO_CONFIG), even when missing
     * @param string|null       $globalError why ~/.pablo/config.yaml does not load (every command fails), null when it does
     */
    public function __construct(
        public readonly array $files,
        public readonly array $issues,
        public readonly string $globalFile,
        public readonly ?string $globalError = null,
    ) {
    }

    public function withIssues(ConfigIssue ...$issues): self
    {
        return new self($this->files, [...$this->issues, ...array_values($issues)], $this->globalFile, $this->globalError);
    }

    public function hasErrors(): bool
    {
        return [] !== $this->errors();
    }

    /** @return list<ConfigIssue> */
    public function errors(): array
    {
        return array_values(array_filter($this->issues, static fn (ConfigIssue $i): bool => $i->isError()));
    }

    /** @return list<ConfigIssue> */
    public function warnings(): array
    {
        return array_values(array_filter($this->issues, static fn (ConfigIssue $i): bool => !$i->isError()));
    }

    /** @return list<ConfigIssue> */
    public function issuesOf(string $file): array
    {
        return array_values(array_filter($this->issues, static fn (ConfigIssue $i): bool => $i->file === $file));
    }
}
