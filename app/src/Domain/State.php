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
    case Approved = 'approved';
    case NeedsTesting = 'needs-testing';
    case RequestChanges = 'request-changes';
    case TestingFailed = 'testing-failed';

    /** @return list<State> */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * Display priority for listings and the dashboard: lower ranks first.
     * The single source of truth shared by the terminal listing and TaskView,
     * so the two surfaces cannot drift.
     *
     * @var list<State>
     */
    public const DISPLAY_ORDER = [
        self::TestingFailed,
        self::NeedsTesting,
        self::Approved,
        self::RequestChanges,
        self::WaitingReview,
        self::ReadyToReview,
        self::CiRed,
        self::Draft,
        self::Waiting,
        self::InProgress,
    ];

    public function displayRank(): int
    {
        $i = array_search($this, self::DISPLAY_ORDER, true);

        return false === $i ? \count(self::DISPLAY_ORDER) : (int) $i;
    }
}
