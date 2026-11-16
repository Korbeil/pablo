<?php

declare(strict_types=1);

namespace Pablo\Agents;

use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Config\Config;
use Pablo\Install\InstallLog;
use Pablo\Support\Files;
use Pablo\Support\PabloError;

/**
 * Renders the agents from their bundled .md.template sources + the project
 * configs for a runtime. Everything goes under ~/.pablo/runtime
 * (PABLO_RUNTIME_DIR) — the sources may sit inside the read-only binary:
 *  - always, the shared render (AgentTemplates::renderedDir()):
 *    opencode/agents/<agent>.md plus the bundled commands and skills
 *    extracted to opencode/commands/ and opencode/skills/ (a symlink cannot
 *    point into the binary);
 *  - then whatever the runtime translates on top of it, in its own directory
 *    (RuntimeAgentFilesInterface::generate()).
 *
 * Shared by `pablo system:generate-agents` and `pablo system:install`, which
 * both report each file as created, updated or unchanged on an InstallLog.
 */
final class AgentFilesGenerator
{
    public function __construct(
        private readonly AgentTemplates $agentTemplates,
        private readonly Config $projectsLoader,
    ) {
    }

    /**
     * @param string|null $agentsDir  template sources (default: the bundled opencode/agents)
     * @param string|null $outputDir  the shared render (default: AgentTemplates::renderedDir())
     * @param string|null $runtimeDir the runtime's own files (default: its generatedDir())
     *
     * @throws AgentTemplateError on a broken template
     * @throws PabloError         when a file cannot be written
     */
    public function generate(AgentRuntimeInterface $runtime, InstallLog $log, ?string $agentsDir = null, ?string $outputDir = null, ?string $runtimeDir = null): void
    {
        $agentsDir ??= $this->agentTemplates->templatesDir();
        $outputDir ??= AgentTemplates::renderedDir();

        $projectsDir = $this->projectsLoader->projectsDir();
        if (!is_dir($projectsDir)) {
            $log->info('No projects directory found — generating agents with all providers enabled.');
        }
        $enabledProviders = $this->agentTemplates->enabledProviders($projectsDir);

        // Render everything first: a broken template writes nothing.
        $agents = [];
        foreach (AgentTemplates::AGENT_NAMES as $name) {
            $agents[$outputDir.'/agents/'.$name.'.md'] = $this->agentTemplates->render($agentsDir, $name, $enabledProviders);
        }

        $log->section('agent definitions', \dirname($outputDir), $runtime->label().' · providers: '.implode(', ', $enabledProviders ?: ['(none)']));
        foreach ($agents as $path => $content) {
            $log->file('agent', $path, Files::write($path, $content));
        }
        // The commands and skills ship as-is; extract them next to the agents
        // so every runtime links to real files on disk.
        foreach (Files::list(AgentTemplates::commandsDirFor($agentsDir), '*.md') as $source) {
            $dest = $outputDir.'/commands/'.basename($source);
            $log->file('command', $dest, Files::copy($source, $dest));
        }
        foreach (Files::list(AgentTemplates::skillsDirFor($agentsDir), '*', dirsOnly: true) as $source) {
            $dest = $outputDir.'/skills/'.basename($source);
            $log->file('skill', $dest, Files::copy($source, $dest));
        }

        foreach ($runtime->agentFiles()->generate($agentsDir, $enabledProviders, $runtimeDir) as $path => $content) {
            $log->file($runtime->label().' file', $path, Files::write($path, $content));
        }
        $log->close();
    }
}
