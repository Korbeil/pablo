<?php

declare(strict_types=1);

namespace Pablo\Doctor;

use Pablo\Agents\AgentTemplateError;
use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Agents\Runtime\AgentRuntimeResolver;
use Pablo\Agents\Runtime\ClaudeCodeAgents;
use Pablo\Agents\Runtime\ClaudeCodeRuntime;
use Pablo\Agents\Runtime\CopilotAgents;
use Pablo\Agents\Runtime\CopilotRuntime;
use Pablo\Support\Files;
use Pablo\Support\PabloError;

/**
 * system:doctor's agents-staleness check, for the selected runtime.
 *
 * OpenCode: for each of the five generated agents, render the template in
 * memory (no writes) exactly as `system:generate-agents` would and compare
 * against the installed ~/.config/opencode/agents/<name>.md — the link
 * `pablo system:install` creates (into ~/.pablo/runtime/opencode/agents).
 *
 * Claude Code: the same comparison for ~/.pablo/runtime/claude/agents.json (one file
 * for all five agents, passed with --agents) and the
 * ~/.claude/commands/pablo-commit-and-pr.md symlink, plus the presence of the
 * ~/.claude/skills/pablo-task symlink.
 *
 * Copilot: the same comparison for each ~/.copilot/agents/pablo-<agent>.agent.md
 * symlink and the ~/.copilot/skills/pablo-commit-and-pr/SKILL.md the skill
 * symlink leads to, plus the presence of the ~/.copilot/skills/pablo-task
 * symlink.
 *
 * Symlinks are resolved; broken links and files PABLO does not manage are
 * reported distinctly.
 */
final class AgentStaleness
{
    /** Renders the agents and (re)creates the links: the fix for every hard failure here. */
    private const INSTALL = 'pablo system:install';

    /**
     * @param callable|null $check optional full-result override fn(): list<AgentFileStatus>
     */
    public function __construct(
        private readonly AgentTemplates $agentTemplates,
        private $check = null,
        private ?AgentRuntimeResolver $runtimes = null,
    ) {
    }

    private function runtimeName(): string
    {
        try {
            return ($this->runtimes ??= new AgentRuntimeResolver())->resolveName();
        } catch (PabloError) {
            return AgentRuntimeInterface::OPENCODE; // doctor reports the bad name itself
        }
    }

    /**
     * Same convention as `pablo system:install` ($HOME/.config/opencode); resolved at
     * call time like every HOME-derived default, never at container compile time.
     */
    public function installedDir(): string
    {
        return (getenv('HOME') ?: '~').'/.config/opencode/agents';
    }

    /**
     * @param string|null $installedDir override of ~/.config/opencode/agents (tests)
     * @param string|null $agentsDir    override of the repo template dir (tests)
     *
     * @return list<AgentFileStatus>
     */
    public function check(?string $installedDir = null, ?string $agentsDir = null): array
    {
        if (null !== $this->check) {
            return ($this->check)();
        }
        $runtime = $this->runtimeName();
        if (AgentRuntimeInterface::CLAUDE_CODE === $runtime) {
            return $this->checkClaudeCode(agentsDir: $agentsDir);
        }
        if (AgentRuntimeInterface::COPILOT === $runtime) {
            return $this->checkCopilot(agentsDir: $agentsDir);
        }

        $installedDir ??= $this->installedDir();
        $agentsDir ??= $this->agentTemplates->templatesDir();
        $providers = $this->agentTemplates->enabledProviders();

        $results = [];
        foreach (AgentTemplates::AGENT_NAMES as $name) {
            $results[] = self::checkOne($name, $installedDir.'/'.$name.'.md', $agentsDir, $providers);
        }

        return $results;
    }

