<?php

declare(strict_types=1);

namespace Pablo\Tests\Config;

use Pablo\Config\TrackerConfig;
use Pablo\Support\PabloError;
use PHPUnit\Framework\TestCase;

final class TrackerConfigTest extends TestCase
{
    public function testReadsTheIssueTrackerSection(): void
    {
        $tracker = TrackerConfig::fromYaml(['issue_tracker' => [
            'provider' => 'jira', 'identity' => 'me', 'project_key' => 'ACME', 'site' => 'acme.atlassian.net', 'repo' => '',
        ]], 'p.yaml');

        $this->assertSame('jira', $tracker->provider);
        $this->assertSame('me', $tracker->identity);
        $this->assertSame('ACME', $tracker->projectKey);
        $this->assertSame('acme.atlassian.net', $tracker->site);
        $this->assertNull($tracker->repo, 'an empty issue_tracker.repo means the project repo');
    }

    public function testMissingProjectKey(): void
    {
        $this->expectException(PabloError::class);
        $this->expectExceptionMessage("p.yaml: missing required key 'issue_tracker.project_key'");

        TrackerConfig::fromYaml(['issue_tracker' => ['provider' => 'github', 'identity' => 'me']], 'p.yaml');
    }
}
