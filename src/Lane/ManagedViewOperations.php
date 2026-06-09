<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Lane;

use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Dependency\PostgresDependencyConflict;
use Th3Mouk\MaterializedView\Core\Sync\SyncOutcome;

interface ManagedViewOperations
{
    public function dropAllManaged(): void;

    /**
     * Drop only the managed materialized views that block a migration DDL conflict, in a
     * dedicated transaction. Refuses (throws) when the conflict closure contains an
     * unmanaged dependent.
     *
     * @return list<MaterializedViewName> the views dropped, in drop order
     */
    public function dropConflictClosure(PostgresDependencyConflict $conflict): array;

    public function synchronize(): SyncOutcome;
}
