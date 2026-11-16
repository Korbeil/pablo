<?php

declare(strict_types=1);

namespace Pablo\Support;

/**
 * What writing a file (or placing a link) did: what `pablo system:install`
 * reports, so a re-install shows at a glance what actually changed.
 */
enum WriteStatus: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Unchanged = 'unchanged';

    /**
     * The status of a group of writes (a directory copied file by file):
     * created when every one was, unchanged when every one was (or there
     * were none), else updated.
     *
     * @param iterable<self> $statuses
     */
    public static function combine(iterable $statuses): self
    {
        $seen = [];
        foreach ($statuses as $status) {
            $seen[$status->value] = $status;
        }
        if ([] === $seen) {
            return self::Unchanged;
        }

        return 1 === \count($seen) ? reset($seen) : self::Updated;
    }

    public function changed(): bool
    {
        return self::Unchanged !== $this;
    }
}
