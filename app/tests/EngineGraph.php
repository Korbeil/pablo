<?php

declare(strict_types=1);

namespace Pablo\Tests;

use Pablo\Agents\Ade\AdeRegistry;
use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\AgentRuntimeRegistry;
use Pablo\Agents\Runtime\OpenCode\OpenCodeUsage;
use Pablo\Backup\Backup;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Dispatch\Dispatch;
use Pablo\Dispatch\Stamps;
use Pablo\Dispatch\WorkerPool;
use Pablo\Doctor\Doctor;
use Pablo\Domain\Time;
use Pablo\Hooks\Hooks;
use Pablo\Listing\Listing;
use Pablo\Poller\Poller;
use Pablo\Provider\Git\Sync;
use Pablo\StateMachine\StateMachine;
use Pablo\Store\Store;
use Pablo\Support\RepoSlug;
use Pablo\Yolo\YoloPipeline;

/**
 * The engine service graph wired over fakes (FakeAgents, FakeGit, FakeGhPr,
 * FakeProcessRunner, StubProviders, FakeAnalytics, FakeHookRunner), its store in a temp dir:
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
    public AgentTemplates $templates;
    public AgentRuntimeRegistry $runtimes;
    public AdeRegistry $ades;
    public AgentLauncherFactory $agentLaunchers;
    public FakeHookRunner $hookRunner;
    public Hooks $hooks;
    public StubProviders $providers;
    public RepoSlug $repoSlug;
    public StateMachine $stateMachine;
    public YoloPipeline $yolo;
    public FakeAnalytics $analytics;
    public Stamps $stamps;
    public Doctor $doctor;
    public Sync $sync;
    public Poller $poller;
    public Listing $listing;
    public Dispatch $dispatch;
    public FakeJobWorkers $jobWorkers;
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
        $this->templates = new AgentTemplates($this->config);
        $this->runtimes = new AgentRuntimeRegistry($this->global, $this->templates);
        $this->ades = new AdeRegistry($this->global);
        $this->agentLaunchers = new AgentLauncherFactory($this->runner, $this->runtimes, $this->ades, $this->time);
        $this->providers = new StubProviders();
        $this->repoSlug = new RepoSlug($this->git);
        $this->analytics = new FakeAnalytics();
        $this->hookRunner = new FakeHookRunner();
        $this->hooks = new Hooks($this->hookRunner, $this->repoSlug);
        $this->stateMachine = new StateMachine($this->gh, $this->providers, $this->repoSlug, $this->time, $this->hooks, $this->analytics);
        $this->yolo = new YoloPipeline($this->stateMachine, $this->time);
        $this->stamps = new Stamps();
        $this->doctor = new Doctor($this->runner, $this->runtimes, $this->ades);
        $this->sync = new Sync($this->git, $this->gh, $this->repoSlug, $this->time, $this->runtimes);
        $this->poller = new Poller(
            $this->gh,
            $this->git,
            $this->providers,
            $this->repoSlug,
            $this->stateMachine,
            $this->time,
            new OpenCodeUsage($this->runner),
            $this->hooks,
            $this->analytics,
            $this->yolo,
        );
        $this->listing = new Listing($this->stamps, $this->gh, $this->providers, $this->repoSlug, $this->time);
        $this->jobWorkers = new FakeJobWorkers();
        $this->dispatch = new Dispatch($this->doctor, $this->sync, $this->poller, $this->agentLaunchers, $this->stamps, $this->git, new WorkerPool($this->jobWorkers, sleep: static function (): void {}), $this->global);
        $this->backup = new Backup($this->git, $this->runner, $this->time, $this->ades);
    }
}
