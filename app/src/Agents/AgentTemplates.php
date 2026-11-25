<?php

declare(strict_types=1);

namespace Pablo\Agents;

use Pablo\Config\Config;
use Pablo\Provider\Forge\RepoRef;
use Pablo\Support\Files;
use Pablo\Support\PabloPaths;

/**
 * Renders the five opencode agent .md files IN MEMORY from the
 * opencode/agents/*.md.template sources plus the issue-tracker providers and
 * the forges (GitHub, GitLab) the configured projects use. Shared by `pablo system:generate-agents` (writes
 * the rendered files under ~/.pablo/runtime) and `pablo system:doctor`
 * (compares a fresh render against what is installed under
 * ~/.config/opencode) so the two can never
 * drift apart. Reads only — never writes. These renders are also the source
 * every other runtime translates (see Runtime\RuntimeAgentFilesInterface).
 */
final class AgentTemplates
{
    public const AGENT_NAMES = [
        'task-analyst',
        'task-feedback',
        'ci-analyst',
        'pr-feedback',
        'rebase-conflict-resolver',
    ];

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * The bundled *.md.template sources (plus shared/ and providers/): in the
     * repository checkout, or inside the standalone binary — read-only either
     * way, so nothing is ever rendered next to them.
     */
    public function templatesDir(): string
    {
        return PabloPaths::resourcesDir().'/opencode/agents';
    }

    /** Commands sources (opencode/commands), next to the agents directory. */
    public static function commandsDirFor(string $agentsDir): string
    {
        return \dirname($agentsDir).'/commands';
    }

    /** Skills sources (opencode/skills/<name>/), next to the agents directory. */
    public static function skillsDirFor(string $agentsDir): string
    {
        return \dirname($agentsDir).'/skills';
    }

    /**
     * ~/.pablo/runtime/opencode: the shared render every runtime starts from —
     * the rendered agents (agents/) plus the commands and skills extracted
     * from the bundle (commands/, skills/). A symlink cannot point into the
     * binary, so the links `pablo system:install` creates lead here (or to a
     * runtime's own translation, see RuntimeAgentFilesInterface). Its name
     * predates the other runtimes and is kept so existing links stay valid.
     * Resolved per call.
     */
    public static function renderedDir(): string
    {
        return PabloPaths::runtimeDir().'/opencode';
    }

    /**
     * Names of the bundled commands next to $agentsDir.
     *
     * @return list<string>
     */
    public static function commandNames(string $agentsDir): array
    {
        return array_map(static fn (string $path): string => basename($path, '.md'), Files::list(self::commandsDirFor($agentsDir), '*.md'));
    }

    /**
     * Names of the bundled skills next to $agentsDir.
     *
     * @return list<string>
     */
    public static function skillNames(string $agentsDir): array
    {
        return array_map(basename(...), Files::list(self::skillsDirFor($agentsDir), '*', dirsOnly: true));
    }

    /**
     * Providers any configured project uses — all of them when no projects
     * directory exists yet. Resolved per call (like every other PABLO_* /
     * HOME derived default) so a cached DI container can never freeze it.
     *
     * @return list<string>
     */
    public function enabledProviders(?string $projectsDir = null): array
    {
        $projectsDir ??= $this->config->projectsDir();
        if (!is_dir($projectsDir)) {
            return Config::PROVIDERS;
        }

        $providers = [];
        foreach (glob(rtrim($projectsDir, '/').'/*.yaml') ?: [] as $path) {
            $data = $this->config->loadYaml($path);
            if (isset($data['issue_tracker']['provider'])) {
                $providers[] = $data['issue_tracker']['provider'];
            }
        }

        return array_values(array_unique($providers));
    }

    /**
     * Forges any configured project uses (repo.forge, else told by the
     * origin remote in the repository's .git/config, else GitHub, or GitLab
     * for a GitLab-tracked project) — all of them when no projects directory
     * exists yet. Read from files only, never git: a render must stay cheap.
     *
     * @return list<string>
     */
    public function enabledForges(?string $projectsDir = null): array
    {
        $projectsDir ??= $this->config->projectsDir();
        if (!is_dir($projectsDir)) {
            return Config::FORGES;
        }

        $forges = [];
        foreach (glob(rtrim($projectsDir, '/').'/*.yaml') ?: [] as $path) {
            $data = $this->config->loadYaml($path);
            $forges[] = self::forgeOf($data);
        }

        return array_values(array_intersect(Config::FORGES, $forges));
    }

    /** @param array<mixed> $project */
    private static function forgeOf(array $project): string
    {
        $configured = $project['repo']['forge'] ?? null;
        if (\is_string($configured) && \in_array($configured, Config::FORGES, true)) {
            return $configured;
        }
        $repoPath = $project['repo']['path'] ?? null;
        $url = \is_string($repoPath) ? self::originUrl(PabloPaths::expandTilde($repoPath)) : null;
        $remote = null !== $url ? RepoRef::parseRemote($url) : null;
        $forge = null !== $remote ? RepoRef::forgeOfHost($remote[0]) : null;

        return $forge ?? (($project['issue_tracker']['provider'] ?? null) === 'gitlab' ? RepoRef::GITLAB : RepoRef::GITHUB);
    }

    /** The origin remote's URL, from the repository's .git/config. */
    private static function originUrl(string $repoPath): ?string
    {
        $config = @file_get_contents($repoPath.'/.git/config'); // best-effort: no repo, no forge hint
        if (false === $config) {
            return null;
        }
        if (1 !== preg_match('/^\s*\[remote "origin"\]\s*$(.*?)(?=^\s*\[|\z)/ms', $config, $section)
            || 1 !== preg_match('/^\s*url\s*=\s*(\S+)\s*$/m', $section[1], $url)) {
            return null;
        }

        return $url[1];
    }

