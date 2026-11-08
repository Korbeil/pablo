<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime;

use Pablo\Agents\AgentTemplateError;
use Pablo\Doctor\AgentFileStatus;
use Pablo\Support\PabloError;

/**
 * A runtime's generated agent definitions and the links that expose them.
 *
 * Every runtime starts from the shared render (AgentTemplates::renderedDir():
 * agents/, commands/, skills/ — always written by system:generate-agents);
 * this adds the runtime's own translation under generatedDir(), says which
 * links system:install creates under userConfigDir(), and checks them for
 * system:doctor.
 */
interface RuntimeAgentFilesInterface
{
    /** Where this runtime's own generated files go (~/.pablo/runtime/<runtime>). */
    public function generatedDir(): string;

    /** The runtime's user config directory the links go into (~/.claude, …). */
    public function userConfigDir(): string;

    /**
     * The runtime's own files rendered from the template sources, keyed by
     * path under $outputDir (default generatedDir()); empty when the shared
     * render is all it needs.
     *
     * @param list<string> $providers
     *
     * @return array<string, string> path => content
     *
     * @throws AgentTemplateError
     */
    public function generate(string $agentsDir, array $providers, ?string $outputDir = null): array;

    /**
     * The links system:install creates, link path => target, from the shared
     * render in $renderedDir and this runtime's generated files.
     *
     * @return array<string, string>
     *
     * @throws PabloError when a file the links need was not generated
     */
    public function links(string $renderedDir): array;

    /**
     * Every link path an install of this runtime may have created for the
     * agents, commands and skills sourced from $agentsDir (system:uninstall
     * removes those that are PABLO's).
     *
     * @return list<string>
     */
    public function installedPaths(string $agentsDir): array;

    /**
     * The installed files compared against a fresh in-memory render.
     *
     * @param list<string> $providers
     *
     * @return list<AgentFileStatus>
     */
    public function staleness(string $agentsDir, array $providers): array;

    /**
     * What the user still has to do once the links are in place (shown by
     * system:install).
     *
     * @return list<string>
     */
    public function installNotes(): array;

    /**
     * Remove what older PABLO layouts left behind.
     *
     * @return list<string> what was removed, one line each
     */
    public function cleanupLegacy(): array;
}
