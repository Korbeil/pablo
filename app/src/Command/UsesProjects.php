<?php

declare(strict_types=1);

namespace Pablo\Command;

use Pablo\Completion\Suggestions;
use Pablo\Config\Config;
use Pablo\Config\ProjectConfig;
use Pablo\Store\Store;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The configured projects and their shell completion, for the commands
 * working across projects. The using command promotes the two services the
 * trait declares, with the same signature:
 *
 *     public function __construct(
 *         protected readonly Store $store,
 *         protected readonly Config $projectsLoader,
 *     )
 *
 * @phpstan-require-extends Command
 */
trait UsesProjects
{
    protected readonly Store $store;
    protected readonly Config $projectsLoader;

    /** @return array<string, ProjectConfig> */
    protected function projects(): array
    {
        return $this->projectsLoader->loadProjects();
    }

    /**
     * The projects a command run covers: the one named by its optional
     * `project` argument (see addProjectArgument(); an unknown name is an
     * error), else all of them, after a warning per project file that failed
     * to load.
     *
     * @return array<string, ProjectConfig>
     */
    protected function selectedProjects(InputInterface $input, OutputInterface $output): array
    {
        $projects = $this->projects();
        $project = $input->getArgument('project') ?: null;
        if (null === $project) {
            $this->warnProjectErrors($output);

            return $projects;
        }
        if (!isset($projects[$project])) {
            throw $this->projectsLoader->unknownProject((string) $project);
        }

        return [$project => $projects[$project]];
    }

    /** The optional `project` argument read by selectedProjects(), with its completion. */
    protected function addProjectArgument(string $description = 'limit to one project'): static
    {
        return $this->addArgument('project', InputArgument::OPTIONAL, $description, null, $this->suggestions()->projectCompleter());
    }

    /**
     * One stderr warning per project file that failed to load: those projects
     * are silently missing from projects() otherwise.
     */
    protected function warnProjectErrors(OutputInterface $output): void
    {
        $err = $this->errorOutput($output);
        foreach ($this->projectsLoader->projectErrors() as $file => $error) {
            $err->writeln("pablo: warning: skipping invalid project config {$file}: {$error}");
        }
    }

    /** Shell-completion values, from local files only (see Suggestions). */
    protected function suggestions(): Suggestions
    {
        return new Suggestions($this->store, $this->projectsLoader);
    }
}
