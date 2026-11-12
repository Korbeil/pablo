<?php

declare(strict_types=1);

namespace Pablo\Tests\Listing;

use Pablo\Agents\SessionInfo;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Listing\TaskPrefetch;
use Pablo\Provider\Gh\PrInfo;
use PHPUnit\Framework\TestCase;

final class TaskPrefetchTest extends TestCase
{
    public function testLookupsAnswerWhatWasPrefetched(): void
    {
        $sessions = [new SessionInfo('a', 'running')];
        $pr = new PrInfo(7, 'PR', 'OPEN', false, 'u', null);
        $prefetch = new TaskPrefetch(
            ['/wt/a' => $sessions],
            ['github' => ['WK-45' => 'Doing']],
            ['acme/kit' => ['a' => $pr]],
        );

        $this->assertSame($sessions, $prefetch->sessionsFor(new Task('kit', 'a', '/wt/a', State::Draft)));
        $this->assertSame(['WK-45' => 'Doing'], $prefetch->trackerStatusesFor('github'));
        $this->assertSame(['a' => $pr], $prefetch->prsFor('acme/kit'));
    }

    public function testNullMeansNotPrefetched(): void
    {
        $prefetch = new TaskPrefetch();

        $this->assertNull($prefetch->sessionsFor(new Task('kit', 'a', '/wt/a', State::Draft)));
        $this->assertNull($prefetch->trackerStatusesFor('jira'));
        $this->assertNull($prefetch->prsFor('acme/kit'));
    }
}
