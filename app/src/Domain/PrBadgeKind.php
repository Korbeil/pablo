<?php

declare(strict_types=1);

namespace Pablo\Domain;

enum PrBadgeKind: string
{
    /** No PR opened yet. */
    case None = 'none';
    /** PR merged (the number is not always known — a merged task keeps only the flag). */
    case Merged = 'merged';
    case Draft = 'draft';
    case Open = 'open';
    /** We know the number but could not read the PR's state. */
    case Unknown = 'unknown';
}
