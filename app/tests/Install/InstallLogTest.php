<?php

declare(strict_types=1);

namespace Pablo\Tests\Install;

use Pablo\Install\InstallLog;
use Pablo\Support\WriteStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class InstallLogTest extends TestCase
{
    public function testChangedFilesAreListedAndUnchangedOnesCollapsed(): void
    {
        $output = new BufferedOutput();
        $log = new InstallLog($output);

        $log->section('agent definitions', '/r', 'OpenCode');
        $log->file('agent', '/r/agents/a.md', WriteStatus::Unchanged);
        $log->file('agent', '/r/agents/b.md', WriteStatus::Updated);
        $log->file('agent', '/r/agents/c.md', WriteStatus::Unchanged);
        $log->file('skill', '/r/skills/s', WriteStatus::Unchanged);
        $log->file('command', '/elsewhere/x.md', WriteStatus::Created);
        $log->info('a step');
        $log->section('pablo command');
        $log->file('command', '/bin/pablo', WriteStatus::Unchanged);
        $log->summary();

        $this->assertSame(<<<'TXT'

            agent definitions (/r · OpenCode)
              ~ updated  agents/b.md
              + created  /elsewhere/x.md
              = 2 agents, 1 skill unchanged
            ➜ a step

            pablo command
              = unchanged /bin/pablo

            2 changed (1 created, 1 updated), 4 unchanged.

            TXT, $output->fetch());
    }

    public function testVerboseListsEachUnchangedFile(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $log = new InstallLog($output);

        $log->section('links');
        $log->file('link', '/a', WriteStatus::Unchanged);
        $log->file('link', '/b', WriteStatus::Unchanged);
        $log->detail('services PATH: /usr/bin');
        $log->summary();

        $this->assertSame("\nlinks\n  = unchanged /a\n  = unchanged /b\n➜ services PATH: /usr/bin\n\nNothing changed: 2 files and links already up to date.\n", $output->fetch());
    }

    public function testDetailsAndAnEmptySummaryPrintNothingByDefault(): void
    {
        $output = new BufferedOutput();
        $log = new InstallLog($output);

        $log->detail('services PATH: /usr/bin');
        $log->summary();

        $this->assertSame('', $output->fetch());
    }

    public function testPathsAreEscaped(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $log = new InstallLog($output);

        $log->file('file', '/tmp/<info>odd', WriteStatus::Created);

        $this->assertStringContainsString('/tmp/<info>odd', $output->fetch());
    }

    public function testCombineSaysCreatedUnchangedOrUpdated(): void
    {
        $this->assertSame(WriteStatus::Created, WriteStatus::combine([WriteStatus::Created, WriteStatus::Created]));
        $this->assertSame(WriteStatus::Unchanged, WriteStatus::combine([]));
        $this->assertSame(WriteStatus::Updated, WriteStatus::combine([WriteStatus::Created, WriteStatus::Unchanged]));
    }
}
