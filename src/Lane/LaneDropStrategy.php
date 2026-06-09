<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Lane;

/**
 * How the deploy lane clears managed materialized views that block a pending migration.
 *
 *   all_on_pending — drop every managed view up front when any migration is pending, then
 *                    migrate, then re-sync. Blunt but always safe (the default).
 *   reactive_retry — migrate first; on a dependency-conflict SQLSTATE drop only the
 *                    conflicting closure and retry. Falls back to all_on_pending when a
 *                    non-transactional migration is pending.
 *   custom_impact  — reserved; not yet implemented.
 */
enum LaneDropStrategy: string
{
    case AllOnPending = 'all_on_pending';
    case ReactiveRetry = 'reactive_retry';
    case CustomImpact = 'custom_impact';
}
