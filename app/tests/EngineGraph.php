<?php

declare(strict_types=1);

namespace Pablo\Tests;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\Runtime\OpenCode\OpenCodeUsage;
use Pablo\Backup\Backup;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Dispatch\Dispatch;
use Pablo\Dispatch\Stamps;
use Pablo\Doctor\Doctor;
use Pablo\Domain\Time;
use Pablo\Listing\Listing;
use Pablo\Poller\Poller;
use Pablo\Provider\Git\Sync;
use Pablo\StateMachine\StateMachine;
use Pablo\Store\Store;
use Pablo\Support\RepoSlug;

/**
 * The engine service graph wired over fakes (FakeAgents, FakeGit, FakeGhPr,
 * FakeProcessRunner, StubProviders, FakeAnalytics), its store in a temp dir:
 * the one place tests build it. CommandTestBed holds one; tests that need
 * the services without the command bed's project and task build their own.
 */
final class EngineGraph
{
    public Store $store;
    public FakeAgents $agents;
    public FakeGit $git;
    public FakeGhPr $gh;
    public FakeProcessRunner $runner;
    public Time $time;
    public GlobalConfig $global;
    public Config $config;
    public AgentLauncherFactory $agentLaunchers;
    public StubProviders $providers;
    public RepoSlug $repoSlug;
    public StateMachine $stateMachine;
    public FakeAnalytics $analytics;
    public Stamps $stamps;
    public Doctor $doctor;
    public Sync $sync;
    public Poller $poller;
    public Listing $listing;
    public Dispatch $dispatch;
    public Backup $backup;

    public function __construct(?string $stateDir = null)
    {
        $this->store = new Store($stateDir ?? TempDir::create('graph'));
        $this->agents = new FakeAgents();
        $this->runner = new FakeProcessRunner();
        $this->time = new Time();
        $this->git = new FakeGit();
        $this->gh = new FakeGhPr();
        $this->global = new GlobalConfig();
        $this->config = new Config($this->global);
        // Launchers built by the factory share the fake runner: a real one
        // would call the real ADE CLI and, when it refuses, fall back to a
        // real headless `opencode run`.
        $this->agentLaunchers = new AgentLauncherFactory($this->global, $this->runner);
        $this->providers = new StubProviders();
        $this->repoSlug = new RepoSlug($this->git);
        $this->analytics = new FakeAnalytics();
        $this->stateMachine = new StateMachine($this->gh, $this->providers, $this->repoSlug, $this->time, $this->analytics);
        $this->stamps = new Stamps();
        $this->doctor = new Doctor($this->runner, $this->agentLaunchers);
        $this->sync = new Sync($this->git, $this->gh, $this->repoSlug, $this->time);
        $this->poller = new Poller(
            $this->gh,
            $this->git,
            $this->providers,
            $this->repoSlug,
            $this->stateMachine,
            $this->time,
            new OpenCodeUsage($this->runner),
            $this->analytics,
        );
        $this->listing = new Listing($this->stamps, $this->gh, $this->providers, $this->repoSlug, $this->time);
        $this->dispatch = new Dispatch($this->doctor, $this->sync, $this->poller, $this->agentLaunchers, $this->stamps, $this->git);
        $this->backup = new Backup($this->git, $this->runner, $this->time);
    }
}
