<?php

declare(strict_types=1);

namespace Pablo\Config;

use Pablo\Support\PabloError;
use Pablo\Support\PabloPaths;
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

        return PabloPaths::pabloPath('config.yaml', 'PABLO_CONFIG');
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
            return ['sync' => [], 'state_polling' => [], 'main_update' => [], 'review' => [], 'ci' => [], 'testing' => ['enabled' => true], 'branch_prefix' => null];
        }
        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new PabloError(basename($path).': invalid YAML: '.$e->getMessage());
        }
        if (!\is_array($data)) {
            throw new PabloError(basename($path).': expected a mapping at top level');
        }

        $byType = $data['default_model_by_type'] ?? null;
        if (null !== $byType && !\is_array($byType)) {
            throw new PabloError(basename($path).': default_model_by_type must be a mapping of project type to model');
        }

        return [
            'sync' => \is_array($data['sync'] ?? null) ? $data['sync'] : [],
            'state_polling' => \is_array($data['state_polling'] ?? null) ? $data['state_polling'] : [],
            'main_update' => \is_array($data['main_update'] ?? null) ? $data['main_update'] : [],
            'review' => \is_array($data['review'] ?? null) ? $data['review'] : [],
            'ci' => \is_array($data['ci'] ?? null) ? $data['ci'] : [],
            'testing' => \is_array($data['testing'] ?? null) ? $data['testing'] : ['enabled' => true],
            'default_model' => isset($data['default_model']) ? (string) $data['default_model'] : null,
            'default_model_by_type' => \is_array($byType) ? $byType : null,
            'pr_description_locale' => isset($data['pr_description_locale']) ? (string) $data['pr_description_locale'] : null,
            'branch_prefix' => isset($data['branch_prefix']) ? (string) $data['branch_prefix'] : null,
            'agent_backend' => isset($data['agent_backend']) ? (string) $data['agent_backend'] : null,
            'agent_runtime' => isset($data['agent_runtime']) ? (string) $data['agent_runtime'] : null,
            'claude_code' => \is_array($data['claude_code'] ?? null) ? $data['claude_code'] : [],
            'dashboard_port' => $data['dashboard_port'] ?? null,
        ];
    }

    /**
     * The port of the dashboard (`pablo web`, and its service): dashboard_port,
     * else $default. Read per call, like every other key.
     *
     * @throws PabloError when dashboard_port is not a port number
     */
    public function dashboardPort(int $default): int
    {
        $port = $this->defaults()['dashboard_port'] ?? null;
        if (null === $port) {
            return $default;
        }
        if (!self::isPort($port)) {
            throw new PabloError('config.yaml: dashboard_port must be a port number (1-65535), got '.var_export($port, true));
        }

        return (int) $port;
    }

    /** A TCP port: an integer (or digits) between 1 and 65535. */
    public static function isPort(mixed $value): bool
    {
        if (\is_int($value)) {
            return $value >= 1 && $value <= 65535;
        }

        return \is_string($value) && 1 === preg_match('/^\d+$/', $value) && (int) $value >= 1 && (int) $value <= 65535;
    }
}