    /**
     * @param string|null $claudeDir     override of ~/.pablo/runtime/claude (tests)
     * @param string|null $userClaudeDir override of ~/.claude (tests)
     * @param string|null $agentsDir     override of the repo template dir (tests)
     *
     * @return list<AgentFileStatus>
     */
    public function checkClaudeCode(?string $claudeDir = null, ?string $userClaudeDir = null, ?string $agentsDir = null): array
    {
        $claudeDir ??= ClaudeCodeRuntime::defaultDir();
        $userClaudeDir ??= ClaudeCodeRuntime::userConfigDir();
        $agentsDir ??= $this->agentTemplates->templatesDir();
        $claude = new ClaudeCodeAgents($this->agentTemplates);
        $regenerate = 'run: '.self::INSTALL;

        $results = [];
        try {
            $fresh = $claude->renderAgentsJson($agentsDir, $this->agentTemplates->enabledProviders());
            $dest = $claudeDir.'/agents.json';
            $results[] = is_file($dest)
                ? self::compare('agents.json', $dest, $fresh)
                : new AgentFileStatus('agents.json', AgentFileState::Missing, 'missing ('.$dest.')', $regenerate);
        } catch (AgentTemplateError $e) {
            $results[] = new AgentFileStatus('agents.json', AgentFileState::Unverifiable, 'cannot verify freshness: '.$e->getMessage());
        }

        $providers = $this->agentTemplates->enabledProviders();
        foreach (AgentTemplates::AGENT_NAMES as $name) {
            $id = ClaudeCodeAgents::agentId($name);
            try {
                $fresh = $claude->profile($name, $agentsDir, $providers)->agentFile($id);
            } catch (AgentTemplateError $e) {
                $results[] = new AgentFileStatus($id, AgentFileState::Unverifiable, 'cannot verify freshness: '.$e->getMessage());
                continue;
            }
            $results[] = self::checkLinked($id, $userClaudeDir.'/agents/'.$id.'.md', $fresh);
        }

        foreach (Files::list(AgentTemplates::commandsDirFor($agentsDir), '*.md') as $source) {
            $name = basename($source, '.md');
            try {
                $results[] = self::checkLinked('/'.$name, $userClaudeDir.'/commands/'.$name.'.md', $claude->renderCommand($source));
            } catch (AgentTemplateError $e) {
                $results[] = new AgentFileStatus('/'.$name, AgentFileState::Unverifiable, 'cannot verify freshness: '.$e->getMessage());
            }
        }

        foreach (Files::list(AgentTemplates::skillsDirFor($agentsDir), '*', dirsOnly: true) as $skillDir) {
            $name = basename($skillDir);
            $results[] = self::checkLinked('skill '.$name, $userClaudeDir.'/skills/'.$name, null);
        }

        return $results;
    }

    /**
     * @param string|null $userCopilotDir override of ~/.copilot (tests)
     * @param string|null $agentsDir      override of the repo template dir (tests)
     *
     * @return list<AgentFileStatus>
     */
    public function checkCopilot(?string $userCopilotDir = null, ?string $agentsDir = null): array
    {
        $userCopilotDir ??= CopilotRuntime::userConfigDir();
        $agentsDir ??= $this->agentTemplates->templatesDir();
        $copilot = new CopilotAgents($this->agentTemplates);
        $providers = $this->agentTemplates->enabledProviders();

        $results = [];
        foreach (AgentTemplates::AGENT_NAMES as $name) {
            $id = CopilotAgents::agentId($name);
            try {
                $fresh = $copilot->profile($name, $agentsDir, $providers)->definition();
            } catch (AgentTemplateError $e) {
                $results[] = new AgentFileStatus($id, AgentFileState::Unverifiable, 'cannot verify freshness: '.$e->getMessage());
                continue;
            }
            $results[] = self::checkLinked($id, $userCopilotDir.'/agents/'.$id.'.agent.md', $fresh);
        }

        foreach (Files::list(AgentTemplates::commandsDirFor($agentsDir), '*.md') as $source) {
            $name = basename($source, '.md');
            try {
                $results[] = self::checkLinked('skill '.$name, $userCopilotDir.'/skills/'.$name, $copilot->renderSkill($source), 'SKILL.md');
            } catch (AgentTemplateError $e) {
                $results[] = new AgentFileStatus('skill '.$name, AgentFileState::Unverifiable, 'cannot verify freshness: '.$e->getMessage());
            }
        }

        foreach (Files::list(AgentTemplates::skillsDirFor($agentsDir), '*', dirsOnly: true) as $skillDir) {
            $name = basename($skillDir);
            $results[] = self::checkLinked('skill '.$name, $userCopilotDir.'/skills/'.$name, null);
        }

        return $results;
    }

