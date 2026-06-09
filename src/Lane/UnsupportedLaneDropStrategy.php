<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Lane;

use RuntimeException;
use Th3Mouk\MaterializedViewBundle\Exception\MaterializedViewBundleError;

/**
 * Raised when a configured lane drop strategy has no implementation. `custom_impact` is
 * reserved in the configuration schema but not yet wired; selecting it fails loudly rather
 * than silently behaving like another strategy.
 */
final class UnsupportedLaneDropStrategy extends RuntimeException implements MaterializedViewBundleError
{
    public static function customImpact(): self
    {
        return new self('The "custom_impact" lane drop strategy is reserved but not implemented; use "all_on_pending" or "reactive_retry".');
    }
}
