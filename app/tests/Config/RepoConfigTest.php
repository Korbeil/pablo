<?php

declare(strict_types=1);

namespace Pablo\Tests\Config;

use Pablo\Config\RepoConfig;
use Pablo\Support\PabloError;
use PHPUnit\Framework\TestCase;

final class RepoConfigTest extends TestCase
{
    public function testReadsRepoAndCheckoutKeys(): void
    {
        $repo = RepoConfig::fromYaml([
            'repo' => ['path' => '/src/acme', 'primary_branch' => 'main'],
            'worktrees_root' => '/wt/acme',
            'startup_script' => '/src/acme/setup.sh',
        ], 'p.yaml');

        $this->assertSame('/src/acme', $repo->path);
        $this->assertSame('main', $repo->primaryBranch);
        $this->assertSame('origin', $repo->primaryRemote);
        $this->assertSame('/wt/acme', $repo->worktreesRoot);
        $this->assertSame('/src/acme/setup.sh', $repo->startupScript);
    }

    public function testMissingPrimaryBranch(): void
    {
        $this->expectException(PabloError::class);
        $this->expectExceptionMessage("p.yaml: missing required key 'repo.primary_branch'");

        RepoConfig::fromYaml(['repo' => ['path' => '/src/acme']], 'p.yaml');
    }
}
