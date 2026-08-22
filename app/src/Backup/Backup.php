<?php

declare(strict_types=1);

namespace Pablo\Backup;

use Pablo\App\ConsoleApplication;
use Pablo\Config\ProjectConfig;
use Pablo\Domain\Task;
use Pablo\Domain\Time;
use Pablo\Provider\Git\GitRepoInterface;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Pablo\Support\ProcessRunnerInterface;

/**
 * PABLO backup/restore.
 *
 * A backup is a single .tar.gz captured on the source machine: the state
 * store, poller stamps, rebase logs and cache (everything under ~/.pablo
 * except agents/ — opencode sessions are never saved) plus the embedded
 * project configs, a manifest of every task worktree, and a best-effort
 * snapshot of Orca-registered repos. Worktree contents are transient
 * checkouts, so only their metadata (origin URL + branch) is kept;
 * restore recreates them from git by checking the remote branch back
 * out, then rewrites each task record's worktree_path and re-registers
 * repos with Orca.
 */
final class Backup
{
    public const MANIFEST_VERSION = 1;

    public const DEFAULT_BACKUP_SUBDIR = '.pablo-backups';

    /** Sub-directories of ~/.pablo included in a backup. */
    private const STORE_SUBDIRS = ['state', 'stamps', 'logs', 'cache'];

    public function __construct(
        private readonly GitRepoInterface $git,
        private readonly ProcessRunnerInterface $runner,
        private readonly Time $time,
    ) {
    }

    public function pabloRoot(): string
    {
        $env = getenv('PABLO_ROOT');
        if (false !== $env && '' !== $env) {
            return $env;
        }

        return (getenv('HOME') ?: '~').'/.pablo';
    }

    public function defaultBackupDir(): string
    {
        $env = getenv('PABLO_BACKUPS_DIR');
        if (false !== $env && '' !== $env) {
            return $env;
        }

        return (getenv('HOME') ?: '~').'/'.self::DEFAULT_BACKUP_SUBDIR;
    }

    public function defaultArchiveName(?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return 'pablo-backup-'.$now->format('Ymd-His').'.tar.gz';
    }

    /**
     * @param array<string, ProjectConfig> $projects
     */
    public function buildManifest(array $projects, Store $store, string $pabloRoot): Manifest
    {
        $projectList = [];
        foreach ($projects as $cfg) {
            $branches = array_values(array_map(
                static fn (Task $t) => $t->branch,
                $store->allTasks($cfg->name),
            ));
            sort($branches, \SORT_STRING);
            $projectList[] = new ManifestProject(
                name: $cfg->name,
                sourceRepoPath: $cfg->repoPath,
                originUrl: $this->git->originUrl($cfg->repoPath),
                worktreesRoot: $cfg->worktreesRoot,
                primaryBranch: $cfg->primaryBranch,
                branches: $branches,
            );
        }

        return new Manifest(
            version: self::MANIFEST_VERSION,
            pabloVersion: ConsoleApplication::VERSION,
            createdAt: $this->time->utcnow(),
            pabloRoot: $pabloRoot,
            projects: $projectList,
        );
    }

    /**
     * Create a full .tar.gz backup archive and return its path.
     *
     * @param array<string, ProjectConfig> $projects
     */
    public function writeArchive(
        string $pabloRoot,
        string $projectsDir,
        array $projects,
        Store $store,
        string $destination,
    ): string {
        $gz = rtrim($destination, '/');
        if (1 !== preg_match('/\.gz$/i', $gz)) {
            $gz .= '.tar.gz';
        }
        $rawTar = \strlen($gz) > 3 ? substr($gz, 0, -3) : $gz.'.tar';

        $staging = $this->stage($pabloRoot, $projectsDir, $projects, $store);
        try {
            $dir = \dirname($gz);
            if (!is_dir($dir)) {
                @mkdir($dir, 0o777, true);
            }
            $phar = new \PharData($rawTar);
            $phar->buildFromDirectory($staging);
            unset($phar);
            (new \PharData($rawTar))->compress(\Phar::GZ);
            @unlink($rawTar);
        } finally {
            $this->cleanupDir($staging);
        }

        return $gz;
    }

