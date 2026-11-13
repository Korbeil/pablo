<?php

declare(strict_types=1);

namespace Pablo\Tests\Dispatch;

use Pablo\Dispatch\Stamps;
use Pablo\Tests\TempDir;
use PHPUnit\Framework\TestCase;

final class StampsTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = TempDir::create('stamps');
        putenv('PABLO_STAMPS_DIR='.$this->tmp.'/stamps');
    }

    protected function tearDown(): void
    {
        putenv('PABLO_STAMPS_DIR');
        exec('rm -rf '.escapeshellarg($this->tmp));
    }

    public function testWriteCreatesTheDirectoryAndReadsBack(): void
    {
        $stamps = new Stamps();
        $stamps->write('acme', 'poll', 1_780_000_000.5, 37.4);

        $this->assertSame($this->tmp.'/stamps/acme.poll', Stamps::path('acme', 'poll'));
        $this->assertSame(['ran_at' => 1_780_000_000.5, 'duration_s' => 37.4], json_decode((string) file_get_contents(Stamps::path('acme', 'poll')), true));
        $stamp = $stamps->readStamp('acme', 'poll');
        $this->assertNotNull($stamp);
        $this->assertSame(1_780_000_000.5, $stamp->ranAt);
        $this->assertSame(37.4, $stamp->durationS);
    }

    public function testWriteIsBestEffort(): void
    {
        file_put_contents($this->tmp.'/stamps', 'a file where the stamps directory should be');

        (new Stamps())->write('acme', 'poll', 1.0, 2.0);

        $this->assertNull((new Stamps())->readStamp('acme', 'poll'));
    }

    public function testMissingJunkAndLegacyBareFloatStampsReadAsNone(): void
    {
        $stamps = new Stamps();
        $this->assertNull($stamps->readStamp('acme', 'poll'));

        mkdir($this->tmp.'/stamps', 0o777, true);
        // The pre-JSON format: read as no stamp, so the job runs once more and rewrites it.
        file_put_contents(Stamps::path('acme', 'poll'), '1780000000');
        $this->assertNull($stamps->readStamp('acme', 'poll'));

        file_put_contents(Stamps::path('acme', 'poll'), '{"ran_at": "soon"}');
        $this->assertNull($stamps->readStamp('acme', 'poll'));
    }
}
