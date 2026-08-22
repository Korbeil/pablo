<?php

declare(strict_types=1);

namespace Pablo\Provider\Gh;

/**
 * One entry of a PR's statusCheckRollup, normalised over GitHub's two shapes
 * (legacy status contexts expose `state`; check runs expose
 * `status`+`conclusion`).
 */
final readonly class CiCheck
{
    private const FAILURE_CONCLUSIONS = [
        'FAILURE', 'TIMED_OUT', 'CANCELLED', 'ACTION_REQUIRED', 'STARTUP_FAILURE', 'ERROR',
    ];

    public function __construct(
        public string $name,
        public bool $failed,
        public bool $pending,
    ) {
    }

    /**
     * @param array<string, mixed> $raw one rollup node as returned by gh --json
     */
    public static function fromRollup(array $raw): self
    {
        $name = strtolower((string) ($raw['name'] ?? $raw['workflowName'] ?? $raw['context'] ?? ''));

        if (\array_key_exists('state', $raw)) {
            // Legacy status context.
            $state = (string) $raw['state'];

            return new self(
                $name,
                \in_array($state, ['FAILURE', 'ERROR'], true),
                \in_array($state, ['PENDING', 'EXPECTED'], true),
            );
        }

        // Check run.
        $conclusion = strtoupper((string) ($raw['conclusion'] ?? ''));
        $failed = \in_array($conclusion, self::FAILURE_CONCLUSIONS, true);

        return new self(
            $name,
            $failed,
            ($raw['status'] ?? null) !== 'COMPLETED' && !$failed,
        );
    }
}
