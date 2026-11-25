<?php

declare(strict_types=1);

namespace Pablo\Provider\Forge;

/**
 * One entry of a PR's statusCheckRollup, normalised over GitHub's two shapes
 * (legacy status contexts expose `state`; check runs expose
 * `status`+`conclusion`).
 */
final readonly class CiCheck
{
    /**
     * CANCELLED is deliberately absent: a run cancelled by a workflow
     * concurrency group (superseded by a newer trigger) is not a failure, and
     * a cancelled run that is never replaced says nothing about the code.
     */
    private const FAILURE_CONCLUSIONS = [
        'FAILURE', 'TIMED_OUT', 'ACTION_REQUIRED', 'STARTUP_FAILURE', 'ERROR',
    ];

    /**
     * @param ?string $runKey    identifies the check across its runs (workflow + check name),
     *                           null for legacy status contexts, which GitHub already reports once
     * @param string  $startedAt ISO-8601 UTC start of the run ('' when unknown), orders runs of one $runKey
     */
    public function __construct(
        public string $name,
        public bool $failed,
        public bool $pending,
        public ?string $runKey = null,
        public string $startedAt = '',
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
        $runKey = isset($raw['name']) ? ($raw['workflowName'] ?? '')."\0".$raw['name'] : null;

        return new self(
            $name,
            $failed,
            ($raw['status'] ?? null) !== 'COMPLETED' && !$failed,
            $runKey,
            (string) ($raw['startedAt'] ?? ''),
        );
    }

    /**
     * @param list<string> $ignoreChecks
     */
    private static function isIgnored(self $check, array $ignoreChecks): bool
    {
        foreach ($ignoreChecks as $marker) {
            if (str_contains($check->name, strtolower((string) $marker))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The pure CI evaluation behind every forge's ciStatus(): red | pending | green.
     *
     * @param list<self>   $rollup
     * @param list<string> $ignoreChecks
     */
    public static function evaluate(array $rollup, array $ignoreChecks = []): string
    {
        $pending = false;
        foreach (self::latestRuns($rollup) as $check) {
            if (self::isIgnored($check, $ignoreChecks)) {
                continue;
            }
            if ($check->failed) {
                return 'red';
            }
            if ($check->pending) {
                $pending = true;
            }
        }

        return $pending ? 'pending' : 'green';
    }

    /**
     * Keeps only the most recent run of each check run: a workflow triggered
     * twice on one commit leaves both runs in the rollup, and the older one
     * (often cancelled or failed, then superseded) must not decide the state.
     * Legacy status contexts carry no run key and are all kept.
     *
     * @param list<self> $rollup
     *
     * @return list<self>
     */
    private static function latestRuns(array $rollup): array
    {
        $kept = [];
        foreach ($rollup as $i => $check) {
            if (null === $check->runKey) {
                $kept[$i] = $check;
                continue;
            }
            $key = 'run:'.$check->runKey;
            if (!isset($kept[$key]) || $check->startedAt >= $kept[$key]->startedAt) {
                $kept[$key] = $check;
            }
        }

        return array_values($kept);
    }
}
