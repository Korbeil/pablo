<?php

declare(strict_types=1);

namespace Pablo\Config;

/**
 * One problem found in a config file: an error (the value cannot work) or a
 * warning (an unknown key, ignored). Reported by `system:config:validate`
 * and `system:doctor`; a loader turns the first error into its PabloError.
 */
final class ConfigIssue
{
    public const ERROR = 'error';
    public const WARNING = 'warning';

    /**
     * @param string $file  the file's path
     * @param string $label the file's name in messages (config.yaml, wallet-kit.yaml)
     * @param string $key   the dotted key path, '' for the whole file
     */
    public function __construct(
        public readonly string $severity,
        public readonly string $file,
        public readonly string $label,
        public readonly string $key,
        public readonly string $message,
    ) {
    }

    public static function error(string $file, string $label, string $key, string $message): self
    {
        return new self(self::ERROR, $file, $label, $key, $message);
    }

    public static function warning(string $file, string $label, string $key, string $message): self
    {
        return new self(self::WARNING, $file, $label, $key, $message);
    }

    public function isError(): bool
    {
        return self::ERROR === $this->severity;
    }

    /** The key and the problem: `sync.interval_minutes must be …`. */
    public function detail(): string
    {
        return '' !== $this->key ? "{$this->key} {$this->message}" : $this->message;
    }

    /** The full message, file name first: `wallet-kit.yaml: sync.interval_minutes must be …`. */
    public function text(): string
    {
        return "{$this->label}: {$this->detail()}";
    }

    /** @return array{file: string, key: string, severity: string, message: string} */
    public function toArray(): array
    {
        return ['file' => $this->file, 'key' => $this->key, 'severity' => $this->severity, 'message' => $this->message];
    }
}
