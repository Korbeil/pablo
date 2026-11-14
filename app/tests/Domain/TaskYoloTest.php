<?php

declare(strict_types=1);

namespace Pablo\Tests\Domain;

use Pablo\Domain\Agent;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use PHPUnit\Framework\TestCase;

final class TaskYoloTest extends TestCase
{
    public function testRoundTripsTheYoloFields(): void
    {
        $task = new Task('p', 'b', '/wt', State::CiRed);
        $task->yolo = false;
        $task->yoloRounds = 2;
        $task->yoloStep = Agent::YoloCommit;

        $back = Task::fromJson($task->toJson());

        $this->assertFalse($back->yolo);
        $this->assertSame(2, $back->yoloRounds);
        $this->assertSame(Agent::YoloCommit, $back->yoloStep);
    }

    public function testATaskWithoutYoloKeepsItsRecordAsBefore(): void
    {
        $json = (new Task('p', 'b', '/wt', State::InProgress))->toJson();

        $this->assertArrayNotHasKey('yolo', $json);
        $this->assertArrayNotHasKey('yolo_rounds', $json);
        $this->assertArrayNotHasKey('yolo_step', $json);
        $back = Task::fromJson($json);
        $this->assertNull($back->yolo);
        $this->assertSame(0, $back->yoloRounds);
        $this->assertNull($back->yoloStep);
    }

    public function testOnlyAYoloStepIsKeptAsTheStep(): void
    {
        $json = (new Task('p', 'b', '/wt', State::InProgress))->toJson();
        $json['yolo_step'] = 'task-analyst';

        $this->assertNull(Task::fromJson($json)->yoloStep);
    }
}
