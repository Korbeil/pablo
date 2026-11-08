<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents;

use Pablo\Agents\OrcaCli;
use PHPUnit\Framework\TestCase;

final class OrcaCliTest extends TestCase
{
    private string $tmp;
    private string|false $override;
    private string|false $home;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-orcacli-'.bin2hex(random_bytes(4));
        mkdir($this->tmp.'/home', 0o777, true);
        mkdir($this->tmp.'/bin', 0o777, true);
        $this->override = getenv('PABLO_ORCA_BIN');
        $this->home = getenv('HOME');
        putenv('PABLO_ORCA_BIN');
        putenv('HOME='.$this->tmp.'/home');
    }

    protected function tearDown(): void
    {
        putenv(false === $this->override ? 'PABLO_ORCA_BIN' : 'PABLO_ORCA_BIN='.$this->override);
        putenv(false === $this->home ? 'HOME' : 'HOME='.$this->home);
        exec('rm -rf '.escapeshellarg($this->tmp));
    }

    public function testOrcaIdeWinsOverAnAmbiguousOrca(): void
    {
        $this->assertSame('/x/orca-ide', OrcaCli::find($this->which(['orca-ide' => '/x/orca-ide', 'orca' => '/usr/bin/orca'])));
    }

    /** What the reporter's machine had: GNOME's screen reader as the only `orca`. */
    public function testGnomesScreenReaderIsNeverTakenForOrca(): void
    {
        $reader = $this->tool('orca', "#!/usr/bin/python3\n# Orca screen reader\nimport orca\n");

        $this->assertTrue(OrcaCli::isScreenReader($reader));
        $this->assertNull(OrcaCli::find($this->which(['orca' => $reader])));
        $this->assertSame('orca', OrcaCli::command($this->which(['orca' => $reader])), 'callers get a command that fails cleanly');
    }

    public function testTheLinuxShimIsUsedWhenOrcaIdeIsNotOnThePath(): void
    {
        $shim = $this->tmp.'/home/'.OrcaCli::LINUX_SHIM;
        mkdir(\dirname($shim), 0o777, true);
        file_put_contents($shim, "#!/usr/bin/env bash\nexec '/opt/Orca/resources/bin/orca-ide' \"\$@\"\n");
        chmod($shim, 0o755);

        $this->assertSame($shim, OrcaCli::find($this->which([])));
    }

    public function testAnOrcaThatIsNotTheScreenReaderIsAccepted(): void
    {
        $orca = $this->tool('orca', "#!/usr/bin/env bash\nexec orca-ide \"\$@\"\n");

        $this->assertFalse(OrcaCli::isScreenReader($orca));
        $this->assertSame($orca, OrcaCli::find($this->which(['orca' => $orca])));
    }

    public function testNothingInstalled(): void
    {
        $this->assertNull(OrcaCli::find($this->which([])));
    }

    public function testTheOverrideIsTakenAsGivenUnlessVerified(): void
    {
        putenv('PABLO_ORCA_BIN=orca');

        $this->assertSame('orca', OrcaCli::find($this->which([])));
        $this->assertNull(OrcaCli::find($this->which([]), verifyOverride: true), 'the doctor reports a missing one');
        $this->assertSame('/y/orca', OrcaCli::find($this->which(['orca' => '/y/orca']), verifyOverride: true));
    }

    /**
     * @param array<string, string> $found
     *
     * @return callable(string): ?string
     */
    private function which(array $found): callable
    {
        return static fn (string $bin): ?string => $found[$bin] ?? null;
    }

    private function tool(string $name, string $content): string
    {
        $path = $this->tmp.'/bin/'.$name;
        file_put_contents($path, $content);
        chmod($path, 0o755);

        return $path;
    }
}
