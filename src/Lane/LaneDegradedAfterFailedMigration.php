<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Lane;

use RuntimeException;
use Th3Mouk\MaterializedViewBundle\Exception\MaterializedViewBundleError;
use Throwable;

final class LaneDegradedAfterFailedMigration extends RuntimeException implements MaterializedViewBundleError
{
    public static function withDroppedViews(Throwable $previous): self
    {
        return new self(
            'Migration failed after managed materialized views were dropped. The schema is left in a degraded state; '
            .'recover with "matview:sync --after-failed-migration" or re-run "matview:doctrine-lane" once migrations are fixed.',
            0,
            $previous,
        );
    }

    public static function withoutDroppedViews(Throwable $previous): self
    {
        return new self(
            'Migration failed before any managed materialized view was dropped; existing views are intact. '
            .'Fix the migration and re-run "matview:doctrine-lane".',
            0,
            $previous,
        );
    }
}
