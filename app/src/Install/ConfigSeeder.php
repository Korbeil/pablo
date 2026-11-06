<?php

declare(strict_types=1);

namespace Pablo\Install;

use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Support\PabloError;
use Pablo\Support\PabloPaths;

/**
 * The ~/.pablo/config.yaml part of `pablo system:install`.
 *
 * A first install seeds it from the bundled examples/config.yaml and picks
 * the tools it finds (OpenCode is the default runtime, Orca the default
 * backend); an existing config is never rewritten, except for the keys an
 * explicit --runtime / --backend sets. Keys are edited line by line, so the
 * user's comments and layout survive.
 */
final class ConfigSeeder
{
    public const RUNTIMES = ['opencode', 'claude-code', 'copilot'];
    public const BACKENDS = ['orca', 'openchamber', 'headless'];

    /** The model a runtime switch writes; OpenCode's comes from the bundled example. */
    private const MODEL_BY_RUNTIME = [
        'claude-code' => 'sonnet',
        'copilot' => 'claude-sonnet-5.5',
    ];

    /** @var \Closure(string): bool */
    private readonly \Closure $hasCommand;

    /**
     * @param (\Closure(string): bool)|null $hasCommand whether a CLI is on the PATH (tests)
     */
    public function __construct(
        private readonly GlobalConfig $global,
        private readonly Config $config,
        ?\Closure $hasCommand = null,
    ) {
        $this->hasCommand = $hasCommand ?? InstallPaths::hasCommand(...);
    }

    /**
     * Refuse bad --runtime / --backend values before anything is touched.
     *
     * @throws PabloError
     */
    public static function validate(?string $runtime, ?string $backend): void
    {
        if (null !== $runtime && !\in_array($runtime, self::RUNTIMES, true)) {
            throw new PabloError("unknown --runtime '{$runtime}' (expected opencode, claude-code or copilot)");
        }
        if (null !== $backend && !\in_array($backend, self::BACKENDS, true)) {
            throw new PabloError("unknown --backend '{$backend}' (expected orca, openchamber or headless)");
        }
        if ('openchamber' === $backend && null !== $runtime && 'opencode' !== $runtime) {
            throw new PabloError("OpenChamber only works with OpenCode: --backend openchamber cannot run --runtime {$runtime}");
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
            if (!@mkdir($projectsDir, 0o777, true) && !is_dir($projectsDir)) {
                throw new PabloError("cannot create {$projectsDir}");
            }
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
            $current = self::value($file, 'agent_runtime') ?? 'opencode';
            if ($runtimeOption !== $current) {
                // The model id format follows the runtime, so it changes with it.
                $model = self::MODEL_BY_RUNTIME[$runtimeOption] ?? (self::value(self::example(), 'default_model') ?? '');
                self::set($file, 'default_model', $model);
                $info("agent runtime {$current} -> {$runtimeOption}: set default_model: {$model}");
            }
            self::set($file, 'agent_runtime', $runtimeOption);
        }
        if (null !== $backendOption) {
            // There is no "headless" backend value: PABLO falls back to a
            // headless run by itself whenever Orca is missing or does not know
            // a repository.
            self::set($file, 'agent_backend', 'openchamber' === $backendOption ? 'openchamber' : 'orca');
            $info('agent backend: '.('headless' === $backendOption ? 'none (agents run headless in the background)' : $backendOption));
        }

        // --runtime, else PABLO_AGENT_RUNTIME, else agent_runtime, else opencode.
        $runtime = $runtimeOption ?? self::env('PABLO_AGENT_RUNTIME') ?? self::value($file, 'agent_runtime') ?? AgentRuntimeInterface::OPENCODE;
        if (!\in_array($runtime, self::RUNTIMES, true)) {
            throw new PabloError("unknown agent_runtime '{$runtime}' (expected opencode, claude-code or copilot)");
        }
        // An OpenChamber backend (from the config, or kept from an earlier
        // install) cannot drive another runtime: refuse now rather than fail
        // at the first launch.
        $backend = self::env('PABLO_AGENT_BACKEND') ?? self::value($file, 'agent_backend');
        if ('openchamber' === $backend && 'opencode' !== $runtime) {
            throw new PabloError("agent_backend: openchamber only works with OpenCode, not {$runtime}. Pass --backend orca (or edit {$file}).");
        }
        $info("agent runtime: {$runtime}");

        return $runtime;
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
        $content = @file_get_contents($file);
        if (false === $content) {
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
        $content = (string) @file_get_contents($file);
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
        $example = @file_get_contents(self::example());
        if (false === $example) {
            throw new PabloError('cannot read the bundled '.self::example());
        }
        $dir = \dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new PabloError("cannot create {$dir}");
        }
        if (false === @file_put_contents($file, $example)) {
            throw new PabloError("cannot write {$file}");
        }
        $info("wrote default {$file} (edit it to set your default agent model, PR locale, sync strategy, ...)");

        $has = $this->hasCommand;
        // Pick the agent runtime the user actually has: OpenCode is the
        // default, so only switch when it is missing.
        if (!$has('opencode') && $has('claude')) {
            self::set($file, 'default_model', 'sonnet');
            self::set($file, 'agent_runtime', 'claude-code');
            $info('Claude Code found (and no OpenCode): set agent_runtime: claude-code, default_model: sonnet');
        } elseif (!$has('opencode') && $has('copilot')) {
            self::set($file, 'default_model', 'claude-sonnet-5.5');
            self::set($file, 'agent_runtime', 'copilot');
            $info('Copilot CLI found (and no OpenCode or Claude Code): set agent_runtime: copilot, default_model: claude-sonnet-5.5');
        }
        // Same for the backend: Orca is the default — and OpenChamber only
        // runs OpenCode.
        if (!$has('orca') && $has('openchamber') && $has('opencode')) {
            self::set($file, 'agent_backend', 'openchamber');
            $info('OpenChamber found (and no Orca): set agent_backend: openchamber');
        } elseif (!$has('orca')) {
            $info('⚠ neither Orca nor OpenChamber found: agents will run headless until you install one');
        }
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return false === $value || '' === $value ? null : $value;
    }
}
