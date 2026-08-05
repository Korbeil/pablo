<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Backup\Backup;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Config\ProjectConfig;
use Pablo\Provider\Git\GitRepo;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

final class RestoreCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('archive:restore')
            ->setDescription('restore a PABLO state backup and recreate task worktrees')
            ->addArgument('archive', InputArgument::REQUIRED, 'path to a pablo-backup-*.tar.gz archive')
            ->addOption('skip-worktrees', null, InputOption::VALUE_NONE, 'restore state and configs only, do not recreate worktrees')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'answer yes to all overwrite prompts');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $archive = (string) $input->getArgument('archive');
        $pabloRoot = Backup::pabloRoot();
        $projectsDir = Config::projectsDir();

        $extracted = sys_get_temp_dir().'/pablo-restore-'.uniqid();
        try {
            $manifest = Backup::extractArchive($archive, $extracted);
            $this->assertVersion($manifest, $output);

            $output->writeln(\sprintf('Backup created %s for %d project(s):', $manifest['created_at'] ?? '?', \count($manifest['projects'] ?? [])));
            foreach ($manifest['projects'] ?? [] as $project) {
                $output->writeln(\sprintf('  - %s (origin %s, %d task branch(es))', $project['name'], $project['origin_url'] ?? 'n/a', \count($project['branches'] ?? [])));
            }

            $overwrite = (bool) $input->getOption('yes') || $this->confirm($input, $output, 'Restore project configs from the backup?', true);
            foreach (Backup::restoreProjects($extracted, $projectsDir, $overwrite) as $written) {
                $output->writeln('  config '.$written);
            }

            $overwriteState = (bool) $input->getOption('yes') || $this->confirm($input, $output, 'Restore state, stamps, logs and cache into '.$pabloRoot.'?', true);
            foreach (Backup::restoreStoreTree($extracted, $pabloRoot, $overwriteState) as $restored) {
                $output->writeln('  restored '.$restored);
            }

            if (!(bool) $input->getOption('skip-worktrees')) {
                $this->recreateWorktrees($input, $output, $manifest, $pabloRoot);
            } else {
                $output->writeln('worktrees skipped (--skip-worktrees) — task records keep their original paths');
            }

            $output->writeln('restore complete. Run `pablo show:tasks` to verify.');

            return self::SUCCESS;
        } finally {
            Backup::cleanupDir($extracted);
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function assertVersion(array $manifest, OutputInterface $output): void
    {
        $version = (int) ($manifest['version'] ?? 0);
        if ($version > Backup::MANIFEST_VERSION) {
            $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $err->writeln(\sprintf('backup manifest version %d is newer than this PABLO supports (%d) — upgrade PABLO first', $version, Backup::MANIFEST_VERSION));

            throw new PabloError('incompatible backup version');
        }
        if ($version < 1) {
            throw new PabloError('invalid backup manifest (missing version)');
        }
    }

    private function confirm(InputInterface $input, OutputInterface $output, string $question, bool $default = true): bool
    {
        $helper = new QuestionHelper();
        $output->writeln('');

        return (bool) $helper->ask($input, $output, new ConfirmationQuestion(\sprintf('[y/N] %s ', $question), $default));
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function recreateWorktrees(InputInterface $input, OutputInterface $output, array $manifest, string $pabloRoot): void
    {
        $helper = new QuestionHelper();
        foreach ($manifest['projects'] ?? [] as $project) {
            $name = (string) $project['name'];
            $origin = $project['origin_url'] ?? null;
            $branches = array_values((array) ($project['branches'] ?? []));
            if ([] === $branches) {
                $output->writeln(\sprintf('%s: no task branches to recreate', $name));
                continue;
            }

            $output->writeln(\sprintf("\n=== %s ===", $name));
            $repoPath = $this->askRepoPath($helper, $input, $output, $name, $origin);

            $isRepo = is_dir($repoPath.'/.git') || is_dir($repoPath);
            $hasOrigin = $isRepo && null !== GitRepo::originUrl($repoPath);
            if (!is_dir($repoPath) || 0 === \count(glob($repoPath.'/*') ?: []) + \count(glob($repoPath.'/.[!.]*') ?: [])) {
                if (null === $origin || '' === $origin) {
                    throw new PabloError($name.': destination does not exist and the backup has no origin URL to clone from; provide an existing checkout path');
                }
                $output->writeln('  cloning '.$origin.' …');
                GitRepo::cloneRepo($origin, $repoPath);
                $hasOrigin = true;
            } elseif (!$hasOrigin) {
                if (null === $origin || '' === $origin) {
                    throw new PabloError($name.': checkout has no origin remote and the backup has no origin URL; add origin manually then re-run');
                }
                $output->writeln('  adding origin '.$origin.' …');
                GitRepo::addOrigin($repoPath, $origin);
                $hasOrigin = true;
            }
            GitRepo::fetchOrigin($repoPath);

            $cfg = $this->localProject($name);
            $wtRoot = null !== $cfg ? $cfg->worktreesRoot : rtrim($pabloRoot, '/').'/worktrees/'.basename($repoPath);

            $store = $this->store();
            foreach ($branches as $branch) {
                if (!GitRepo::remoteBranchExists($repoPath, $branch)) {
                    $output->writeln('  ⚠ '.$branch.': not found on origin (local-only / unpushed) — skipped; push it on the source machine and re-run to recreate');

                    continue;
                }
                $path = GitRepo::recreateWorktree($repoPath, $wtRoot, $branch);
                $task = $store->get($name, $branch);
                if (null !== $task) {
                    $task->worktreePath = $path;
                    $store->save($task);
                }
                $output->writeln('  ✔ '.$branch.' → '.$path);
            }
        }
    }

    private function askRepoPath(QuestionHelper $helper, InputInterface $input, OutputInterface $output, string $name, ?string $origin): string
    {
        $local = $this->localProject($name);
        $suggested = null !== $local ? $local->repoPath : '';
        $prompt = \sprintf('Where should the repository for \'%s\' be created?', $name);
        if (null !== $origin && '' !== $origin) {
            $prompt .= ' (origin '.$origin.')';
        }
        $question = new Question($prompt.('' !== $suggested ? ' ['.$suggested.'] ' : ' '), $suggested);

        return rtrim((string) $helper->ask($input, $output, $question), '/');
    }

    private function localProject(string $name): ?ProjectConfig
    {
        try {
            return $this->projects()[$name] ?? null;
        } catch (PabloError) {
            return null;
        }
    }
}
