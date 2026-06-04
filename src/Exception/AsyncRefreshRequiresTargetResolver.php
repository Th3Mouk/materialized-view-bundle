<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Exception;

use RuntimeException;

final class AsyncRefreshRequiresTargetResolver extends RuntimeException implements MaterializedViewBundleError
{
    public static function forSync(): self
    {
        return new self(
            'Cannot enqueue async initial refreshes: sync.async_requires_target_resolver is enabled but the configured '
            .'dispatcher cannot route them per-connection (symfony/messenger missing or a non-shared transport scope). '
            .'Install symfony/messenger with a shared transport, run the refresh inline with --refresh-initial, '
            .'or set sync.async_requires_target_resolver to false to accept the risk.',
        );
    }
}
