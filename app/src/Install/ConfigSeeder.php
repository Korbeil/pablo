<?php

declare(strict_types=1);

namespace Pablo\Install;

use Pablo\Agents\Ade\AdeRegistry;
use Pablo\Agents\Runtime\AgentRuntimeRegistry;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Support\Files;
use Pablo\Support\PabloError;
use Pablo\Support\PabloPaths;

/**
 * The ~/.pablo/config.yaml part of `pablo system:install`.
 *
 * A first install seeds it from the bundled examples/config.yaml and picks
 * the tools it finds (AgentRuntimeRegistry::DEFAULT is the default runtime,
 * AdeRegistry::DEFAULT the default ADE); an existing config is never rewritten, except for the keys an
 * explicit --runtime / --backend sets. Keys are edited line by line, so the
 * user's comments and layout survive.
 */
final class ConfigSeeder
{
    /** @var \Closure(string): bool */
    private readonly \Closure $hasCommand;

    /**
     * @param (\Closure(string): bool)|null $hasCommand whether a CLI is on the PATH (tests)
     */
    public function __construct(
        private readonly GlobalConfig $global,
        private readonly Config $config,
        private readonly AgentRuntimeRegistry $runtimes,
        private readonly AdeRegistry $ades,
        ?\Closure $hasCommand = null,
    ) {
        $this->hasCommand = $hasCommand ?? InstallPaths::hasCommand(...);
    }

    /**
     * Refuse bad --runtime / --backend values before anything is touched.
     *
     * @throws PabloError
     */
    public function validate(?string $runtime, ?string $backend): void
    {
        if (null !== $runtime) {
            AgentRuntimeRegistry::checkName($runtime, '--runtime');
        }
        if (null !== $backend) {
            AdeRegistry::checkName($backend, '--backend');
        }
        if (null !== $backend && null !== $runtime
            && null !== ($error = $this->ades->resolve($backend)->runtimeError($this->runtimes->resolve($runtime)))) {
            throw new PabloError($error);
        }
    }

    /**
     * Seed or update the config, then return the runtime the rest of the
     * install works for.
     *
     * @param \Closure(string): void $info
     *
     * @throws PabloError
     */
    public function apply(?string $runtimeOption, ?string $backendOption, \Closure $info): string
    {
        self::validate($runtimeOption, $backendOption);

        $projectsDir = $this->config->projectsDir();
        if (!is_dir($projectsDir)) {
            Files::ensureDir($projectsDir);
            $info("created {$projectsDir}");
        }

        $file = $this->global->configPath();
        if (!is_file($file)) {
            $this->seed($file, $info);
        } else {
            $info("keeping existing {$file}");
        }

        // Explicit choices (--runtime / --backend) win over what the config says.
        if (null !== $runtimeOption) {
            $current = self::value($file, 'agent_runtime') ?? AgentRuntimeRegistry::DEFAULT;
            if ($runtimeOption !== $current) {
                // The model id format follows the runtime, so it changes with it.
                $model = $this->runtimes->resolve($runtimeOption)->defaultModel();
                self::set($file, 'default_model', $model);
                $info("agent runtime {$current} -> {$runtimeOption}: set default_model: {$model}");
            }
            self::set($file, 'agent_runtime', $runtimeOption);
        }
        if (null !== $backendOption) {
            self::set($file, 'agent_backend', $backendOption);
            $info('agent backend: '.$this->ades->resolve($backendOption)->label());
        }

        // --runtime, else PABLO_AGENT_RUNTIME, else agent_runtime, else the default.
        $runtime = AgentRuntimeRegistry::checkName($runtimeOption ?? self::env('PABLO_AGENT_RUNTIME') ?? self::value($file, 'agent_runtime') ?? AgentRuntimeRegistry::DEFAULT);
        // An ADE (from the config, or kept from an earlier install) that
        // cannot drive this runtime: refuse now rather than fail at the first
        // launch.
        $backend = self::env('PABLO_AGENT_BACKEND') ?? self::value($file, 'agent_backend');
        if (null !== $backend && \in_array($backend, AdeRegistry::NAMES, true)
            && null !== ($error = $this->ades->resolve($backend)->runtimeError($this->runtimes->resolve($runtime)))) {
            throw new PabloError("{$error} (agent_backend is set in {$file}; or pass --backend ".AdeRegistry::DEFAULT.')');
        }
        $info("agent runtime: {$runtime}");

        return $runtime;
    }

    /** Former top-level keys of config.yaml => their current name. */
    public const RENAMED_KEYS = ['default_model_by_type' => 'models'];

