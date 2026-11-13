<?php

declare(strict_types=1);

namespace Pablo\Config;

use Pablo\Domain\State;

/**
 * hooks.*: the user's own command, run after transitions and agent runs.
 */
final class HooksConfig
{
    /**
     * @param list<State>|null $states null: every transition fires the hook
     */
    public function __construct(
        public readonly ?string $command = null,
        public readonly ?array $states = null,
        public readonly bool $agentFinished = true,
    ) {
    }

    /**
     * Each key is the project's value when it sets it (even to null, which
     * turns the hook off for that project), else the global one. Both arrays
     * are already checked by ConfigSchema (see Config, GlobalConfig).
     *
     * @param array<string, mixed> $project
     * @param array<string, mixed> $defaults
     */
    public static function fromYaml(array $project, array $defaults): self
    {
        $command = self::value($project, $defaults, 'command');
        $states = self::value($project, $defaults, 'states');

        return new self(
            command: \is_string($command) && '' !== trim($command) ? $command : null,
            states: \is_array($states) ? array_values(array_map(static fn (mixed $state): State => State::from((string) $state), $states)) : null,
            agentFinished: (bool) (self::value($project, $defaults, 'agent_finished') ?? true),
        );
    }

    /**
     * @param array<string, mixed> $project
     * @param array<string, mixed> $defaults
     */
    private static function value(array $project, array $defaults, string $key): mixed
    {
        foreach ([$project, $defaults] as $source) {
            $hooks = $source['hooks'] ?? null;
            if (\is_array($hooks) && \array_key_exists($key, $hooks)) {
                return $hooks[$key];
            }
        }

        return null;
    }
}
