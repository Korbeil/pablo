<?php

declare(strict_types=1);

namespace Pablo\Agents;

/**
 * Outcome of one `orca --json` call: the decoded `result` payload on success
 * (null otherwise) plus the failure reason when the call did not succeed.
 */
final readonly class OrcaCall
{
    /**
     * @param array<string, mixed>|null $payload
     */
    public function __construct(
        public ?array $payload,
        public ?string $reason,
    ) {
    }
}
