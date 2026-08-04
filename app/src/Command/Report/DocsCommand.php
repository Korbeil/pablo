<?php

declare(strict_types=1);

namespace Pablo\Command\Report;

use Pablo\Command\Command;
use Pablo\Provider\Confluence\Confluence;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class DocsCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('docs')
            ->setDescription('fetch a Confluence documentation page (via acli)')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'scope to a configured project (optional)')
            ->addArgument('page', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'page id or Confluence URL');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $target = trim(implode(' ', (array) $input->getArgument('page')));
        if ('' === $target) {
            throw new PabloError('usage: pablo docs <page-id | confluence-url> [--project <name>]');
        }
        $projects = $this->projects();
        $project = $input->getOption('project') ?: null;
        $cfg = null;
        if (null !== $project) {
            $cfg = $projects[$project] ?? null;
            if (null === $cfg) {
                throw new PabloError("unknown project '{$project}'; configured projects: ".implode(', ', array_keys($projects)));
            }
        } elseif ([] !== $projects) {
            $cfg = reset($projects);
        }
        $page = Confluence::fetch($target, $cfg);
        $output->writeln("# {$page->title}");
        $output->writeln($page->url);
        $output->writeln('');
        $output->writeln($page->body);

        return self::SUCCESS;
    }
}
