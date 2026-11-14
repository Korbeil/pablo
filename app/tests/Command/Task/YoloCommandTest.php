<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\Task;

use Pablo\Command\Task\YoloCommand;
use Pablo\Tests\Command\CommandTestBed;

final class YoloCommandTest extends CommandTestBed
{
    private function yoloCommand(): YoloCommand
    {
        return new YoloCommand($this->store, $this->projectsLoader, $this->agents);
    }

    public function testOnOffAndBackToTheProject(): void
    {
        $task = $this->getTask();
        $task->yoloRounds = 3;
        $this->store->save($task);

        $tester = $this->runCommand($this->yoloCommand(), ['mode' => 'on', 'branch' => 'wk-45']);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame("wk-45: YOLO mode on\n", $tester->getDisplay());
        $this->assertTrue($this->getTask()->yolo);
        $this->assertSame(0, $this->getTask()->yoloRounds, 'a new choice counts its rounds anew');

        $this->runCommand($this->yoloCommand(), ['mode' => 'off', 'branch' => 'wk-45']);
        $this->assertFalse($this->getTask()->yolo);

        $tester = $this->runCommand($this->yoloCommand(), ['mode' => 'project', 'branch' => 'wk-45']);
        $this->assertNull($this->getTask()->yolo);
        $this->assertSame("wk-45: YOLO mode off (project wallet-kit's yolo.enabled)\n", $tester->getDisplay());
    }

    public function testRefusesAnUnknownMode(): void
    {
        $tester = $this->runCommand($this->yoloCommand(), ['mode' => 'maybe', 'branch' => 'wk-45']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString("unknown mode 'maybe'", $tester->getDisplay());
        $this->assertNull($this->getTask()->yolo);
    }
}
