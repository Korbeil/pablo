<?php

declare(strict_types=1);

namespace Pablo\Backup;

use Pablo\App\ConsoleApplication;
use Pablo\Config\ProjectConfig;
use Pablo\Domain\Task;
use Pablo\Domain\Time;
use Pablo\Provider\Git\GitRepo;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Pablo\Support\Proc;

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

    public static function pabloRoot(): string
    {
        $env = getenv('PABLO_ROOT');
        if (false !== $env && '' !== $env) {
            return $env;
        }

        return (getenv('HOME') ?: '~').'/.pablo';
    }

    public static function defaultBackupDir(): string
    {
        $env = getenv('PABLO_BACKUPS_DIR');
        if (false !== $env && '' !== $env) {
            return $env;
        }

        return (getenv('HOME') ?: '~').'/'.self::DEFAULT_BACKUP_SUBDIR;
    }

    public static function defaultArchiveName(?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return 'pablo-backup-'.$now->format('Ymd-His').'.tar.gz';
    }

    /**
     * @param array<string, ProjectConfig> $projects
     *
     * @return array<string, mixed>
     */
    public static function buildManifest(array $projects, Store $store, string $pabloRoot): array
    {
        $projectList = [];
        foreach ($projects as $cfg) {
            $branches = array_values(array_map(
                static fn (Task $t) => $t->branch,
                $store->allTasks($cfg->name),
            ));
            sort($branches, \SORT_STRING);
            $projectList[] = [
                'name' => $cfg->name,
                'source_repo_path' => $cfg->repoPath,
                'origin_url' => GitRepo::originUrl($cfg->repoPath),
                'worktrees_root' => $cfg->worktreesRoot,
                'primary_branch' => $cfg->primaryBranch,
                'branches' => $branches,
            ];
        }

        return [
            'version' => self::MANIFEST_VERSION,
            'pablo_version' => ConsoleApplication::VERSION,
            'created_at' => Time::utcnow(),
            'pablo_root' => $pabloRoot,
            'projects' => $projectList,
        ];
    }

    /**
     * Create a full .tar.gz backup archive and return its path.
     *
     * @param array<string, ProjectConfig> $projects
     */
    public static function writeArchive(
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

        $staging = self::stage($pabloRoot, $projectsDir, $projects, $store);
        try {
            @mkdir(\dirname($gz), 0o777, true);
            $phar = new \PharData($rawTar);
            $phar->buildFromDirectory($staging);
            unset($phar);
            (new \PharData($rawTar))->compress(\Phar::GZ);
            @unlink($rawTar);
        } finally {
            self::cleanupDir($staging);
        }

        return $gz;
    }

    /**
     * Extract an archive into $dest and return the parsed manifest.
     *
     * @return array<string, mixed>
     */
    public static function extractArchive(string $archive, string $dest): array
    {
        if (!is_file($archive)) {
            throw new PabloError("backup archive not found: {$archive}");
        }
        @mkdir($dest, 0o777, true);
        $phar = new \PharData($archive);
        $phar->extractTo($dest, null, true);

        $manifestPath = rtrim($dest, '/').'/manifest.json';
        if (!is_file($manifestPath)) {
            throw new PabloError("{$archive} is not a PABLO backup (missing manifest.json)");
        }
        $data = json_decode((string) file_get_contents($manifestPath), true);
        if (!\is_array($data)) {
            throw new PabloError("invalid manifest in {$archive}");
        }

        return $data;
    }

    /**
     * Restore the state/stamps/logs/cache subtrees of an extracted backup
     * into $pabloRoot. Returns the sub-directory names actually written.
     *
     * @return list<string>
     */
    public static function restoreStoreTree(string $extractedRoot, string $pabloRoot, bool $overwrite): array
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
            self::cleanupDir($dest);
            self::copyDir($src, $dest);
            $restored[] = $sub;
        }

        return $restored;
    }

    /**
     * Restore the embedded project configs into $projectsDir.
     *
     * @return list<string>
     */
    public static function restoreProjects(string $extractedRoot, string $projectsDir, bool $overwrite): array
    {
        $src = $extractedRoot.'/projects';
        if (!is_dir($src)) {
            return [];
        }
        @mkdir($projectsDir, 0o777, true);
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

    public static function cleanupDir(string $dir): void
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
                self::cleanupDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * @param array<string, ProjectConfig> $projects
     */
    private static function stage(string $pabloRoot, string $projectsDir, array $projects, Store $store): string
    {
        $staging = sys_get_temp_dir().'/pablo-backup-'.uniqid();
        mkdir($staging, 0o777, true);

        if (is_dir($projectsDir)) {
            foreach (glob(rtrim($projectsDir, '/').'/*.yaml') ?: [] as $yamlPath) {
                $dest = $staging.'/projects/'.basename($yamlPath);
                @mkdir(\dirname($dest), 0o777, true);
                copy($yamlPath, $dest);
            }
        }

        foreach (self::STORE_SUBDIRS as $sub) {
            $src = $pabloRoot.'/'.$sub;
            if (is_dir($src)) {
                self::copyDir($src, $staging.'/'.$sub);
            }
        }

        file_put_contents(
            $staging.'/manifest.json',
            json_encode(
                self::buildManifest($projects, $store, $pabloRoot),
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
            )."\n",
        );

        $orcaRepos = self::captureOrcaRepos();
        if (null !== $orcaRepos) {
            file_put_contents($staging.'/orca-repos.json', $orcaRepos);
        }

        return $staging;
    }

    private static function captureOrcaRepos(): ?string
    {
        try {
            return Proc::run(['orca', 'repo', 'list', '--json'], false, 30);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function copyDir(string $src, string $dest): void
    {
        @mkdir($dest, 0o777, true);
        foreach (scandir($src) ?: [] as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $from = $src.'/'.$item;
            $to = $dest.'/'.$item;
            if (is_dir($from)) {
                self::copyDir($from, $to);
            } else {
                copy($from, $to);
            }
        }
    }
}
