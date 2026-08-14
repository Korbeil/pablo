<?php

declare(strict_types=1);

namespace Pablo\Tests;

/**
 * Seeds PABLO's global config (~/.pablo/config.yaml equivalent) for tests by
 * pointing the PABLO_CONFIG env override at a throwaway file, and resets it
 * afterwards. Mirrors how the production loader resolves GlobalConfig::path().
 */
trait UsesGlobalConfig
{
    protected string $configPath = '';

    protected function writeGlobalConfig(string $yaml): void
    {
        $this->configPath = sys_get_temp_dir().'/pablo-config-'.uniqid().'.yaml';
        file_put_contents($this->configPath, $yaml);
        putenv('PABLO_CONFIG='.$this->configPath);
    }

    protected function unsetGlobalConfig(): void
    {
        putenv('PABLO_CONFIG');
        if ('' !== $this->configPath && is_file($this->configPath)) {
            unlink($this->configPath);
        }
        $this->configPath = '';
    }
}
