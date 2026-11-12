<?php

declare(strict_types=1);

namespace Pablo\Completion;

use Pablo\Agents\Ade\AdeRegistry;
use Pablo\Agents\Runtime\AgentRuntimeRegistry;
use Pablo\Config\Config;
use Pablo\Domain\State;
use Pablo\Store\Store;
use Symfony\Component\Console\Completion\CompletionInput;

/**
 * Values offered by shell completion (`pablo completion`). Read from local
 * files only — the task records and the project YAML files — so a TAB press
 * is fast and never reaches gh, git, the network or an agent. Any error
 * (unreadable record, broken YAML, missing directory) means no suggestion,
 * never a failure: completion must not print errors into the shell.
 */
final class Suggestions
{
    public function __construct(
        private readonly Store $store,
        private readonly Config $config,
    ) {
    }

    /**
     * Branches of the recorded tasks, all projects or only $project's.
     *
     * @return list<string>
     */
    public function branches(?string $project = null): array
    {
        // allTasks() skips an unreadable record with a warning on stderr,
        // which would land in the middle of the user's command line.
        $stderr = $this->store->stderr;
        $this->store->stderr = fopen('php://memory', 'w') ?: null;
        try {
            $branches = [];
            foreach ($this->store->allTasks(null !== $project && '' !== $project ? $project : null) as $task) {
                $branches[$task->branch] = true;
            }
        } catch (\Throwable) {
            return [];
        } finally {
            $this->store->stderr = $stderr;
        }

        return self::sorted(array_keys($branches));
    }

    /**
     * Configured project names. Each YAML file is read on its own for its
     * `name` key, so one broken file does not hide the others (the full
     * loader would refuse them all).
     *
     * @return list<string>
     */
    public function projects(): array
    {
        try {
            $paths = glob(rtrim($this->config->projectsDir(), '/').'/*.yaml') ?: [];
        } catch (\Throwable) {
            return [];
        }
        $names = [];
        foreach ($paths as $path) {
            try {
                $name = $this->config->loadYaml($path)['name'] ?? null;
            } catch (\Throwable) {
                continue;
            }
            if (\is_string($name) && '' !== $name) {
                $names[$name] = true;
            }
        }

        return self::sorted(array_keys($names));
    }

    /** @return list<string> */
    public static function states(): array
    {
        return array_map(static fn (State $s): string => $s->value, State::cases());
    }

    /** @return list<string> */
    public static function runtimes(): array
    {
        return AgentRuntimeRegistry::NAMES;
    }

    /** @return list<string> */
    public static function ades(): array
    {
        return AdeRegistry::NAMES;
    }

    /**
     * For `suggestedValues:` — task branches, narrowed to the --project
     * already typed on the command line when the command has that option.
     *
     * @return \Closure(CompletionInput): list<string>
     */
    public function branchCompleter(): \Closure
    {
        return function (CompletionInput $input): array {
            $project = $input->hasOption('project') ? $input->getOption('project') : null;

            return $this->branches(\is_string($project) ? $project : null);
        };
    }

    /**
     * For `suggestedValues:` — configured project names.
     *
     * @return \Closure(CompletionInput): list<string>
     */
    public function projectCompleter(): \Closure
    {
        return fn (CompletionInput $input): array => $this->projects();
    }

    /**
     * @param list<int|string> $values
     *
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        $values = array_map('strval', $values);
        sort($values);

        return $values;
    }
}
