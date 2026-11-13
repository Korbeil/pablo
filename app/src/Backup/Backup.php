<?php

declare(strict_types=1);

namespace Pablo\Backup;

use Pablo\Agents\Ade\AdeRegistry;
use Pablo\App\ConsoleApplication;
use Pablo\Config\ProjectConfig;
use Pablo\Domain\Task;
use Pablo\Domain\Time;
use Pablo\Provider\Git\GitRepoInterface;
use Pablo\Store\Store;
use Pablo\Support\Files;
use Pablo\Support\Json;
use Pablo\Support\PabloError;
use Pablo\Support\PabloPaths;
use Pablo\Support\ProcessRunnerInterface;

/**
 * PABLO backup/restore.
 *
 * A backup is a single .tar.gz captured on the source machine: the state
 * store, poller stamps, rebase logs and cache (everything under ~/.pablo
 * except agents/ — opencode sessions are never saved) plus the embedded
 * project configs, a manifest of every task worktree, and a best-effort
 * snapshot of what each ADE keeps outside ~/.pablo (AdeInterface::backup(),
 * e.g. Orca's registered repositories). Worktree contents are transient
 * checkouts, so only their metadata (origin URL + branch) is kept;
 * restore recreates them from git by checking the remote branch back
 * out, then rewrites each task record's worktree_path and re-registers
 * the repositories with the selected ADE (AdeInterface::restore()).
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
        private readonly AdeRegistry $ades,
        /** Where staging directories go; the system temp dir by default. */
        private readonly ?string $stagingRoot = null,
    ) {
    }

    public function pabloRoot(): string
    {
        return PabloPaths::pabloPath('', 'PABLO_ROOT');
    }

    public function defaultBackupDir(): string
    {
        $env = getenv('PABLO_BACKUPS_DIR');
        if (false !== $env && '' !== $env) {
            return $env;
        }

        return PabloPaths::home().'/'.self::DEFAULT_BACKUP_SUBDIR;
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
                sourceRepoPath: $cfg->repo->path,
                originUrl: $this->git->originUrl($cfg->repo->path),
                worktreesRoot: $cfg->repo->worktreesRoot,
                primaryBranch: $cfg->repo->primaryBranch,
                branches: $branches,
            );
        }

        return new Manifest(
            version: self::MANIFEST_VERSION,
            pabloVersion: ConsoleApplication::version(),
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

        $staging = $this->newStagingDir('backup');
        try {
            $this->stage($staging, $projectsDir, $pabloRoot, $projects, $store);
            $dir = \dirname($gz);
            Files::ensureDir($dir);
            $phar = new \PharData($rawTar);
            $phar->buildFromDirectory($staging);
            unset($phar);
            (new \PharData($rawTar))->compress(\Phar::GZ);
            Files::tryRemove($rawTar);
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
        Files::ensureDir($dest);
        $phar = new \PharData($archive);
        $phar->extractTo($dest, null, true);

        $manifestPath = rtrim($dest, '/').'/manifest.json';
        if (!is_file($manifestPath)) {
            throw new PabloError("{$archive} is not a PABLO backup (missing manifest.json)");
        }
        $data = Json::tryDecodeArray((string) file_get_contents($manifestPath));
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
        Files::ensureDir($projectsDir);
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

    /**
     * A new empty staging directory `<tmp>/pablo-<kind>-<random>`, created
     * atomically; the caller removes it with cleanupDir() in a `finally`.
     */
    public function newStagingDir(string $kind): string
    {
        $root = $this->stagingRoot ?? sys_get_temp_dir();
        for ($i = 0; $i < 10; ++$i) {
            $dir = $root.'/pablo-'.$kind.'-'.bin2hex(random_bytes(8));
            if (@mkdir($dir, 0o700, true)) { // checked: false on collision, retried
                return $dir;
            }
        }

        throw new PabloError('cannot create a staging directory in '.$root);
    }

    /**
     * Removes $dir and its content. A symlink inside is removed, never
     * followed; $dir itself may be a link to a directory, which is emptied
     * and kept (a ~/.pablo subdirectory the user moved elsewhere).
     */
    public function cleanupDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (Files::list($dir) as $child) {
            Files::remove($child);
        }
        @rmdir($dir); // best-effort: fails on a link, which is kept on purpose
    }

    /**
     * @param array<string, ProjectConfig> $projects
     */
    private function stage(string $staging, string $projectsDir, string $pabloRoot, array $projects, Store $store): void
    {
        if (is_dir($projectsDir)) {
            foreach (glob(rtrim($projectsDir, '/').'/*.yaml') ?: [] as $yamlPath) {
                $dest = $staging.'/projects/'.basename($yamlPath);
                $dir = \dirname($dest);
                Files::ensureDir($dir);
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

        // Whatever each ADE keeps outside ~/.pablo (e.g. a repository list).
        foreach ($this->ades->all() as $ade) {
            foreach ($ade->backup($this->runner) as $name => $content) {
                file_put_contents($staging.'/'.$name, $content);
            }
        }
    }

    private function copyDir(string $src, string $dest): void
    {
        Files::ensureDir($dest);
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
