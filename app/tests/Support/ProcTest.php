<?php

declare(strict_types=1);

namespace Pablo\Tests\Support;

use Pablo\Support\Proc;
use PHPUnit\Framework\TestCase;

final class ProcTest extends TestCase
{
    public function testEnvForAcliPointsDbusAwayFromKeyring(): void
    {
        $env = Proc::envFor(['acli', 'jira', 'auth', 'status']);

        $this->assertSame(
            ['DBUS_SESSION_BUS_ADDRESS' => 'unix:path='.sys_get_temp_dir().'/pablo-nokeyring'],
            $env,
        );
    }

    public function testEnvForOtherClisKeepsInheritedSessionBus(): void
    {
        foreach ([[], ['gh', '--version'], ['orca', 'status'], ['linear', 'auth', 'status']] as $argv) {
            $this->assertSame([], Proc::envFor($argv), json_encode($argv) ?: '[]');
        }
    }
}