    /**
     * Rename the former keys (RENAMED_KEYS) of an existing config, in place:
     * the loaders refuse them. A config setting both a former key and its
     * new name is left alone, with a warning. Runs before anything reads the
     * config, so an update can fix what would otherwise stop the install.
     *
     * @param \Closure(string): void $info
     *
     * @throws PabloError when the file cannot be written
     */
    public function migrate(\Closure $info): void
    {
        $file = $this->global->configPath();
        $content = Files::tryRead($file);
        if (null === $content) {
            return;
        }
        $migrated = $content;
        foreach (self::RENAMED_KEYS as $old => $new) {
            $oldKey = '/^'.preg_quote($old, '/').':/m';
            if (1 !== preg_match($oldKey, $migrated)) {
                continue;
            }
            if (1 === preg_match('/^'.preg_quote($new, '/').':/m', $migrated)) {
                $info("⚠ {$file} sets both {$old} and {$new}: {$old} was renamed to {$new}, merge it by hand");
                continue;
            }
            $migrated = (string) preg_replace($oldKey, $new.':', $migrated, 1);
            $info("renamed {$old} to {$new} in {$file}");
        }
        // Written in place, like set(): a symlinked config and its
        // permissions are kept.
        if ($migrated !== $content && false === @file_put_contents($file, $migrated)) {
            throw new PabloError("cannot write {$file}");
        }
    }

    /** The bundled example config (inside the binary, or in the checkout). */
    public static function example(): string
    {
        return PabloPaths::resourcesDir().'/examples/config.yaml';
    }

    /**
     * The value of a top-level key, quotes stripped, or null.
     */
    public static function value(string $file, string $key): ?string
    {
        $content = Files::tryRead($file);
        if (null === $content) {
            return null;
        }
        if (1 !== preg_match('/^'.preg_quote($key, '/').':[ \t]*["\']?([^"\'#\s]*)/m', $content, $m) || '' === $m[1]) {
            return null;
        }

        return $m[1];
    }

    /**
     * Set `<key>: <value>`: replace the first top-level line of that key, or
     * append one. Written in place, so a symlinked config and its
     * permissions are kept.
     */
    public static function set(string $file, string $key, string $value): void
    {
        // A missing file is fine: the key is appended to an empty one.
        $content = Files::tryRead($file) ?? '';
        $pattern = '/^'.preg_quote($key, '/').':.*$/m';
        if (1 === preg_match($pattern, $content)) {
            $content = (string) preg_replace($pattern, $key.': '.$value, $content, 1);
        } else {
            if ('' !== $content && !str_ends_with($content, "\n")) {
                $content .= "\n";
            }
            $content .= $key.': '.$value."\n";
        }
        if (false === @file_put_contents($file, $content)) {
            throw new PabloError("cannot write {$file}");
        }
    }

    /** @param \Closure(string): void $info */
    private function seed(string $file, \Closure $info): void
    {
        $example = Files::read(self::example());
        Files::ensureDir(\dirname($file));
        if (false === @file_put_contents($file, $example)) {
            throw new PabloError("cannot write {$file}");
        }
        $info("wrote default {$file} (edit it to set your default agent model, PR locale, sync strategy, ...)");

        $has = $this->hasCommand;
        // Pick the agent runtime the user actually has: the default one,
        // else the first one found in detection order.
        $runtime = $this->runtimes->resolve(AgentRuntimeRegistry::DEFAULT);
        if (!$has($runtime->binary())) {
            $missing = [$runtime->label()];
            foreach ($this->runtimes->all() as $candidate) {
                if ($candidate->name() === $runtime->name()) {
                    continue;
                }
                if (!$has($candidate->binary())) {
                    $missing[] = $candidate->label();
                    continue;
                }
                $runtime = $candidate;
                self::set($file, 'default_model', $runtime->defaultModel());
                self::set($file, 'agent_runtime', $runtime->name());
                $info(\sprintf('%s found (and no %s): set agent_runtime: %s, default_model: %s', $runtime->label(), implode(' or ', $missing), $runtime->name(), $runtime->defaultModel()));
                break;
            }
        }
        // Same for the ADE: the default one, else the first one found that
        // can run the runtime. Headless needs no CLI, so it is never picked
        // here: the default stays, and is used as soon as it is installed.
        $default = $this->ades->resolve(AdeRegistry::DEFAULT);
        $defaultProbe = $default->probe();
        if (null === $defaultProbe || $has($defaultProbe->argv[0])) {
            return;
        }
        $missing = [$default->label()];
        foreach ($this->ades->all() as $ade) {
            $probe = $ade->probe();
            if ($ade->name() === $default->name() || null === $probe) {
                continue;
            }
            if (!$has($probe->argv[0]) || !$has($runtime->binary()) || null !== $ade->runtimeError($runtime)) {
                $missing[] = $ade->label();
                continue;
            }
            self::set($file, 'agent_backend', $ade->name());
            $info(\sprintf('%s found (and no %s): set agent_backend: %s', $ade->label(), implode(' or ', $missing), $ade->name()));

            return;
        }
        $info(\sprintf('⚠ neither %s found: agents will run headless until you install one', implode(' nor ', $missing)));
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return false === $value || '' === $value ? null : $value;
    }
}
