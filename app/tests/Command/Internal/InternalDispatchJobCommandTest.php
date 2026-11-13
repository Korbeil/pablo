<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\Internal;

use Pablo\Command\Internal\InternalDispatchJobCommand;
use Pablo\Dispatch\JobResult;
use Pablo\Tests\Command\CommandTestBed;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class InternalDispatchJobCommandTest extends CommandTestBed
{
    /** @return array{0: int, 1: string, 2: CommandTester} exit code, stdout, tester */
    private function job(string $project, string $job): array
    {
        $tester = new CommandTester(new InternalDispatchJobCommand($this->graph->dispatch, $this->store, $this->projectsLoader));
        ob_start();
        try {
            $code = $tester->execute(['--project' => $project, '--job' => $job], ['capture_stderr_separately' => true, 'verbosity' => OutputInterface::VERBOSITY_NORMAL]);
        } finally {
            $stdout = (string) ob_get_clean();
        }

        return [$code, $stdout, $tester];
    }

    public function testASuccessfulPollEndsWithItsResultLine(): void
    {
        [$code, $stdout] = $this->job('wallet-kit', 'poll');

        $this->assertSame(0, $code);
        [$rest, $result] = JobResult::extract($stdout);
        $this->assertNotNull($result);
        $this->assertTrue($result->ok());
        $this->assertSame(0, $result->checkFailures);
        $this->assertStringNotContainsString('@pablo-dispatch-result', $rest);
        $line = (new JobResult(null))->line();
        $this->assertSame($line, substr($stdout, -\strlen($line)), 'the result line comes last');
    }

    public function testAnUnknownProjectIsAFailedResultNotACrash(): void
    {
        [$code, $stdout, $tester] = $this->job('nope', 'poll');

        $this->assertSame(1, $code);
        [, $result] = JobResult::extract($stdout);
        $this->assertNotNull($result);
        $this->assertFalse($result->ok());
        $this->assertStringContainsString("project 'nope' is not configured", (string) $result->error);
        $this->assertStringContainsString("project 'nope' is not configured", $tester->getErrorOutput(), 'the whole exception goes to stderr');
    }

    public function testAnUnknownJobIsRefused(): void
    {
        [$code, $stdout] = $this->job('wallet-kit', 'deploy');

        $this->assertSame(1, $code);
        [, $result] = JobResult::extract($stdout);
        $this->assertNotNull($result);
        $this->assertStringContainsString("unknown dispatcher job 'deploy'", (string) $result->error);
    }
}
