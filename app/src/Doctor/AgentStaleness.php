<?php

declare(strict_types=1);

namespace Pablo\Doctor;

use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\AgentRuntimeRegistry;

/**
 * system:doctor's agents-staleness check, for the selected runtime: each
 * file `pablo system:install` generated or linked is compared, in memory (no
 * writes), against what `system:generate-agents` would render now — see each
 * runtime's RuntimeAgentFilesInterface::staleness(). Symlinks are resolved;
 * broken links and files PABLO does not manage are reported distinctly.
 */
final class AgentStaleness
{
    /**
     * @param callable|null $check optional full-result override fn(): list<AgentFileStatus>
     */
    public function __construct(
        private readonly AgentTemplates $agentTemplates,
        private $check = null,
        private ?AgentRuntimeRegistry $runtimes = null,
    ) {
    }

    /**
     * @param string|null $agentsDir override of the repo template dir (tests)
     *
     * @return list<AgentFileStatus>
     */
    public function check(?string $agentsDir = null): array
    {
        if (null !== $this->check) {
            return ($this->check)();
        }
        // An unknown runtime name is reported by the doctor itself.
        $runtime = ($this->runtimes ??= new AgentRuntimeRegistry())->resolveOrDefault();

        return $runtime->agentFiles()->staleness(
            $agentsDir ?? $this->agentTemplates->templatesDir(),
            $this->agentTemplates->enabledProviders(),
        );
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
}