    /**
     * Extract an archive into $dest and return the parsed manifest.
     */
    public function extractArchive(string $archive, string $dest): Manifest
    {
        if (!is_file($archive)) {
            throw new PabloError("backup archive not found: {$archive}");
        }
        if (!is_dir($dest)) {
            @mkdir($dest, 0o777, true);
        }
        $phar = new \PharData($archive);
        $phar->extractTo($dest, null, true);

        $manifestPath = rtrim($dest, '/').'/manifest.json';
        if (!is_file($manifestPath)) {
            throw new PabloError("{$archive} is not a PABLO backup (missing manifest.json)");
        }
        /** @var array<string, mixed>|null $data */
        $data = json_decode((string) file_get_contents($manifestPath), true);
        if (!\is_array($data)) {
            throw new PabloError("invalid manifest in {$archive}");
        }

        return Manifest::fromArray($data);
    }

    /**
     * Restore the state/stamps/logs/cache subtrees of an extracted backup
     * into $pabloRoot. Returns the sub-directory names actually written.
     *
     * @return list<string>
     */
    public function restoreStoreTree(string $extractedRoot, string $pabloRoot, bool $overwrite): array
    {
        $restored = [];
        foreach (self::STORE_SUBDIRS as $sub) {
            $src = $extractedRoot.'/'.$sub;
            if (!is_dir($src)) {
                continue;
            }
            $dest = $pabloRoot.'/'.$sub;
            if (is_dir($dest) && !$overwrite) {
                $restored[] = $sub.':skipped';
                continue;
            }
            $this->cleanupDir($dest);
            $this->copyDir($src, $dest);
            $restored[] = $sub;
        }

        return $restored;
    }

    /**
     * Restore the embedded project configs into $projectsDir.
     *
     * @return list<string>
     */
    public function restoreProjects(string $extractedRoot, string $projectsDir, bool $overwrite): array
    {
        $src = $extractedRoot.'/projects';
        if (!is_dir($src)) {
            return [];
        }
        if (!is_dir($projectsDir)) {
            @mkdir($projectsDir, 0o777, true);
        }
        $written = [];
        foreach (glob(rtrim($src, '/').'/*.yaml') ?: [] as $yamlPath) {
            $dest = $projectsDir.'/'.basename($yamlPath);
            if (is_file($dest) && !$overwrite) {
                $written[] = basename($yamlPath).':skipped';
                continue;
            }
            copy($yamlPath, $dest);
            $written[] = basename($yamlPath);
        }

        return $written;
    }

    public function cleanupDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->cleanupDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * @param array<string, ProjectConfig> $projects
     */
    private function stage(string $pabloRoot, string $projectsDir, array $projects, Store $store): string
    {
        $staging = sys_get_temp_dir().'/pablo-backup-'.uniqid();
        mkdir($staging, 0o777, true);

        if (is_dir($projectsDir)) {
            foreach (glob(rtrim($projectsDir, '/').'/*.yaml') ?: [] as $yamlPath) {
                $dest = $staging.'/projects/'.basename($yamlPath);
                $dir = \dirname($dest);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0o777, true);
                }
                copy($yamlPath, $dest);
            }
        }

        foreach (self::STORE_SUBDIRS as $sub) {
            $src = $pabloRoot.'/'.$sub;
            if (is_dir($src)) {
                $this->copyDir($src, $staging.'/'.$sub);
            }
        }

        $manifest = $this->buildManifest($projects, $store, $pabloRoot);

        file_put_contents(
            $staging.'/manifest.json',
            json_encode($manifest->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n",
        );

        $orcaRepos = $this->captureOrcaRepos();
        if (null !== $orcaRepos) {
            file_put_contents($staging.'/orca-repos.json', $orcaRepos);
        }

        return $staging;
    }

    private function captureOrcaRepos(): ?string
    {
        try {
            return $this->runner->run(['orca', 'repo', 'list', '--json'], false, 30);
        } catch (\Throwable) {
            return null;
        }
    }

    private function copyDir(string $src, string $dest): void
    {
        if (!is_dir($dest)) {
            @mkdir($dest, 0o777, true);
        }
        foreach (scandir($src) ?: [] as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $from = $src.'/'.$item;
            $to = $dest.'/'.$item;
            if (is_dir($from)) {
                $this->copyDir($from, $to);
            } else {
                copy($from, $to);
            }
        }
    }
}
