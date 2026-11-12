<?php

declare(strict_types=1);

namespace Pablo\Tests\Install\Scheduler;

use Pablo\Agents\Ade\Detached;
use Pablo\Install\Scheduler\SchedulerPath;
use PHPUnit\Framework\TestCase;

final class SchedulerPathTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-schedpath-'.bin2hex(random_bytes(4));
        mkdir($this->tmp, 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmp));
    }

    public function testFoundCliDirectoriesComeBeforeTheSystemOnes(): void
    {
        $nvm = $this->tool('nvm/bin', 'node');
        $shim = $this->tool('shim', 'cli-a');
        $this->tool('shadowed', 'cli-a');

        $path = SchedulerPath::build(
            '/home/u',
            "{$shim}:/usr/bin:{$this->tmp}/shadowed:{$nvm}",
            ['cli-a', 'node', 'missing'],
            ['~/extra', '/opt/x/bin/'],
            'Linux',
        );

        $this->assertSame(
            "/home/u/extra:/opt/x/bin:/home/u/.local/bin:/home/u/bin:{$shim}:{$nvm}:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/snap/bin",
            $path,
        );
    }

    public function testDuplicatesSystemDirectoriesAndRelativeEntriesAreDropped(): void
    {
        $dir = $this->tool('bin', 'tool');
        $this->tool('rel', 'other');

        $path = SchedulerPath::build('/home/u', "rel:{$dir}:{$dir}", ['tool', 'tool', 'other'], [$dir, '~'], 'Darwin');

        $this->assertSame(
            "{$dir}:/home/u:/home/u/.local/bin:/home/u/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin",
            $path,
        );
    }

    public function testTheServicesFindTheDetachedSessionHelpers(): void
    {
        // macOS has no setsid: a detached run falls back to perl, so the
        // services' PATH must hold its directory too.
        foreach (['setsid', 'perl', ...Detached::SESSION_HELPERS] as $helper) {
            $this->assertContains($helper, SchedulerPath::TOOLS);
        }
    }

    public function testAnAbsoluteCommandContributesItsDirectory(): void
    {
        $dir = $this->tool('abs', 'orca-ide');

        $this->assertSame($dir, SchedulerPath::locate($dir.'/orca-ide', ''));
        $this->assertNull(SchedulerPath::locate($dir.'/nope', ''));
        $this->assertNull(SchedulerPath::locate('', $dir));
    }

    public function testANonExecutableFileIsNotACommand(): void
    {
        mkdir($this->tmp.'/plain');
        file_put_contents($this->tmp.'/plain/tool', '');

        $this->assertNull(SchedulerPath::locate('tool', $this->tmp.'/plain'));
    }

    private function tool(string $dir, string $name): string
    {
        $dir = $this->tmp.'/'.$dir;
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        file_put_contents($dir.'/'.$name, "#!/bin/sh\n");
        chmod($dir.'/'.$name, 0o755);

        return $dir;
    }
}
