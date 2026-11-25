<?php

declare(strict_types=1);

namespace Pablo\Provider\Forge;

/**
 * Where a project's repository lives on its forge: the forge (github |
 * gitlab), its host and the repository's slug there ("owner/repo" on GitHub,
 * "group/subgroup/project" on GitLab). Resolved by Support\RepoSlug::ref().
 */
final readonly class RepoRef
{
    public const GITHUB = 'github';
    public const GITLAB = 'gitlab';

    public const DEFAULT_HOSTS = [self::GITHUB => 'github.com', self::GITLAB => 'gitlab.com'];

    public function __construct(
        public string $forge,
        public string $host,
        public string $slug,
    ) {
    }

    public function isGitlab(): bool
    {
        return self::GITLAB === $this->forge;
    }

    /** The repository's web page. */
    public function webUrl(): string
    {
        return "https://{$this->host}/{$this->slug}";
    }

    /** The web page of pull (GitHub) or merge (GitLab) request $number. */
    public function prUrl(int $number): string
    {
        return $this->isGitlab()
            ? $this->webUrl()."/-/merge_requests/{$number}"
            : $this->webUrl()."/pull/{$number}";
    }

    /**
     * The forge a remote's host points to, when the host says it:
     * github.com, gitlab.com, or any host whose name contains "gitlab"
     * (gitlab.example.com, git.gitlab.acme.io). Null for anything else: a
     * self-hosted GitLab elsewhere is declared with repo.forge.
     */
    public static function forgeOfHost(string $host): ?string
    {
        $host = strtolower($host);
        if ('github.com' === $host || 'www.github.com' === $host) {
            return self::GITHUB;
        }
        if (str_contains($host, 'gitlab')) {
            return self::GITLAB;
        }

        return null;
    }

    /**
     * Host and repository path of a git remote URL: scp-like
     * (git@host:group/repo.git) or URL (https://host/group/repo,
     * ssh://git@host:2222/group/repo.git); null when it is neither.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parseRemote(string $url): ?array
    {
        $url = trim($url);
        if (1 === preg_match('#^[a-z][a-z0-9+.-]*://(?:[^@/]+@)?([^/:]+)(?::\d+)?/(.+)$#i', $url, $m)
            || 1 === preg_match('#^(?:[^@/]+@)?([^/:]+):(?!//)(.+)$#', $url, $m)) {
            $path = trim((string) preg_replace('#\.git/?$#', '', $m[2]), '/');

            return '' === $path ? null : [strtolower($m[1]), $path];
        }

        return null;
    }
}
