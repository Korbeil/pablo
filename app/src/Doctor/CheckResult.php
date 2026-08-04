<?php

declare(strict_types=1);

namespace Pablo\Doctor;

final class CheckResult
{
    public function __construct(
        public string $cli,
        public bool $installed,
        public bool $authenticated,
        public string $detail,
        public string $hint,
    ) {
    }

    public function ok(): bool
    {
        return $this->installed && $this->authenticated;
    }
}
