<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime;

/**
 * How to resume a recorded agent session, decided from the handle itself so
 * a log written under one runtime still reads right after switching: every
 * runtime's handles are recognisable (RuntimeSessionsInterface::owns()), and
 * the default runtime takes those nobody claims.
 */
final class SessionRef
{
    public static function command(string $handle): string
    {
        $strategies = AgentRuntimeRegistry::sessionStrategies();
        foreach ($strategies as $sessions) {
            if ($sessions->owns($handle)) {
                return $sessions->resumeCommand($handle);
            }
        }

        return $strategies[\count($strategies) - 1]->resumeCommand($handle);
    }
}
