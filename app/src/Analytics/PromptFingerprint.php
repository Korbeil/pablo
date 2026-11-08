<?php

declare(strict_types=1);

namespace Pablo\Analytics;

/**
 * Short non-reversible prompt fingerprint used to attribute a runtime
 * session to the agent run that sent the prompt. Every runtime's usage
 * harvester hashes the first user message the same way.
 */
final class PromptFingerprint
{
    public static function of(string $prompt): string
    {
        return substr(hash('sha256', $prompt), 0, 16);
    }
}
