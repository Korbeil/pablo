<?php

declare(strict_types=1);

use Pablo\Agents\Ade\AdeRegistry;
use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Agents\AgentTemplates;
use Pablo\Agents\LaunchContext;
use Pablo\Agents\Runtime\AgentRuntimeRegistry;
use Pablo\Analytics\AgentUsage;
use Pablo\Analytics\AnalyticsAggregator;
use Pablo\Analytics\AnalyticsInterface;
use Pablo\Analytics\AnalyticsReader;
use Pablo\Analytics\JsonlAnalytics;
use Pablo\Analytics\UsageHarvesterInterface;
use Pablo\App\ConsoleApplication;
use Pablo\Backup\Backup;
use Pablo\Command\Command as PabloCommand;
use Pablo\Config\Config;
use Pablo\Config\ConfigValidator;
use Pablo\Config\GlobalConfig;
use Pablo\Dispatch\Dispatch;
use Pablo\Dispatch\DispatchHealth;
use Pablo\Dispatch\JobWorkerSpawnerInterface;
use Pablo\Dispatch\ProcessJobWorkerSpawner;
use Pablo\Dispatch\Stamps;
use Pablo\Dispatch\WorkerPool;
use Pablo\Doctor\AgentStaleness;
use Pablo\Doctor\Doctor;
use Pablo\Doctor\SchedulerHealth;
use Pablo\Domain\Time;
use Pablo\Hooks\DetachedHookRunner;
use Pablo\Hooks\HookRunnerInterface;
use Pablo\Hooks\Hooks;
use Pablo\Housekeeping\Pruner;
use Pablo\Install\Scheduler\SchedulerFactory;
use Pablo\Listing\Listing;
use Pablo\Poller\Poller;
use Pablo\Provider\Gh\GhPr;
use Pablo\Provider\Gh\GhPrInterface;
use Pablo\Provider\Git\GitRepo;
use Pablo\Provider\Git\GitRepoInterface;
use Pablo\Provider\Git\Sync;
use Pablo\Provider\Tracker\Github;
use Pablo\Provider\Tracker\Jira;
use Pablo\Provider\Tracker\Linear;
use Pablo\Provider\Tracker\ProviderRegistry;
use Pablo\Provider\Tracker\ProviderRegistryInterface;
use Pablo\StateMachine\StateMachine;
use Pablo\Store\Store;
use Pablo\Support\Naming;
use Pablo\Support\ProcessRunner;
use Pablo\Support\ProcessRunnerInterface;
use Pablo\Support\RepoSlug;
use Pablo\Support\TaskSummarizer;
use Pablo\Task\TaskAttacher;
use Pablo\Task\TaskCloser;
use Pablo\Task\TaskStarter;
use Pablo\Task\TaskStateChanger;
use Pablo\Update\Updater;
use Pablo\Web\TrustedHostsSubscriber;
use Pablo\Yolo\YoloPipeline;
use Pablo\Yolo\YoloPrompts;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\abstract_arg;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // ~/.pablo paths and the agent backend are deliberately NOT container
    // parameters: the compiled container is cached under app/var/cache/<env>,
    // so a compile-time value would freeze PABLO_* / HOME as they were on the
    // process that first warmed the cache. Every env-dependent service
    // resolves its own defaults at call time instead.

    // Leaf collaborators (interfaces exist so tests can inject fakes).
    $services->set(ProcessRunner::class);
    $services->alias(ProcessRunnerInterface::class, ProcessRunner::class);

    $services->set(GitRepo::class);
    $services->alias(GitRepoInterface::class, GitRepo::class);

    $services->set(GhPr::class);
    $services->alias(GhPrInterface::class, GhPr::class);

    $services->set(Time::class);
    $services->set(Naming::class);
    $services->set(TaskSummarizer::class);
    $services->set(RepoSlug::class);
    $services->set(GlobalConfig::class);
    $services->set(Config::class);
    $services->set(ConfigValidator::class);
    $services->set(Store::class);

    // Analytics: append-only JSONL under ~/.pablo/analytics (root resolved at
    // call time — never a container parameter, same rule as Store).
    $services->set(JsonlAnalytics::class);
    $services->set(AnalyticsReader::class);
    $services->set(AnalyticsAggregator::class);
    // Run usage comes from the selected runtime's own storage (resolved per call).
    $services->set(AgentUsage::class);
    $services->alias(UsageHarvesterInterface::class, AgentUsage::class);
    $services->alias(AnalyticsInterface::class, JsonlAnalytics::class);

    // Tracker providers and their registry.
    $services->set(Github::class);
    $services->set(Jira::class);
    $services->set(Linear::class);
    $services->set(ProviderRegistry::class);
    $services->alias(ProviderRegistryInterface::class, ProviderRegistry::class);

    // Generated-agent templates and the doctor's staleness check.
    $services->set(AgentTemplates::class);
    $services->set(AgentStaleness::class);

    // Bounded context pre-fetched into auto-launched agent prompts.
    $services->set(LaunchContext::class);

    // ADEs and runtimes are selected per call (env > global config > default):
    // code needing one injects its registry, code launching agents the
    // factory; a default-behaviour launcher is exposed as the interface for
    // consumers that never need an explicit backend.
    $services->set(AgentRuntimeRegistry::class);
    $services->set(AdeRegistry::class);
    $services->set(AgentLauncherFactory::class);
    // Lazy: create() refuses an incompatible backend/runtime pair (OpenChamber
    // + anything but OpenCode), and services that only sometimes launch hold
    // it: Poller and Sync under `system:dispatch`, whose preflight must report
    // the pair rather than die building its graph, Listing under `task:list`,
    // the dashboard's Twig components. (bin/pablo builds only the command it
    // runs, so `system:doctor`, which does not hold one, no longer builds it.)
    $services->set(AgentLauncherInterface::class)
        ->factory([service(AgentLauncherFactory::class), 'create'])
        ->lazy()
        ->public();

    // The user's own hook command on transitions / finished agents (detached).
    $services->set(DetachedHookRunner::class);
    $services->alias(HookRunnerInterface::class, DetachedHookRunner::class);
    $services->set(Hooks::class);

    // Engine orchestration.
    $services->set(StateMachine::class);
    // YOLO mode: the follow-up runs after a state agent's plan.
    $services->set(YoloPrompts::class);
    $services->set(YoloPipeline::class);
    $services->set(Poller::class);
    $services->set(Sync::class);
    $services->set(Doctor::class);
    $services->set(SchedulerHealth::class);
    $services->set(Backup::class);
    $services->set(Stamps::class);
    $services->set(DispatchHealth::class);
    // Polls of several projects at once, each in a child process of this PABLO.
    $services->set(ProcessJobWorkerSpawner::class);
    $services->alias(JobWorkerSpawnerInterface::class, ProcessJobWorkerSpawner::class);
    $services->set(WorkerPool::class);
    $services->set(Dispatch::class);
    $services->set(Pruner::class);
    $services->set(Listing::class);
    $services->set(TaskStarter::class);
    $services->set(TaskAttacher::class);
    $services->set(TaskStateChanger::class);
    $services->set(TaskCloser::class);

    // `pablo system:install` / `system:uninstall`: the platform's background
    // services (systemd or launchd), paths resolved per call.
    $services->set(SchedulerFactory::class);

    // `pablo update`: replaces the running binary with the latest release.
    $services->set(Updater::class);

    // `pablo.command`, not `console.command`: every bundle now contributes to
    // the latter, and bin/pablo must keep listing PABLO subcommands only.
    // Autoconfigure still adds `console.command` on top, so bin/console (the
    // framework maintenance CLI) sees them too.
    $services->instanceof(PabloCommand::class)
        ->tag('pablo.command');

    $services->load('Pablo\\Command\\', '../src/Command');

    // Dashboard (web) services. Autoconfigure tags controllers with
    // controller.service_arguments and Twig components via their attributes.
    $services->load('Pablo\\Controller\\', '../src/Controller');
    $services->load('Pablo\\Dashboard\\', '../src/Dashboard');
    $services->load('Pablo\\Twig\\', '../src/Twig');
    $services->set(TrustedHostsSubscriber::class);

    // Its lazy command loader is built from the `pablo.command` tag by
    // PabloCommandLoaderPass (registered in Kernel::build()).
    $services->set(ConsoleApplication::class)
        ->public()
        ->args([abstract_arg('the pablo.command loader, set by PabloCommandLoaderPass')]);
};
