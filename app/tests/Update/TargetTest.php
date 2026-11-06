<?php

declare(strict_types=1);

namespace Pablo\Tests\Update;

use Pablo\Support\PabloError;
use Pablo\Update\Target;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TargetTest extends TestCase
{
    /** @return iterable<array{string, string, string}> */
    public static function platforms(): iterable
    {
        yield ['Linux', 'x86_64', 'linux-x86_64'];
        yield ['Linux', 'amd64', 'linux-x86_64'];
        yield ['Linux', 'aarch64', 'linux-aarch64'];
        yield ['Darwin', 'arm64', 'macos-arm64'];
        yield ['Darwin', 'x86_64', 'macos-x86_64'];
    }

    #[DataProvider('platforms')]
    public function testMapsPlatformsToReleaseTargets(string $os, string $machine, string $target): void
    {
        $this->assertSame($target, Target::for($os, $machine));
        $this->assertContains($target, Target::TARGETS);
    }

    public function testAnUnsupportedPlatformIsAClearError(): void
    {
        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('no PABLO binary for Linux on riscv64');

        Target::for('Linux', 'riscv64');
    }

    /** The names the release workflow publishes. */
    public function testTargetsMatchTheReleaseWorkflow(): void
    {
        $workflow = (string) file_get_contents(\dirname(__DIR__, 3).'/.github/workflows/binary.yml');

        foreach (Target::TARGETS as $target) {
            $this->assertStringContainsString('target: '.$target, $workflow);
        }
        $this->assertSame('pablo-macos-arm64', Target::asset('macos-arm64'));
    }
}
