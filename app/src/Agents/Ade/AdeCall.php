<?php

declare(strict_types=1);

namespace Pablo\Agents\Ade;

/**
 * Outcome of one ADE CLI call (`orca --json`, `openchamber --json`): the
 * decoded payload on success (null otherwise) plus the failure reason when
 * the call did not succeed, so callers can log why they fell back.
 */
final readonly class AdeCall
{
    /**
     * @param array<string, mixed>|null $payload
     */
    public function __construct(
        public ?array $payload,
        public ?string $reason,
    ) {
    }

    public static function failed(string $reason): self
    {
        return new self(null, $reason);
    }
}
