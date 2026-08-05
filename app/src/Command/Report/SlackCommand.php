<?php

declare(strict_types=1);

namespace Pablo\Command\Report;

use Pablo\Command\Command;
use Pablo\Listing\Listing;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class SlackCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('show:prs')
            ->setDescription('paste-ready Slack list of PRs to review and/or QA (waiting-review / needs-testing)')
            ->addArgument('state', InputArgument::OPTIONAL, 'one queue only; omit to print both', null, ['waiting-review', 'needs-testing']);
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $state = $input->getArgument('state') ?: null;
        $states = null !== $state ? [$state] : ['waiting-review', 'needs-testing'];
        $projects = $this->projects();
        $store = $this->store();
        $blocks = [];
        foreach ($states as $s) {
            $blocks[] = Listing::renderSlack(Listing::queueTasks($projects, $store, $s), $s);
        }
        if (1 === \count($blocks)) {
            $output->writeln($blocks[0]);
        } else {
            $output->writeln(implode("\n\n―――― review above · QA below ――――\n\n", $blocks));
        }

        return self::SUCCESS;
    }
}
