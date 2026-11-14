<?php

declare(strict_types=1);

namespace Pablo\Tests\Yolo;

use Pablo\Config\ProjectConfig;
use Pablo\Domain\Agent;
use Pablo\Domain\AgentLaunch;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Tests\Command\CommandTestBed;
use Pablo\Yolo\YoloPipeline;

final class YoloPipelineTest extends CommandTestBed
{
    private function cfg(string $yolo = ''): ProjectConfig
    {
        if ('' !== $yolo) {
            file_put_contents($this->projectsDir.'/wallet-kit.yaml', "\n".$yolo, \FILE_APPEND);
        }

        return $this->projectsLoader->loadProjectFile($this->projectsDir.'/wallet-kit.yaml');
    }

    private function finishedAnalyst(?string $session = 'ses_plan'): Task
    {
        $task = $this->getTask();
        $task->agentLaunches[Agent::TaskAnalyst->value] = new AgentLaunch(Agent::TaskAnalyst, '2026-11-01T00:00:00+00:00', 1, '2026-11-01T00:10:00+00:00', sessionId: $session);

        return $task;
    }

    public function testOffByDefault(): void
    {
        $cfg = $this->cfg();
        $task = $this->finishedAnalyst();

        $this->assertFalse(YoloPipeline::isOn($task, $cfg));
        $this->assertNull($this->yolo->onAgentFinished($cfg, $task, Agent::TaskAnalyst, $this->agents));
        $this->assertSame([], $this->agents->followUps);
        $this->assertNull($task->yoloStep);
    }

    public function testTheProjectTurnsItOnAndATaskOverridesIt(): void
    {
        $cfg = $this->cfg("yolo:\n  enabled: true\n");
        $task = $this->finishedAnalyst();
        $this->assertTrue(YoloPipeline::isOn($task, $cfg));

        $task->yolo = false;
        $this->assertFalse(YoloPipeline::isOn($task, $cfg));
        $this->assertNull($this->yolo->onAgentFinished($cfg, $task, Agent::TaskAnalyst, $this->agents));
        $this->assertSame([], $this->agents->followUps);
    }

    public function testATaskTurnsItOnInAProjectWithout(): void
    {
        $task = $this->finishedAnalyst();
        $task->yolo = true;

        $this->assertTrue(YoloPipeline::isOn($task, $this->cfg()));
    }

    public function testAFinishedPlanIsImplementedInTheSameSession(): void
    {
        $cfg = $this->cfg();
        $task = $this->finishedAnalyst();
        $task->yolo = true;

        $event = $this->yolo->onAgentFinished($cfg, $task, Agent::TaskAnalyst, $this->agents);

        $this->assertSame('task-analyst finished → yolo-implement (round 1/5)', $event);
        $this->assertCount(1, $this->agents->followUps);
        $this->assertSame('yolo-implement', $this->agents->followUps[0]['step']);
        $this->assertSame('ses_plan', $this->agents->followUps[0]['session']);
        $this->assertStringContainsString('step 1 of 2: implement', $this->agents->followUps[0]['prompt']);
        // The state agent's own prompt, for a session holding no plan.
        $this->assertStringContainsString('Analyze issue 45', $this->agents->followUps[0]['prompt']);
        $this->assertSame(Agent::YoloImplement, $task->yoloStep);
        $this->assertSame(1, $task->yoloRounds);
        $this->assertSame('ses_plan', $task->agentLaunches['yolo-implement']->sessionId);
        // Not the user's move while YOLO carries on.
        $this->assertFalse($task->hasFinishedAgent());
    }

    public function testTheCommitStartsOnlyOnceTheImplementationHasFinished(): void
    {
        $cfg = $this->cfg();
        $task = $this->finishedAnalyst();
        $task->yolo = true;
        $this->yolo->onAgentFinished($cfg, $task, Agent::TaskAnalyst, $this->agents);
        $this->assertCount(1, $this->agents->followUps);

        $event = $this->yolo->onAgentFinished($cfg, $task, Agent::YoloImplement, $this->agents);

        $this->assertSame('yolo-implement finished → yolo-commit', $event);
        $this->assertCount(2, $this->agents->followUps);
        $commit = $this->agents->followUps[1];
        $this->assertSame('yolo-commit', $commit['step']);
        $this->assertSame('ses_plan', $commit['session']);
        // /pablo-commit-and-pr's own body, its context turned into commands.
        $this->assertStringContainsString('/pablo-commit-and-pr', $commit['prompt']);
        $this->assertStringContainsString('`pablo task:precommit-check --json 2>&1`', $commit['prompt']);
        $this->assertStringNotContainsString('!`', $commit['prompt']);
        $this->assertStringNotContainsString('$ARGUMENTS', $commit['prompt']);
        $this->assertSame(Agent::YoloCommit, $task->yoloStep);

        $this->assertSame('yolo-commit finished', $this->yolo->onAgentFinished($cfg, $task, Agent::YoloCommit, $this->agents));
        $this->assertNull($task->yoloStep);
        $this->assertCount(2, $this->agents->followUps);
    }

