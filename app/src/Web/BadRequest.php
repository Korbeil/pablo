<?php

declare(strict_types=1);

namespace Pablo\Web;

/**
 * A request Server cannot parse or will not accept; answered with $status
 * without reaching the kernel.
 */
final class BadRequest extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}
