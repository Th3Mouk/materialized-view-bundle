<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Lane;

use RuntimeException;
use Th3Mouk\MaterializedViewBundle\Exception\MaterializedViewBundleError;
use Throwable;

/**
 * Raised when the reactive lane cannot clear a migration conflict: a drop round freed
 * nothing new, repeated the previous drop set, or exceeded the attempt budget. Aborting
 * is safer than looping — the schema is left with whatever the bounded retries dropped.
 */
final class ReactiveDropMadeNoProgress extends RuntimeException implements MaterializedViewBundleError
{
    public static function forConflict(string $sqlState, ?Throwable $previous = null): self
    {
        return new self(
            \sprintf('The reactive lane made no progress clearing a dependency conflict (SQLSTATE %s); aborting.', $sqlState),
            0,
            $previous,
        );
    }
}
