<?php

declare(strict_types=1);

namespace Pablo\Agents\Ade\OpenChamber;

use Pablo\Analytics\UsageHarvesterInterface;
use Pablo\Analytics\UsageSnapshot;
use Pablo\Domain\Time;
use Pablo\Support\ProcessRunnerInterface;

/**
 * Aggregates session-level totals from the OpenChamber daemon: its
 * `session list` rows carry pre-summed tokens, cache, cost and model per
 * session. Asked before the runtime's own storage because OpenChamber-
 * launched runs live behind OpenChamber's own OpenCode server, where the
 * plain CLI's project-scoped listing and export cannot see them. Rows hold no
 * transcripts, so attribution here is always window-based (the fingerprint is
 * unused). Best-effort: never throws.
 */
final class OpenChamberUsage implements UsageHarvesterInterface
{
    private const SESSION_GRACE_S = 5;
    private const SESSION_MAX_COUNT = 200;

    public function __construct(
        private readonly ProcessRunnerInterface $runner,
        private readonly Time $time = new Time(),
    ) {
    }

    public function harvest(string $worktree, string $launchedAt, ?string $fingerprint): ?UsageSnapshot
    {
        try {
            $probe = $this->runner->probe([
                'openchamber', 'session', 'list', '--dir', $worktree,
                '--limit', (string) self::SESSION_MAX_COUNT, '--all', '--json',
            ]);
            if (0 !== $probe->exitCode) {
                return null;
            }
            $decoded = json_decode($probe->output, true);
            $sessions = \is_array($decoded) ? ($decoded['sessions'] ?? null) : null;
            if (!\is_array($sessions)) {
                return null;
            }
            $windowStartS = $this->time->parseTs($launchedAt)->getTimestamp() - self::SESSION_GRACE_S;
            $hits = [];
            foreach ($sessions as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $createdMs = self::intOf(self::arrayOf($row, 'time')['created'] ?? null);
                if (0 === $createdMs || (int) floor($createdMs / 1000) < $windowStartS) {
                    continue;
                }
                $hits[] = ['created' => $createdMs, 'row' => $row];
            }
            if ([] === $hits) {
                return null;
            }
            usort($hits, static fn (array $a, array $b): int => $a['created'] <=> $b['created']);

            $input = $output = $reasoning = $cacheRead = $cacheWrite = 0;
            $cost = 0.0;
            $models = [];
            $ids = [];
            $minCreatedMs = 0;
            $maxUpdatedMs = 0;
            foreach ($hits as $hit) {
                $row = $hit['row'];
                $tokens = self::arrayOf($row, 'tokens');
                $cache = self::arrayOf($tokens, 'cache');
                $input += self::intOf($tokens['input'] ?? null);
                $output += self::intOf($tokens['output'] ?? null);
                $reasoning += self::intOf($tokens['reasoning'] ?? null);
                $cacheRead += self::intOf($cache['read'] ?? null);
                $cacheWrite += self::intOf($cache['write'] ?? null);
                $cost += self::floatOf($row['cost'] ?? null);
                $id = trim((string) ($row['id'] ?? ''));
                if ('' !== $id) {
                    $ids[] = $id;
                }
                $model = self::arrayOf($row, 'model');
                $modelId = trim((string) ($model['id'] ?? ''));
                if ('' !== $modelId) {
                    $providerId = trim((string) ($model['providerID'] ?? ''));
                    $models['' !== $providerId ? $providerId.'/'.$modelId : $modelId] = true;
                }
                $minCreatedMs = 0 === $minCreatedMs ? $hit['created'] : min($minCreatedMs, $hit['created']);
                $updatedMs = self::intOf(self::arrayOf($row, 'time')['updated'] ?? null);
                $maxUpdatedMs = max($maxUpdatedMs, $updatedMs);
            }

            return new UsageSnapshot(
                input: $input,
                output: $output,
                reasoning: $reasoning,
                cacheRead: $cacheRead,
                cacheWrite: $cacheWrite,
                // OpenChamber rows omit the `total` counter; OpenCode's own
                // total is the sum of all components (input + output +
                // reasoning + cache read + cache write).
                totalTokens: $input + $output + $reasoning + $cacheRead + $cacheWrite,
                cost: $cost,
                models: array_keys($models),
                sessionId: implode(',', $ids),
                spanMs: $minCreatedMs > 0 && $maxUpdatedMs > $minCreatedMs ? $maxUpdatedMs - $minCreatedMs : null,
                quality: 'window',
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<array-key, mixed> $source
     *
     * @return array<array-key, mixed>
     */
    private static function arrayOf(array $source, string $key): array
    {
        $value = $source[$key] ?? null;

        return \is_array($value) ? $value : [];
    }

    private static function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function floatOf(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
