<?php

declare(strict_types=1);

namespace Pablo\Tests;

use Pablo\Agents\Ade\AdeRegistry;
use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Agents\Runtime\AgentRuntimeRegistry;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Hooks\Hooks;
use Pablo\Provider\Git\GitRepoInterface;
use Pablo\Support\ProcessRunnerInterface;
use Pablo\Support\RepoSlug;

/**
 * The ADE/runtime registries, the factory over them and Hooks, wired the
 * way the container wires them, for tests that build a service by hand
 * outside EngineGraph. Hooks always get a FakeHookRunner: nothing is spawned.
 */
final class Registries
{
    public static function templates(GlobalConfig $global = new GlobalConfig()): AgentTemplates
    {
        return new AgentTemplates(new Config($global));
    }

    public static function runtimes(GlobalConfig $global = new GlobalConfig()): AgentRuntimeRegistry
    {
        return new AgentRuntimeRegistry($global, self::templates($global));
    }

    /** The default runtime (OpenCode), whatever the config selects. */
    public static function defaultRuntime(GlobalConfig $global = new GlobalConfig()): AgentRuntimeInterface
    {
        return self::runtimes($global)->resolve(AgentRuntimeRegistry::DEFAULT);
    }

    public static function ades(GlobalConfig $global = new GlobalConfig()): AdeRegistry
    {
        return new AdeRegistry($global);
    }

    public static function launchers(ProcessRunnerInterface $runner, GlobalConfig $global = new GlobalConfig()): AgentLauncherFactory
    {
        return new AgentLauncherFactory($runner, self::runtimes($global), self::ades($global));
    }

    public static function hooks(GitRepoInterface $git = new FakeGit(), FakeHookRunner $runner = new FakeHookRunner()): Hooks
    {
        return new Hooks($runner, new RepoSlug($git));
    }
}
