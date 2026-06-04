<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Command\Port;

use RuntimeException;
use Th3Mouk\MaterializedViewBundle\Exception\MaterializedViewBundleError;

final class InitialRefreshDispatchUnavailable extends RuntimeException implements MaterializedViewBundleError
{
    public static function create(): self
    {
        return new self('Cannot enqueue an initial refresh: symfony/messenger is not installed. Install symfony/messenger and configure a shared transport, or run the refresh inline with matview:sync --refresh-initial.');
    }
}
