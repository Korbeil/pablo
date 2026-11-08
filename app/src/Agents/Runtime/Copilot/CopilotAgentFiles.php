<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime\Copilot;

use Pablo\Agents\AgentTemplateError;
use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\RuntimeAgentFilesInterface;
use Pablo\Doctor\AgentFileState;
use Pablo\Doctor\AgentFileStatus;
use Pablo\Doctor\LinkedFileCheck;
use Pablo\Support\Files;

/**
 * Copilot's generated files, under ~/.pablo/runtime/copilot:
 * agents/pablo-<agent>.agent.md (linked into ~/.copilot/agents, usable in
 * the user's own sessions too) and the commands as skills in
 * skills/<command>/SKILL.md; the shared skills are linked into
 * ~/.copilot/skills next to them.
 */
final class CopilotAgentFiles implements RuntimeAgentFilesInterface
{
    /**
     * @param string|null $dir     override of ~/.pablo/runtime/copilot (tests)
     * @param string|null $userDir override of ~/.copilot (tests)
     */
    public function __construct(
        private readonly CopilotAgents $agents,
        private readonly ?string $dir = null,
        private readonly ?string $userDir = null,
    ) {
    }

    public function generatedDir(): string
    {
        return $this->dir ?? CopilotRuntime::defaultDir();
    }

    public function userConfigDir(): string
    {
        return $this->userDir ?? CopilotRuntime::userConfigDir();
    }

    public function generate(string $agentsDir, array $providers, ?string $outputDir = null): array
    {
        $outputDir ??= $this->generatedDir();
        $files = [];
        foreach ($this->agents->renderAgentFiles($agentsDir, $providers) as $file => $content) {
            $files[$outputDir.'/agents/'.$file] = $content;
        }
        foreach (Files::list(AgentTemplates::commandsDirFor($agentsDir), '*.md') as $source) {
            $files[$outputDir.'/skills/'.basename($source, '.md').'/SKILL.md'] = $this->agents->renderSkill($source);
        }

        return $files;
    }

    public function links(string $renderedDir): array
    {
        $dir = $this->generatedDir();
        $user = $this->userConfigDir();
        $links = [];
        foreach (AgentTemplates::AGENT_NAMES as $name) {
            $file = CopilotAgents::agentId($name).'.agent.md';
            $links[$user.'/agents/'.$file] = $dir.'/agents/'.$file;
        }
        foreach ([...Files::list($dir.'/skills', '*', dirsOnly: true), ...Files::list($renderedDir.'/skills', '*', dirsOnly: true)] as $skill) {
            $links[$user.'/skills/'.basename($skill)] = $skill;
        }

        return $links;
    }

    public function installedPaths(string $agentsDir): array
    {
        $user = $this->userConfigDir();
        $paths = [];
        foreach (AgentTemplates::AGENT_NAMES as $name) {
            $paths[] = $user.'/agents/'.CopilotAgents::agentId($name).'.agent.md';
        }
        foreach ([...AgentTemplates::commandNames($agentsDir), ...AgentTemplates::skillNames($agentsDir)] as $skill) {
            $paths[] = $user.'/skills/'.$skill;
        }

        return $paths;
    }

    /**
     * Each linked pablo-<agent>.agent.md, the SKILL.md each command skill
     * link leads to, and the presence of the linked shared skills.
     */
    public function staleness(string $agentsDir, array $providers): array
    {
        $user = $this->userConfigDir();
        $results = [];
        foreach (AgentTemplates::AGENT_NAMES as $name) {
            $id = CopilotAgents::agentId($name);
            try {
                $fresh = $this->agents->profile($name, $agentsDir, $providers)->definition();
            } catch (AgentTemplateError $e) {
                $results[] = new AgentFileStatus($id, AgentFileState::Unverifiable, 'cannot verify freshness: '.$e->getMessage());
                continue;
            }
            $results[] = LinkedFileCheck::check($id, $user.'/agents/'.$id.'.agent.md', $fresh);
        }

        foreach (Files::list(AgentTemplates::commandsDirFor($agentsDir), '*.md') as $source) {
            $name = basename($source, '.md');
            try {
                $results[] = LinkedFileCheck::check('skill '.$name, $user.'/skills/'.$name, $this->agents->renderSkill($source), 'SKILL.md');
            } catch (AgentTemplateError $e) {
                $results[] = new AgentFileStatus('skill '.$name, AgentFileState::Unverifiable, 'cannot verify freshness: '.$e->getMessage());
            }
        }

        foreach (AgentTemplates::skillNames($agentsDir) as $name) {
            $results[] = LinkedFileCheck::check('skill '.$name, $user.'/skills/'.$name, null);
        }

        return $results;
    }

    public function installNotes(): array
    {
        return ["Copilot: log in once with 'copilot login' if you have not already"];
    }

    public function cleanupLegacy(): array
    {
        return [];
    }
}
