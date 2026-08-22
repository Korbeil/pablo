<?php

declare(strict_types=1);

namespace Pablo\Tests;

use Pablo\Agents\AgentLauncherFactory;
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
 * The full engine service graph wired over fakes — one object to pass around
 * in engine-layer tests (poller, listing, state machine, dispatch...).
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
    public Stamps $stamps;
    public Doctor $doctor;
    public Sync $sync;
    public Poller $poller;
    public Listing $listing;
    public Dispatch $dispatch;
    public Backup $backup;

    public function __construct()
    {
        $this->store = new Store(sys_get_temp_dir().'/pablo-graph-'.uniqid());
        $this->agents = new FakeAgents();
        $this->runner = new FakeProcessRunner();
        $this->time = new Time();
        $this->git = new FakeGit();
        $this->gh = new FakeGhPr();
        $this->global = new GlobalConfig();
        $this->config = new Config($this->global);
        $this->agentLaunchers = new AgentLauncherFactory($this->global);
        $this->providers = new StubProviders();
        $this->repoSlug = new RepoSlug($this->git);
        $this->stateMachine = new StateMachine($this->gh, $this->providers, $this->repoSlug, $this->time);
        $this->stamps = new Stamps();
        $this->doctor = new Doctor($this->runner, $this->agentLaunchers);
        $this->sync = new Sync($this->git, $this->gh, $this->repoSlug, $this->runner, $this->time);
        $this->poller = new Poller($this->gh, $this->git, $this->providers, $this->repoSlug, $this->stateMachine, $this->time);
        $this->listing = new Listing($this->stamps, $this->gh, $this->git, $this->providers, $this->repoSlug, $this->stateMachine, $this->time);
        $this->dispatch = new Dispatch($this->doctor, $this->sync, $this->poller, $this->agentLaunchers, $this->stamps);
        $this->backup = new Backup($this->git, $this->runner, $this->time);
    }
}
