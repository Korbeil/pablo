<?php

declare(strict_types=1);

namespace Pablo\Domain;

/**
 * Canonical task-state values (the stored JSON uses ->value).
 */
enum State: string
{
    case InProgress = 'in-progress';
    case Waiting = 'waiting';
    case Draft = 'draft';
    case CiRed = 'ci-red';
    case ReadyToReview = 'ready-to-review';
    case WaitingReview = 'waiting-review';
    case NeedsTesting = 'needs-testing';
    case RequestChanges = 'request-changes';
    case TestingFailed = 'testing-failed';

    /** @return list<State> */
    public static function all(): array
    {
        return self::cases();
    }
}
