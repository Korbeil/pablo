<?php

declare(strict_types=1);

namespace Pablo\Command\Sync;

use Pablo\Agents\AgentLauncherInterface;
use Pablo\Command\Command;
use Pablo\Command\FormatsOutput;
use Pablo\Command\UsesProjects;
use Pablo\Config\Config;
use Pablo\Dispatch\DispatchHealth;
use Pablo\Provider\Git\Sync;
use Pablo\Provider\Git\SyncReport;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sync:run', description: 'sync task worktrees with the primary branch')]
final class SyncCommand extends Command
{
    use FormatsOutput;
    use UsesProjects;

    public function __construct(
        private readonly Sync $sync,
        private readonly DispatchHealth $health,
        protected readonly Store $store,
        protected readonly Config $projectsLoader,
        private readonly AgentLauncherInterface $agents,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addProjectArgument()
            ->addOption('apply', null, InputOption::VALUE_NONE, 'actually sync (default dry-run unless sync.auto_apply is set)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'only report, even when sync.auto_apply is set')
            ->addFormatOption();
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('apply') && $input->getOption('dry-run')) {
            throw new PabloError('--apply and --dry-run are mutually exclusive');
        }
        $json = $this->wantsJson($input);
        $projects = $this->selectedProjects($input, $output);
        $store = $this->store;
        $agents = $this->agents;
        // null defers to each project's sync.auto_apply.
        $apply = match (true) {
            (bool) $input->getOption('apply') => true,
            (bool) $input->getOption('dry-run') => false,
            default => null,
        };
        $results = [];
        foreach ($projects as $name => $cfg) {
            $reports = $this->sync->syncProject($cfg, $store, $apply, $agents);
            if ($json) {
                $results[] = [
                    'project' => $name,
                    'strategy' => $cfg->sync->strategy,
                    'applied' => $apply ?? $cfg->sync->autoApply,
                    'reports' => array_map(static fn (SyncReport $r): array => $r->toArray(), $reports),
                ];
                continue;
            }
            $output->writeln("# {$name}");
            $output->writeln(Sync::renderReports($reports));
            // A manual run that works clears the dispatcher's failure streak and
            // back-off; an explicit --dry-run proves too little to do so.
            if (false !== $apply) {
                $this->health->recordSuccess($name, 'sync', microtime(true));
            }
        }
        if ($json) {
            $this->writeJson($output, ['projects' => $results]);
        }

        return self::SUCCESS;
    }
}
