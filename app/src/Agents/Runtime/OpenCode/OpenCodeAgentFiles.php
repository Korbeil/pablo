<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime\OpenCode;

use Pablo\Agents\AgentTemplateError;
use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\RuntimeAgentFilesInterface;
use Pablo\Doctor\AgentFileState;
use Pablo\Doctor\AgentFileStatus;
use Pablo\Doctor\LinkedFileCheck;
use Pablo\Support\Files;
use Pablo\Support\PabloPaths;

/**
 * OpenCode reads the shared render as-is: system:install links its agents/,
 * commands/ and skills/ into ~/.config/opencode (agents/, commands/,
 * skill/), and system:doctor compares each linked agent with a fresh render.
 */
final class OpenCodeAgentFiles implements RuntimeAgentFilesInterface
{
    /** @param string|null $userDir override of ~/.config/opencode (tests) */
    public function __construct(
        private readonly AgentTemplates $templates,
        private readonly ?string $userDir = null,
    ) {
    }

    public function generatedDir(): string
    {
        return AgentTemplates::renderedDir();
    }

    public function userConfigDir(): string
    {
        return $this->userDir ?? PabloPaths::home().'/.config/opencode';
    }

    public function generate(string $agentsDir, array $providers, ?string $outputDir = null): array
    {
        return [];
    }

    public function links(string $renderedDir): array
    {
        $user = $this->userConfigDir();
        $links = [];
        foreach (AgentTemplates::AGENT_NAMES as $name) {
            $links[$user.'/agents/'.$name.'.md'] = $renderedDir.'/agents/'.$name.'.md';
        }
        foreach (Files::list($renderedDir.'/commands', '*.md') as $command) {
            $links[$user.'/commands/'.basename($command)] = $command;
        }
        foreach (Files::list($renderedDir.'/skills', '*', dirsOnly: true) as $skill) {
            $links[$user.'/skill/'.basename($skill)] = $skill;
        }

        return $links;
    }

    public function installedPaths(string $agentsDir): array
    {
        $user = $this->userConfigDir();
        $paths = [];
        foreach (AgentTemplates::AGENT_NAMES as $name) {
            $paths[] = $user.'/agents/'.$name.'.md';
        }
        foreach (AgentTemplates::commandNames($agentsDir) as $command) {
            $paths[] = $user.'/commands/'.$command.'.md';
        }
        foreach (AgentTemplates::skillNames($agentsDir) as $skill) {
            $paths[] = $user.'/skill/'.$skill;
        }

        return $paths;
    }

    public function staleness(string $agentsDir, array $providers): array
    {
        $results = [];
        foreach (AgentTemplates::AGENT_NAMES as $name) {
            try {
                $fresh = $this->templates->render($agentsDir, $name, $providers);
            } catch (AgentTemplateError $e) {
                $results[] = new AgentFileStatus($name, AgentFileState::Unverifiable, 'cannot verify freshness: '.$e->getMessage());
                continue;
            }
            $results[] = LinkedFileCheck::check($name, $this->userConfigDir().'/agents/'.$name.'.md', $fresh);
        }

        return $results;
    }

    public function installNotes(): array
    {
        return [];
    }

    public function cleanupLegacy(): array
    {
        return [];
    }
}
