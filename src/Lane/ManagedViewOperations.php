<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Lane;

use Th3Mouk\MaterializedView\Core\Sync\SyncOutcome;

interface ManagedViewOperations
{
    public function dropAllManaged(): void;

    public function synchronize(): SyncOutcome;
}
