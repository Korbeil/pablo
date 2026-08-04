<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\Report;

use Pablo\Command\Report\SlackCommand;
use Pablo\Store\Store;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SlackCommandTest extends TestCase
{
    private string $tmp;
    private Store $store;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-slack-'.uniqid();
        mkdir($this->tmp, 0o777, true);
        $this->store = new Store($this->tmp.'/state');
    }

    protected function tearDown(): void
    {
        // no global seams used
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new SlackCommand($this->store));
    }

    public function testNoStatePrintsBothBlocksWithDivider(): void
    {
        $tester = $this->tester();
        $tester->execute([]);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $out = $tester->getDisplay();
        $this->assertStringContainsString('No PRs waiting for review right now 🎉', $out);
        $this->assertStringContainsString('Nothing needs testing right now 🎉', $out);
        $this->assertStringContainsString('―――― review above · QA below ――――', $out);
    }

    public function testSingleStatePrintsOneBlock(): void
    {
        $tester = $this->tester();
        $tester->execute(['state' => 'waiting-review']);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('No PRs waiting for review right now 🎉', $tester->getDisplay());
    }

    public function testUnknownStateRejected(): void
    {
        $tester = $this->tester();
        $tester->execute(['state' => 'bogus']);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }
}
