<?php

declare(strict_types=1);

namespace Pablo\Tests\Config;

use Pablo\Config\ConfigValues;
use Pablo\Support\PabloError;
use PHPUnit\Framework\TestCase;

final class ConfigValuesTest extends TestCase
{
    public function testDottedPaths(): void
    {
        $data = ['repo' => ['path' => '/src', 'empty' => null]];

        $this->assertSame('/src', ConfigValues::required($data, 'repo.path', 'p.yaml'));
        $this->assertNull(ConfigValues::required($data, 'repo.empty', 'p.yaml'), 'a key set to null is present');
        $this->assertNull(ConfigValues::optional($data, 'repo.primary_remote'));
        $this->assertNull(ConfigValues::optional($data, 'repo.path.deeper'));
    }

    public function testRequiredNamesTheFileAndKey(): void
    {
        $this->expectException(PabloError::class);
        $this->expectExceptionMessage("p.yaml: missing required key 'repo.path'");

        ConfigValues::required(['repo' => []], 'repo.path', 'p.yaml');
    }

    public function testMergedFallsBackToTheDefaults(): void
    {
        $this->assertSame(5, ConfigValues::merged(['ci' => ['other' => 1]], ['ci' => ['x' => 5]], 'ci', 'x'));
        $this->assertFalse(ConfigValues::merged(['ci' => ['x' => false]], ['ci' => ['x' => true]], 'ci', 'x'));
    }
}