    /**
     * A PABLO symlink under ~/.claude or ~/.copilot: compared against $fresh
     * when given (for a linked directory, against its $file), otherwise only
     * required to resolve.
     */
    private static function checkLinked(string $label, string $dest, ?string $fresh, ?string $file = null): AgentFileStatus
    {
        if (!is_link($dest) && !file_exists($dest)) {
            return new AgentFileStatus($label, AgentFileState::Missing, 'missing', 'run: '.self::INSTALL);
        }
        if (!is_link($dest)) {
            return new AgentFileStatus($label, AgentFileState::ForeignFile,
                'exists and is not a PABLO symlink', 'move it aside, then re-run '.self::INSTALL);
        }
        $target = realpath($dest);
        if (false === $target) {
            $linkTarget = readlink($dest);

            return new AgentFileStatus($label, AgentFileState::BrokenSymlink,
                'broken symlink → '.(false !== $linkTarget ? $linkTarget : '?'), 're-run '.self::INSTALL.' to relink');
        }

        if (null === $fresh) {
            return new AgentFileStatus($label, AgentFileState::Ok, 'ok');
        }

        return self::compare($label, null !== $file ? $target.'/'.$file : $target, $fresh);
    }

    /** @param list<AgentFileStatus> $results */
    public function hasHardFailure(array $results): bool
    {
        foreach ($results as $result) {
            if ($result->state->hard()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Renders in the doctor command's existing style: icon + padded name +
     * detail, hint lines indented with an arrow.
     *
     * @param list<AgentFileStatus> $results
     */
    public function render(array $results): string
    {
        $lines = [];
        foreach ($results as $result) {
            $lines[] = \sprintf("{$result->state->icon()} %-24s %s", $result->agent, $result->detail);
            if (!$result->ok() && '' !== $result->hint) {
                $lines[] = "   → {$result->hint}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $providers
     */
    private function checkOne(string $name, string $dest, string $agentsDir, array $providers): AgentFileStatus
    {
        try {
            $fresh = $this->agentTemplates->render($agentsDir, $name, $providers);
        } catch (AgentTemplateError $e) {
            return new AgentFileStatus($name, AgentFileState::Unverifiable,
                'cannot verify freshness: '.$e->getMessage());
        }

        if (!is_link($dest) && !file_exists($dest)) {
            return new AgentFileStatus($name, AgentFileState::Missing, 'missing',
                'run: '.self::INSTALL);
        }

        if (is_link($dest)) {
            $target = realpath($dest);
            if (false === $target) {
                $linkTarget = readlink($dest);

                return new AgentFileStatus($name, AgentFileState::BrokenSymlink,
                    'broken symlink → '.(false !== $linkTarget ? $linkTarget : '?'),
                    're-run '.self::INSTALL.' to relink');
            }

            return self::compare($name, $target, $fresh);
        }

        // A regular file where system:install expects to place its symlink —
        // system:install refuses to touch it, so this agent is not PABLO-managed.
        return new AgentFileStatus($name, AgentFileState::ForeignFile,
            'exists and is not a PABLO symlink', 'move it aside, then re-run '.self::INSTALL);
    }

    private static function compare(string $name, string $realPath, string $fresh): AgentFileStatus
    {
        $installed = @file_get_contents($realPath);
        if (false === $installed) {
            return new AgentFileStatus($name, AgentFileState::Unverifiable,
                "cannot read installed file: {$realPath}");
        }

        if ($installed !== $fresh) {
            return new AgentFileStatus($name, AgentFileState::Stale, 'stale — differs from freshly rendered',
                'run: pablo system:generate-agents');
        }

        return new AgentFileStatus($name, AgentFileState::Ok, 'ok');
    }
}