    /**
     * Render one agent's .md content exactly as system:generate-agents
     * writes it to disk.
     *
     * @param list<string>      $enabledProviders
     * @param list<string>|null $enabledForges    null: enabledForges()
     *
     * @throws AgentTemplateError when the template, a provider section or a
     *                            shared frontmatter/body profile is missing/unreadable
     */
    public function render(string $agentsDir, string $name, array $enabledProviders, ?array $enabledForges = null): string
    {
        $templatePath = $agentsDir.'/'.$name.'.md.template';
        if (!is_file($templatePath)) {
            throw new AgentTemplateError("Template not found: {$templatePath}");
        }

        $template = file_get_contents($templatePath);
        if (false === $template) {
            throw new AgentTemplateError("Cannot read template: {$templatePath}");
        }

        $section = $this->buildSection($enabledProviders, $name, $agentsDir.'/providers');
        $names = $this->formatProviderNames($enabledProviders);

        $content = str_replace(
            ['{{ISSUE_TRACKER_SECTION}}', '{{ISSUE_TRACKER_NAMES}}'],
            [$section, $names],
            $template,
        );

        $content = $this->renderForgeSlots($content, $agentsDir.'/forges', $enabledForges ?? $this->enabledForges());

        $tokenMatches = [];
        $tokenCount = preg_match_all('/\{\{SHARED_(FRONTMATTER|BODY):([a-z-]+)\}\}/', $content, $tokenMatches);
        $sharedSlots = [];
        if (false !== $tokenCount) {
            for ($i = 0; $i < $tokenCount; ++$i) {
                $sharedSlots[$tokenMatches[1][$i].':'.$tokenMatches[2][$i]] = true;
            }
        }

        $extensionByType = ['FRONTMATTER' => 'frontmatter.md', 'BODY' => 'body.md'];
        $labelByType = ['FRONTMATTER' => 'frontmatter', 'BODY' => 'body'];

        foreach (array_keys($sharedSlots) as $slotKey) {
            [$type, $profile] = array_pad(explode(':', (string) $slotKey, 2), 2, '');
            $profilePath = $agentsDir.'/shared/'.$profile.'.'.$extensionByType[$type];
            $shared = @file_get_contents($profilePath);
            if (false === $shared || '' === trim($shared)) {
                throw new AgentTemplateError(\sprintf('Shared %s profile not found: %s', $labelByType[$type], $profilePath));
            }

            $content = str_replace(
                '{{SHARED_'.$type.':'.$profile.'}}'."\n",
                rtrim($shared, "\n")."\n",
                $content,
            );
        }

        return $content;
    }

    /**
     * Fills each `{{FORGE:<slot>}}` line with forges/<forge>-<slot>.md of
     * every enabled forge, GitHub first; a forge without that fragment adds
     * nothing.
     *
     * @param list<string> $forges
     */
    private function renderForgeSlots(string $content, string $forgesDir, array $forges): string
    {
        $forges = [] === $forges ? Config::FORGES : $forges;

        return (string) preg_replace_callback('/^\{\{FORGE:([a-z-]+)\}\}$/m', function (array $m) use ($forgesDir, $forges): string {
            $parts = [];
            foreach (array_values(array_intersect(Config::FORGES, $forges)) as $forge) {
                $part = $this->providerItem($forgesDir.'/'.$forge.'-'.$m[1].'.md');
                if (null !== $part) {
                    $parts[] = $part;
                }
            }
            if ([] === $parts) {
                throw new AgentTemplateError("No forge section for {{FORGE:{$m[1]}}} (forges: ".implode(', ', $forges).')');
            }

            return implode("\n\n", $parts);
        }, $content);
    }

    /**
     * @param list<string> $providers
     */
    private function formatProviderNames(array $providers): string
    {
        $displayNames = array_map(
            static fn (string $p): string => match ($p) {
                'github' => 'GitHub Issues',
                'gitlab' => 'GitLab Issues',
                'jira' => 'Jira',
                'linear' => 'Linear',
                default => $p,
            },
            $providers,
        );

        if ([] === $displayNames) {
            return 'your issue tracker';
        }

        if (1 === \count($displayNames)) {
            return $displayNames[0];
        }

        $last = array_pop($displayNames);

        return implode(', ', $displayNames).', or '.$last;
    }

    /**
     * @param list<string> $providers
     */
    private function buildSection(array $providers, string $agentName, string $providersDir): string
    {
        $items = [];
        $num = 1;

        foreach ($providers as $provider) {
            $item = $this->providerItem($providersDir.'/'.$provider.'-'.$agentName.'.md');
            if (null !== $item) {
                $items[] = $num.'. '.$item;
                ++$num;
            }
        }

        if (\in_array('jira', $providers, true)) {
            $item = $this->providerItem($providersDir.'/confluence-'.$agentName.'.md');
            if (null !== $item) {
                $items[] = $num.'. '.$item;
                ++$num;
            }
        }

        $fallback = $this->providerItem($providersDir.'/fallback-'.$agentName.'.md');
        if (null !== $fallback) {
            $items[] = $num.'. '.$fallback;
        }

        return implode("\n\n", $items);
    }

    private function providerItem(string $file): ?string
    {
        if (!is_file($file)) {
            return null;
        }

        $content = file_get_contents($file);
        if (false === $content || '' === trim($content)) {
            return null;
        }

        return trim($content);
    }
}