    public function testEveryStateAgentIsFollowedUp(): void
    {
        $cfg = $this->cfg("yolo:\n  enabled: true\n");
        foreach ([[State::CiRed, Agent::CiAnalyst], [State::RequestChanges, Agent::PrFeedback], [State::TestingFailed, Agent::TaskFeedback]] as [$state, $agent]) {
            $task = $this->getTask();
            $task->state = $state;
            $task->agentLaunches[$agent->value] = new AgentLaunch($agent, '2026-11-01T00:00:00+00:00', 1, '2026-11-01T00:10:00+00:00', sessionId: 'ses_'.$agent->value);

            $this->assertNotNull($this->yolo->onAgentFinished($cfg, $task, $agent, $this->agents), $agent->value);
            $sessions = array_column($this->agents->followUps, 'session');
            $this->assertSame('ses_'.$agent->value, end($sessions));
        }
        $this->assertCount(3, $this->agents->followUps);
    }

    public function testAnAgentOfAnotherStateOrTheStartupScriptIsNotFollowedUp(): void
    {
        $cfg = $this->cfg("yolo:\n  enabled: true\n");
        $task = $this->getTask(); // in-progress

        $this->assertNull($this->yolo->onAgentFinished($cfg, $task, Agent::CiAnalyst, $this->agents));
        $this->assertNull($this->yolo->onAgentFinished($cfg, $task, Agent::StartupScript, $this->agents));
        $this->assertNull($this->yolo->onAgentFinished($cfg, $task, Agent::YoloCommit, $this->agents), 'a stale step');
        $this->assertSame([], $this->agents->followUps);
    }

    public function testNoSecondImplementationWhileOneRuns(): void
    {
        $cfg = $this->cfg("yolo:\n  enabled: true\n");
        $task = $this->finishedAnalyst();
        $task->yoloStep = Agent::YoloCommit;

        $this->assertNull($this->yolo->onAgentFinished($cfg, $task, Agent::TaskAnalyst, $this->agents));
        $this->assertSame([], $this->agents->followUps);
    }

    public function testStopsAfterMaxRounds(): void
    {
        $cfg = $this->cfg("yolo:\n  enabled: true\n  max_rounds: 2\n");
        $task = $this->finishedAnalyst();
        $task->yoloRounds = 2;

        $event = $this->yolo->onAgentFinished($cfg, $task, Agent::TaskAnalyst, $this->agents);

        $this->assertNotNull($event);
        $this->assertStringContainsString('yolo.max_rounds', $event);
        $this->assertSame([], $this->agents->followUps);
        $this->assertTrue($task->hasFinishedAgent(), 'the plan waits for the user');
    }

    public function testWithoutARecordedSessionAFreshOneIsUsed(): void
    {
        $cfg = $this->cfg("yolo:\n  enabled: true\n");
        $task = $this->finishedAnalyst(null);

        $this->yolo->onAgentFinished($cfg, $task, Agent::TaskAnalyst, $this->agents);

        $this->assertSame('ses_fake_1', $this->agents->followUps[0]['session']);
    }

    public function testNoCommitWhenTheTaskWasPausedOrYoloTurnedOff(): void
    {
        $cfg = $this->cfg("yolo:\n  enabled: true\n");
        $task = $this->finishedAnalyst();
        $this->yolo->onAgentFinished($cfg, $task, Agent::TaskAnalyst, $this->agents);
        $task->state = State::Waiting;

        $event = $this->yolo->onAgentFinished($cfg, $task, Agent::YoloImplement, $this->agents);

        $this->assertNotNull($event);
        $this->assertStringContainsString('no commit from waiting', $event);
        $this->assertCount(1, $this->agents->followUps);
        $this->assertNull($task->yoloStep);

        $task->state = State::InProgress;
        $task->yoloStep = Agent::YoloImplement;
        $task->yolo = false;
        $event = $this->yolo->onAgentFinished($cfg, $task, Agent::YoloImplement, $this->agents);
        $this->assertNotNull($event);
        $this->assertStringContainsString('turned off', $event);
        $this->assertCount(1, $this->agents->followUps);
    }
}
