<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime\ClaudeCode;

use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\AgentTranslation;
use Pablo\Agents\Runtime\RenderedFileCheck;
use Pablo\Agents\Runtime\RuntimeAgentFilesInterface;
use Pablo\Doctor\LinkedFileCheck;
use Pablo\Support\Files;
use Pablo\Support\PabloError;
use Pablo\Support\PabloPaths;

/**
 * Claude Code's generated files, under ~/.pablo/runtime/claude:
 *  - agents.json, the `--agents` file PABLO's own launches pass;
 *  - agents/pablo-<agent>.md, linked into ~/.claude/agents so /agents and
 *    the @ picker of the user's own sessions show them;
 *  - commands/, the commands translated (linked into ~/.claude/commands).
 * The shared skills are linked into ~/.claude/skills as-is.
 */
final class ClaudeCodeAgentFiles implements RuntimeAgentFilesInterface
{
    /**
     * @param string|null $dir     override of ~/.pablo/runtime/claude (tests)
     * @param string|null $userDir override of ~/.claude (tests)
     */
    public function __construct(
        private readonly ClaudeCodeAgents $agents,
        private readonly ?string $dir = null,
        private readonly ?string $userDir = null,
    ) {
    }

    public function generatedDir(): string
    {
        return $this->dir ?? ClaudeCodeRuntime::defaultDir();
    }

    public function userConfigDir(): string
    {
        return $this->userDir ?? ClaudeCodeRuntime::userConfigDir();
    }

    public function generate(string $agentsDir, array $providers, ?string $outputDir = null): array
    {
        $outputDir ??= $this->generatedDir();
        $files = [$outputDir.'/agents.json' => $this->agents->renderAgentsJson($agentsDir, $providers)];
        foreach ($this->agents->renderAgentFiles($agentsDir, $providers) as $file => $content) {
            $files[$outputDir.'/agents/'.$file] = $content;
        }
        foreach (Files::list(AgentTemplates::commandsDirFor($agentsDir), '*.md') as $source) {
            $files[$outputDir.'/commands/'.basename($source)] = $this->agents->renderCommand($source);
        }

        return $files;
    }

    public function links(string $renderedDir): array
    {
        $dir = $this->generatedDir();
        if (!is_file($dir.'/agents.json')) {
            throw new PabloError("{$dir}/agents.json was not generated — run: pablo system:generate-agents --runtime ".ClaudeCodeRuntime::NAME);
        }
        $user = $this->userConfigDir();
        $links = [];
        foreach (AgentTemplates::AGENT_NAMES as $name) {
            $file = AgentTranslation::agentId($name).'.md';
            $links[$user.'/agents/'.$file] = $dir.'/agents/'.$file;
        }
        foreach (Files::list($dir.'/commands', '*.md') as $command) {
            $links[$user.'/commands/'.basename($command)] = $command;
        }
        foreach (Files::list($renderedDir.'/skills', '*', dirsOnly: true) as $skill) {
            $links[$user.'/skills/'.basename($skill)] = $skill;
        }

        return $links;
    }

    public function installedPaths(string $agentsDir): array
    {
        $user = $this->userConfigDir();
        $paths = [];
        foreach (AgentTemplates::AGENT_NAMES as $name) {
            $paths[] = $user.'/agents/'.AgentTranslation::agentId($name).'.md';
        }
        foreach (AgentTemplates::commandNames($agentsDir) as $command) {
            $paths[] = $user.'/commands/'.$command.'.md';
        }
        foreach (AgentTemplates::skillNames($agentsDir) as $skill) {
            $paths[] = $user.'/skills/'.$skill;
        }

        return $paths;
    }

    /**
     * agents.json (one file for all agents, passed with --agents), each
     * linked pablo-<agent>.md, the linked commands, and the presence of the
     * linked skills.
     */
    public function staleness(string $agentsDir, array $providers): array
    {
        $user = $this->userConfigDir();
        $results = [RenderedFileCheck::generated('agents.json', $this->generatedDir().'/agents.json', fn (): string => $this->agents->renderAgentsJson($agentsDir, $providers))];

        foreach (AgentTemplates::AGENT_NAMES as $name) {
            $id = AgentTranslation::agentId($name);
            $results[] = RenderedFileCheck::linked($id, $user.'/agents/'.$id.'.md', fn (): string => $this->agents->profile($name, $agentsDir, $providers)->agentFile($id));
        }

        foreach (Files::list(AgentTemplates::commandsDirFor($agentsDir), '*.md') as $source) {
            $name = basename($source, '.md');
            $results[] = RenderedFileCheck::linked('/'.$name, $user.'/commands/'.$name.'.md', fn (): string => $this->agents->renderCommand($source));
        }

        foreach (AgentTemplates::skillNames($agentsDir) as $name) {
            $results[] = LinkedFileCheck::check('skill '.$name, $user.'/skills/'.$name, null);
        }

        return $results;
    }

    public function installNotes(): array
    {
        return [];
    }

    /** The clone install kept the `--agents` file in ~/.pablo/claude. */
    public function cleanupLegacy(): array
    {
        $legacy = PabloPaths::pabloDir().'/claude';
        if ($legacy === $this->generatedDir() || !is_dir($legacy)) {
            return [];
        }
        Files::tryRemove($legacy.'/agents.json');
        if (!@rmdir($legacy)) {
            Files::remove($legacy);
        }

        return ["removed {$legacy} (the --agents file now lives in {$this->generatedDir()})"];
    }
}
