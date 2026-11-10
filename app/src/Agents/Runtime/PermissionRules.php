<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime;

use Pablo\Agents\AgentTemplateError;

/**
 * An agent's `permissions:` frontmatter list (the shared lists in
 * opencode/agents/shared/*.frontmatter.md), validated and split into what
 * each runtime translates: whole actions allowed/denied (`resource: '*'`)
 * and shell patterns allowed/denied. Only `shell` may target a pattern.
 *
 * Every list is deduplicated and keeps the order the rules first name it in.
 */
final readonly class PermissionRules
{
    /**
     * @param list<string> $allowedActions actions allowed whole
     * @param list<string> $deniedActions  actions denied whole
     * @param list<string> $allowedShell   shell patterns allowed
     * @param list<string> $deniedShell    shell patterns denied
     */
    private function __construct(
        public array $allowedActions,
        public array $deniedActions,
        public array $allowedShell,
        public array $deniedShell,
    ) {
    }

    /**
     * @param array<string, mixed> $front        the agent's parsed frontmatter
     * @param list<string>         $knownActions the actions the runtime can translate
     * @param string               $runtime      the runtime's display name, for errors
     *
     * @throws AgentTemplateError
     */
    public static function fromFrontmatter(array $front, string $agent, array $knownActions, string $runtime): self
    {
        $rules = $front['permissions'] ?? [];
        if (!\is_array($rules)) {
            throw new AgentTemplateError("{$agent}: permissions must be a list");
        }

        $actions = ['allow' => [], 'deny' => []];
        $shell = ['allow' => [], 'deny' => []];
        foreach ($rules as $i => $rule) {
            if (!\is_array($rule)) {
                throw new AgentTemplateError("{$agent}: permission #{$i} is not a mapping");
            }
            $action = (string) ($rule['action'] ?? '');
            $resource = (string) ($rule['resource'] ?? '*');
            $effect = (string) ($rule['effect'] ?? '');
            if (!\in_array($action, $knownActions, true)) {
                throw new AgentTemplateError("{$agent}: permission action '{$action}' has no {$runtime} equivalent");
            }
            if ('allow' !== $effect && 'deny' !== $effect) {
                throw new AgentTemplateError("{$agent}: permission effect '{$effect}' is not allow/deny");
            }
            if ('*' === $resource) {
                $actions[$effect][$action] = true;
                continue;
            }
            if ('shell' !== $action) {
                throw new AgentTemplateError("{$agent}: only shell permissions may target a pattern ('{$action}' → '{$resource}')");
            }
            $shell[$effect][$resource] = true;
        }

        return new self(
            self::keys($actions['allow']),
            self::keys($actions['deny']),
            self::keys($shell['allow']),
            self::keys($shell['deny']),
        );
    }

    /**
     * Actions allowed whole and not denied — a deny wins whatever the rule order.
     *
     * @return list<string>
     */
    public function offeredActions(): array
    {
        return array_values(array_diff($this->allowedActions, $this->deniedActions));
    }

    /**
     * @param array<array-key, true> $set
     *
     * @return list<string>
     */
    private static function keys(array $set): array
    {
        return array_map(strval(...), array_keys($set));
    }
}
