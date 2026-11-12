<?php

declare(strict_types=1);

namespace Pablo\Tests\Completion;

use Pablo\Completion\Suggestions;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Store\Store;
use PHPUnit\Framework\TestCase;

final class SuggestionsTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-suggest-'.bin2hex(random_bytes(4));
        mkdir($this->tmp.'/projects', 0o777, true);
        putenv('PABLO_PROJECTS_DIR='.$this->tmp.'/projects');
    }

    protected function tearDown(): void
    {
        putenv('PABLO_PROJECTS_DIR');
        exec('rm -rf '.escapeshellarg($this->tmp));
    }

    private function suggestions(?string $stateDir = null): Suggestions
    {
        return new Suggestions(new Store($stateDir ?? $this->tmp.'/state'), new Config(new GlobalConfig()));
    }

    public function testBranchesOfEveryProjectOrOfOne(): void
    {
        $store = new Store($this->tmp.'/state');
        $store->save(new Task('beta', 'zz-2', '/wt/zz-2', State::Draft));
        $store->save(new Task('alpha', 'aa-1', '/wt/aa-1', State::InProgress));
        $store->save(new Task('beta', 'aa-1', '/wt/beta-aa-1', State::InProgress));

        $this->assertSame(['aa-1', 'zz-2'], $this->suggestions()->branches());
        $this->assertSame(['aa-1', 'zz-2'], $this->suggestions()->branches(''));
        $this->assertSame(['aa-1'], $this->suggestions()->branches('alpha'));
        $this->assertSame([], $this->suggestions()->branches('gamma'));
    }

    public function testNoStateDirOrABrokenRecordMeansNoBranches(): void
    {
        $this->assertSame([], $this->suggestions($this->tmp.'/missing')->branches());

        mkdir($this->tmp.'/state/alpha', 0o777, true);
        file_put_contents($this->tmp.'/state/alpha/x.json', 'not json');
        $this->assertSame([], $this->suggestions()->branches());
    }

    public function testProjectsSurviveABrokenConfigFile(): void
    {
        file_put_contents($this->tmp.'/projects/b.yaml', "name: wallet\n");
        file_put_contents($this->tmp.'/projects/a.yaml', "name: api\ntype: nonsense\n");
        file_put_contents($this->tmp.'/projects/broken.yaml', "name: [\n");
        file_put_contents($this->tmp.'/projects/scalar.yaml', "just text\n");

        $this->assertSame(['api', 'wallet'], $this->suggestions()->projects());
    }

    public function testNoProjectsDirMeansNoProjects(): void
    {
        putenv('PABLO_PROJECTS_DIR='.$this->tmp.'/missing');

        $this->assertSame([], $this->suggestions()->projects());
    }

    public function testStaticValues(): void
    {
        $this->assertContains('in-progress', Suggestions::states());
        $this->assertCount(\count(State::cases()), Suggestions::states());
        $this->assertContains('claude-code', Suggestions::runtimes());
        $this->assertContains('headless', Suggestions::ades());
    }
}
