<?php

declare(strict_types=1);

namespace Pablo\Tests\Domain;

use Pablo\Domain\State;
use PHPUnit\Framework\TestCase;

final class StateTest extends TestCase
{
    public function testEveryStateHasItsOwnLabelAndAnEmoji(): void
    {
        foreach (State::cases() as $state) {
            $this->assertNotSame('', $state->emoji(), $state->value);
            if (State::ReadyToReview !== $state) {
                $this->assertSame($state->value, $state->label());
            }
        }
    }

    public function testReadyToReviewIsDisplayedAsWaitingReview(): void
    {
        $this->assertSame('waiting-review', State::ReadyToReview->label());
        $this->assertSame(State::WaitingReview->emoji(), State::ReadyToReview->emoji());
    }
}
