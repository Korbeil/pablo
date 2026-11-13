<?php

declare(strict_types=1);

namespace Pablo\Command\Internal;

use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Dispatch\Dispatch;
use Pablo\Dispatch\JobResult;
use Pablo\Store\Store;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One dispatcher job of one project, run in a child process by the
 * dispatcher's WorkerPool (system:dispatch, dispatch.poll_concurrency).
 *
 * Runs the job exactly as the dispatcher would in-process and prints its
 * output, then a JobResult line, last: the parent reads it, records health
 * and stamps itself (it is the only writer of both) and prints the output
 * whole. It takes no lock: the parent holds the dispatch lock (inherited)
 * for as long as this runs, and the Store's per-task locks still apply.
 */
#[AsCommand(name: 'internal:dispatch-job', hidden: true)]
final class InternalDispatchJobCommand extends Command
{
    public function __construct(
        private readonly Dispatch $dispatch,
        private readonly Store $store,
        private readonly Config $projectsLoader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('project', null, InputOption::VALUE_REQUIRED)
            ->addOption('job', null, InputOption::VALUE_REQUIRED)
            ->setHidden(true);
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getOption('project');
        $job = (string) $input->getOption('job');
        try {
            $runner = $this->dispatch->defaultRunners()[$job] ?? null;
            if (null === $runner) {
                throw new \InvalidArgumentException("unknown dispatcher job '{$job}'");
            }
            $cfg = $this->projectsLoader->loadProjects()[$name] ?? null;
            if (null === $cfg) {
                throw new \InvalidArgumentException("project '{$name}' is not configured, or its file no longer loads");
            }
            $result = $runner($cfg, $this->store);
        } catch (\Throwable $e) {
            $this->errorOutput($output)->writeln((string) $e, OutputInterface::OUTPUT_RAW);
            echo (new JobResult($e->getMessage()))->line();

            return self::FAILURE;
        }
        echo (new JobResult(null, \is_int($result) ? $result : 0))->line();

        return self::SUCCESS;
    }
}
