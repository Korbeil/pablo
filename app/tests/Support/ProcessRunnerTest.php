<?php

declare(strict_types=1);

namespace Pablo\Tests\Support;

use Pablo\Support\ProcessRunner;
use PHPUnit\Framework\TestCase;

/**
 * Ported from the former ProcTest: env tweaks now live inside ProcessRunner
 * (private), so only the public run/probe contracts are covered here.
 */
final class ProcessRunnerTest extends TestCase
{
    public function testRunReturnsStdout(): void
    {
        $out = (new ProcessRunner())->run(['echo', 'hello pablo']);

        $this->assertSame("hello pablo\n", $out);
    }

    public function testRunCheckThrowsWithStderrDetail(): void
    {
        $runner = new ProcessRunner();
        $this->expectException(\Pablo\Support\PabloError::class);
        $this->expectExceptionMessage('boom');
        $runner->run(['sh', '-c', 'echo boom >&2; exit 3']);
    }

    public function testRunWithoutCheckReturnsEmptyOutput(): void
    {
        $out = (new ProcessRunner())->run(['sh', '-c', 'exit 4'], check: false);

        $this->assertSame('', $out);
    }

    public function testProbeReturnsExitCodeAndCombinedOutput(): void
    {
        $probe = (new ProcessRunner())->probe(['sh', '-c', 'echo out; echo err >&2; exit 3']);

        $this->assertSame(3, $probe->exitCode);
        $this->assertStringContainsString('out', $probe->output);
        $this->assertStringContainsString('err', $probe->output);
    }

    public function testRunParallelPreservesOrder(): void
    {
        $outs = (new ProcessRunner())->runParallel([
            ['echo', 'one'],
            ['echo', 'two'],
            ['echo', 'three'],
        ]);

        $this->assertSame(["one\n", "two\n", "three\n"], $outs);
    }
}
