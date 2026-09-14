<?php

declare(strict_types=1);

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Agents\AgentTemplates;
use Pablo\Analytics\AnalyticsAggregator;
use Pablo\Analytics\AnalyticsInterface;
use Pablo\Analytics\AnalyticsReader;
use Pablo\Analytics\JsonlAnalytics;
use Pablo\Analytics\OpenCodeUsage;
use Pablo\App\ConsoleApplication;
use Pablo\Backup\Backup;
use Pablo\Command\Command as PabloCommand;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Dispatch\Dispatch;
use Pablo\Dispatch\Stamps;
use Pablo\Doctor\AgentStaleness;
use Pablo\Doctor\Doctor;
use Pablo\Domain\Time;
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
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

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
    $services->set(Store::class);

    // Analytics: append-only JSONL under ~/.pablo/analytics (root resolved at
    // call time — never a container parameter, same rule as Store).
    $services->set(JsonlAnalytics::class);
    $services->set(AnalyticsReader::class);
    $services->set(AnalyticsAggregator::class);
    $services->set(OpenCodeUsage::class);
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

    // Agent backends are selected per call (env > global config > orca), so
    // commands get the factory; a default-behaviour launcher is exposed as
    // the interface for consumers that never need an explicit backend.
    $services->set(AgentLauncherFactory::class);
    $services->set(AgentLauncherInterface::class)
        ->factory([service(AgentLauncherFactory::class), 'create'])
        ->public();

    // Engine orchestration.
    $services->set(StateMachine::class);
    $services->set(Poller::class);
    $services->set(Sync::class);
    $services->set(Doctor::class);
    $services->set(Backup::class);
    $services->set(Stamps::class);
    $services->set(Dispatch::class);
    $services->set(Listing::class);

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

    $services->set(ConsoleApplication::class)
        ->public()
        ->args([tagged_iterator('pablo.command')]);
};
