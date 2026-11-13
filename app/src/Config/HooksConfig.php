<?php

declare(strict_types=1);

namespace Pablo\Config;

use Pablo\Domain\State;
use Pablo\Support\PabloError;

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
     * turns the hook off for that project), else the global one.
     *
     * @param array<string, mixed> $project
     * @param array<string, mixed> $defaults
     */
    public static function fromYaml(array $project, array $defaults, string $file): self
    {
        if (\array_key_exists('hooks', $project) && null !== $project['hooks'] && !\is_array($project['hooks'])) {
            throw new PabloError("{$file}: hooks must be a mapping (command, states, agent_finished)");
        }
        $command = self::value($project, $defaults, 'command');
        if (null !== $command && !\is_string($command)) {
            throw new PabloError("{$file}: hooks.command must be a shell command string");
        }
        $command = null !== $command && '' !== trim($command) ? $command : null;

        $rawStates = self::value($project, $defaults, 'states');
        $states = null;
        if (null !== $rawStates) {
            if (!\is_array($rawStates)) {
                throw new PabloError("{$file}: hooks.states must be a list of states");
            }
            $states = [];
            foreach ($rawStates as $raw) {
                $state = \is_string($raw) ? State::tryFrom($raw) : null;
                if (null === $state) {
                    throw new PabloError(\sprintf('%s: hooks.states has unknown state %s (expected one of %s)', $file, var_export($raw, true), implode(', ', array_map(static fn (State $s): string => $s->value, State::cases()))));
                }
                $states[] = $state;
            }
        }

        $agentFinished = self::value($project, $defaults, 'agent_finished') ?? true;
        if (!\is_bool($agentFinished)) {
            throw new PabloError("{$file}: hooks.agent_finished must be true or false");
        }

        return new self($command, $states, $agentFinished);
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
