<?php

declare(strict_types=1);

namespace Pablo\Analytics;

/**
 * Typed accessor over one decoded JSONL analytics payload, so aggregation
 * code never pokes at mixed arrays directly.
 */
final class AnalyticsEvent
{
    /**
     * @param array<string, mixed> $event
     */
    private function __construct(
        private readonly array $event,
    ) {
    }

    /**
     * @param array<string, mixed> $event
     */
    public static function fromEvent(array $event): self
    {
        return new self($event);
    }

    public function type(): string
    {
        return (string) ($this->event['type'] ?? '');
    }

    public function ts(): string
    {
        return (string) ($this->event['ts'] ?? '');
    }

    public function project(): string
    {
        return (string) ($this->event['project'] ?? '');
    }

    public function branch(): string
    {
        return (string) ($this->event['branch'] ?? '');
    }

    /** @return mixed the raw payload value or $default when missing */
    public function get(string $key, mixed $default = null): mixed
    {
        return \array_key_exists($key, $this->event) ? $this->event[$key] : $default;
    }
}
