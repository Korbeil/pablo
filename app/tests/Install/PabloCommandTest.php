<?php

declare(strict_types=1);

namespace Pablo\Tests\Install;

use Pablo\Install\PabloCommand;
use PHPUnit\Framework\TestCase;

final class PabloCommandTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-cmd-'.bin2hex(random_bytes(4));
        mkdir($this->tmp.'/bin', 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmp));
    }

    public function testTheBinaryCopiesItselfOverAnOldShim(): void
    {
        file_put_contents($this->tmp.'/pablo-linux-x86_64', "BINARY\n");
        file_put_contents($this->tmp.'/bin/pablo', "#!/usr/bin/env bash\nexec php '/old/clone/app/bin/pablo' \"\$@\"\n");

        $message = PabloCommand::place($this->tmp.'/bin/pablo', $this->tmp.'/pablo-linux-x86_64', '/unused');

        $this->assertStringContainsString('copied from', $message);
        $this->assertSame("BINARY\n", file_get_contents($this->tmp.'/bin/pablo'));
        $this->assertTrue(is_executable($this->tmp.'/bin/pablo'));
    }

    public function testTheBinaryAlreadyInPlaceIsLeftAsIs(): void
    {
        file_put_contents($this->tmp.'/bin/pablo', "BINARY\n");

        $this->assertStringContainsString('is this binary', PabloCommand::place($this->tmp.'/bin/pablo', $this->tmp.'/bin/pablo', '/unused'));
    }

    public function testACheckoutWritesAShimItRecognises(): void
    {
        PabloCommand::place($this->tmp.'/bin/pablo', '', '/src/pablo/app/bin/pablo');

        $this->assertSame('/src/pablo', PabloCommand::shimCheckout($this->tmp.'/bin/pablo'));
        $this->assertTrue(PabloCommand::removeIfOurs($this->tmp.'/bin/pablo', ''));
    }

    public function testAnUnrelatedPabloIsNotRemoved(): void
    {
        file_put_contents($this->tmp.'/bin/pablo', "#!/bin/sh\necho hi\n");

        $this->assertNull(PabloCommand::shimCheckout($this->tmp.'/bin/pablo'));
        $this->assertFalse(PabloCommand::removeIfOurs($this->tmp.'/bin/pablo', $this->tmp.'/elsewhere'));
        $this->assertFileExists($this->tmp.'/bin/pablo');
    }

    public function testTheRunningBinaryIsRemoved(): void
    {
        file_put_contents($this->tmp.'/bin/pablo', "BINARY\n");

        $this->assertTrue(PabloCommand::removeIfOurs($this->tmp.'/bin/pablo', $this->tmp.'/bin/pablo'));
    }
}
