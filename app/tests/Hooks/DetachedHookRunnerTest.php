<?php

declare(strict_types=1);

namespace Pablo\Tests\Hooks;

use Pablo\Hooks\DetachedHookRunner;
use Pablo\Tests\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * The one integration test that spawns a real (harmless) process: the hook
 * writes its environment and cwd to a temp file, detached from PHPUnit.
 */
final class DetachedHookRunnerTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = TempDir::create('hook');
        mkdir($this->tmp.'/wt', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (['out', 'log', 'go'] as $f) {
            @unlink($this->tmp.'/'.$f);
        }
        @rmdir($this->tmp.'/wt');
        @rmdir($this->tmp);
    }

    public function testRunsTheCommandDetachedWithItsEnvironment(): void
    {
        $out = $this->tmp.'/out';
        $runner = new DetachedHookRunner($this->tmp.'/log');

        $go = $this->tmp.'/go';
        // The hook holds until the test creates $go (60s at most, so a
        // non-detached run() cannot hang the suite): no wall-clock threshold,
        // which a loaded machine or a parallel run would break.
        $runner->run(
            'for i in $(seq 600); do [ -e '.escapeshellarg($go).' ] && break; sleep 0.1; done; printf "%s|%s|%s\n" "$PABLO_EVENT" "$PABLO_TO" "$PWD" > '.escapeshellarg($out.'.tmp').' && mv '.escapeshellarg($out.'.tmp').' '.escapeshellarg($out).'; echo logged',
            ['PABLO_EVENT' => 'transition', 'PABLO_TO' => "it's ci-red"],
            $this->tmp.'/wt',
        );
        // Detached: run() returned while the command still waits for $go.
        $this->assertFileDoesNotExist($out);
        touch($go);

        $deadline = microtime(true) + 10;
        while (!is_file($out) && microtime(true) < $deadline) {
            usleep(20_000);
        }
        $this->assertFileExists($out);
        $this->assertSame("transition|it's ci-red|{$this->tmp}/wt\n", file_get_contents($out));

        $deadline = microtime(true) + 5;
        while (!str_contains((string) @file_get_contents($this->tmp.'/log'), 'logged') && microtime(true) < $deadline) {
            usleep(20_000);
        }
        $this->assertStringContainsString('logged', (string) file_get_contents($this->tmp.'/log'));
    }
}
