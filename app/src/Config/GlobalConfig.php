<?php

declare(strict_types=1);

namespace Pablo\Config;

use Pablo\Support\PabloError;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * PABLO-wide (cross-project) configuration.
 *
 * Lives at ~/.pablo/config.yaml and is resolved at runtime — never at
 * container compile time — so the PABLO_CONFIG override and HOME are read
 * fresh on every call (same reason Store resolves its root in its
 * constructor). Holds the global defaults the project loader falls back to
 * per key.
 *
 * Standalone on purpose: Config depends on this, so this must not depend
 * back on Config.
 */
final class GlobalConfig
{
    public function configPath(?string $override = null): string
    {
        if (null !== $override && '' !== $override) {
            return $override;
        }
        $env = getenv('PABLO_CONFIG');
        if (false !== $env && '' !== $env) {
            return $env;
        }

        return (getenv('HOME') ?: '~').'/.pablo/config.yaml';
    }

    /**
     * The per-key defaults the project loader merges over. Returns empty
     * sections when the config file is absent, so projects can still rely
     * on their own explicit values.
     *
     * @return array<string, mixed>
     */
    public function defaults(?string $path = null): array
    {
        $path ??= $this->configPath();
        if (!is_file($path)) {
            return ['sync' => [], 'state_polling' => [], 'review' => [], 'ci' => [], 'branch_prefix' => null];
        }
        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new PabloError(basename($path).': invalid YAML: '.$e->getMessage());
        }
        if (!\is_array($data)) {
            throw new PabloError(basename($path).': expected a mapping at top level');
        }

        return [
            'sync' => \is_array($data['sync'] ?? null) ? $data['sync'] : [],
            'state_polling' => \is_array($data['state_polling'] ?? null) ? $data['state_polling'] : [],
            'review' => \is_array($data['review'] ?? null) ? $data['review'] : [],
            'ci' => \is_array($data['ci'] ?? null) ? $data['ci'] : [],
            'default_model' => isset($data['default_model']) ? (string) $data['default_model'] : null,
            'pr_description_locale' => isset($data['pr_description_locale']) ? (string) $data['pr_description_locale'] : null,
            'branch_prefix' => isset($data['branch_prefix']) ? (string) $data['branch_prefix'] : null,
            'agent_backend' => isset($data['agent_backend']) ? (string) $data['agent_backend'] : null,
        ];
    }
}
