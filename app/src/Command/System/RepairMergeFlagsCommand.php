<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Analytics\JsonlAnalytics;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Provider\Gh\GhPrInterface;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One-off history repair: task_closed events written before the poller fix
 * recorded merged=false even when the PR was in fact merged. Re-checks every
 * such event against GitHub and flips merged to true where the PR is MERGED,
 * rewriting the affected month files atomically. Append-only applies to the
 * engine's normal writes; this command exists precisely to correct bad
 * history, so it rewrites lines in place.
 */
#[AsCommand(name: 'system:repair-merge-flags', description: 'fix historical task_closed events whose merged flag is false although the PR was merged')]
final class RepairMergeFlagsCommand extends Command
{
    public function __construct(
        private readonly GhPrInterface $gh,
        private readonly \Pablo\Support\RepoSlug $repoSlug,
        Store $store,
        Config $projectsLoader,
        AgentLauncherFactory $agentLaunchers,
        AgentLauncherInterface $agents,
    ) {
        parent::__construct($store, $projectsLoader, $agentLaunchers, $agents);
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'report what would change without rewriting any file')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'restrict the repair to one project');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $only = (string) $input->getOption('project');
        $root = JsonlAnalytics::root();
        $files = glob($root.'/*/*.jsonl') ?: [];
        if ([] === $files) {
            $output->writeln("no analytics files under {$root}");

            return self::SUCCESS;
        }

        $projects = $this->projects();
        /** @var array<string, string> $slugs */
        $slugs = [];
        /** @var array<string, bool> $mergedCache keyed "slug#number" */
        $mergedCache = [];
        /** @var array<string, array<int, string>> $rewrites file => patched lines by index */
        $rewrites = [];
        /** @var array<string, array{repaired: int, unmerged: int, skipped: int}> $perProject */
        $perProject = [];

        foreach ($files as $file) {
            $project = basename(\dirname($file));
            if ('' !== $only && $project !== $only) {
                continue;
            }
            $lines = @file($file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
            if (false === $lines) {
                continue;
            }
            $patched = [];
            foreach ($lines as $i => $line) {
                $event = json_decode($line, true);
                if (!\is_array($event)
                    || 'task_closed' !== ($event['type'] ?? null)
                    || !empty($event['merged'])
                    || null === ($event['pr_number'] ?? null)) {
                    continue;
                }
                $perProject[$project] ??= ['repaired' => 0, 'unmerged' => 0, 'skipped' => 0];
                try {
                    $slug = $this->slugFor($projects, $project, $slugs);
                } catch (PabloError $e) {
                    $output->writeln("<comment>skip {$project}: {$e->getMessage()}</comment>");
                    ++$perProject[$project]['skipped'];

                    continue;
                }
                $prNumber = (int) $event['pr_number'];
                $key = $slug.'#'.$prNumber;
                try {
                    $isMerged = $mergedCache[$key] ??= $this->gh->isMerged($slug, $prNumber);
                } catch (\Throwable $e) {
                    $output->writeln("<comment>skip {$project}#{$prNumber}: {$e->getMessage()}</comment>");
                    ++$perProject[$project]['skipped'];

                    continue;
                }
                if (!$isMerged) {
                    ++$perProject[$project]['unmerged'];

                    continue;
                }
                $event['merged'] = true;
                $encoded = json_encode($event, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
                if (false === $encoded) {
                    ++$perProject[$project]['skipped'];

                    continue;
                }
                $patched[$i] = $encoded;
                ++$perProject[$project]['repaired'];
            }
            if ([] !== $patched) {
                $rewrites[$file] = $patched;
            }
        }

        $totalRepaired = 0;
        foreach ($perProject as $project => $stats) {
            $totalRepaired += $stats['repaired'];
            $output->writeln(\sprintf(
                '%s%s: %d repaired, %d genuinely unmerged/closed, %d skipped',
                $dryRun ? '[dry run] ' : '',
                $project,
                $stats['repaired'],
                $stats['unmerged'],
                $stats['skipped'],
            ));
        }
        if ([] === $perProject) {
            $output->writeln('nothing to repair: every task_closed event with a PR already carries merged=true');
        } else {
            foreach ($rewrites as $file => $patched) {
                $output->writeln("  {$file}: ".\count($patched).' line(s)');
            }
            if (!$dryRun) {
                foreach ($rewrites as $file => $patched) {
                    $this->rewrite($file, $patched);
                }
            }
        }
        $output->writeln(($dryRun ? '[dry run] would repair ' : 'repaired ').$totalRepaired.' event(s)');

        return self::SUCCESS;
    }

    /**
     * @param array<string, \Pablo\Config\ProjectConfig> $projects
     * @param array<string, string>                      $slugs
     */
    private function slugFor(array $projects, string $project, array &$slugs): string
    {
        if (isset($slugs[$project])) {
            return $slugs[$project];
        }
        $cfg = $projects[$project] ?? null;
        if (null === $cfg) {
            throw new PabloError('no config under projects/ — cannot resolve the GitHub repo');
        }
        $slugs[$project] = $this->repoSlug->for($cfg);

        return $slugs[$project];
    }

    /**
     * Atomically replace $file with its lines, substituting patched ones.
     *
     * @param array<int, string> $patched original line index => replacement line
     */
    private function rewrite(string $file, array $patched): void
    {
        $lines = file($file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if (false === $lines) {
            return;
        }
        foreach ($patched as $i => $line) {
            if (isset($lines[$i])) {
                $lines[$i] = $line;
            }
        }
        $tmp = $file.'.repair-'.uniqid();
        if (false === @file_put_contents($tmp, implode("\n", $lines)."\n")) {
            @unlink($tmp);

            return;
        }
        rename($tmp, $file);
    }
}
