<?php

declare(strict_types=1);

namespace Pablo\Twig;

use Pablo\Command\Report\StatsCommand;
use Pablo\Support\Numbers;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * The terminal's analytics number formats (`pablo stats`, `task:history`)
 * as Twig filters, so /analytics prints "1h20m" and "12k" exactly like the
 * CLI does.
 */
final class AnalyticsFormat extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('pablo_duration', static fn (mixed $seconds, bool $allowDays = false): string => StatsCommand::humanDuration(Numbers::int($seconds), $allowDays)),
            new TwigFilter('pablo_tokens', static fn (mixed $tokens): string => StatsCommand::humanTokens(Numbers::int($tokens))),
            new TwigFilter('pablo_cost', static fn (mixed $cost): string => null !== $cost && Numbers::float($cost) > 0.0 ? \sprintf('%.2f', Numbers::float($cost)) : '-'),
        ];
    }
}
