<?php

declare(strict_types=1);

namespace Pablo\Doctor;

final class AgentFileStatus
{
    public function __construct(
        public string $agent,
        public AgentFileState $state,
        public string $detail,
        public string $hint = '',
    ) {
    }

    public function ok(): bool
    {
        return AgentFileState::Ok === $this->state;
    }
}
