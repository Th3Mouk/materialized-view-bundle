<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Unit\Lane\Fake;

use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Dependency\PostgresDependencyConflict;
use Th3Mouk\MaterializedView\Core\Sync\SyncOutcome;
use Th3Mouk\MaterializedViewBundle\Lane\ManagedViewOperations;
use Throwable;

final readonly class FakeManagedViewOperations implements ManagedViewOperations
{
    /**
     * @param list<MaterializedViewName> $conflictDrops
     */
    public function __construct(
        private LaneCallLog $log,
        private SyncOutcome $outcome,
        private ?Throwable $syncFailsWith = null,
        private array $conflictDrops = [],
        private ?Throwable $conflictDropFailsWith = null,
    ) {
    }

    public function dropAllManaged(): void
    {
        $this->log->record('dropAllManaged');
    }

    public function dropConflictClosure(PostgresDependencyConflict $conflict): array
    {
        $this->log->record('dropConflictClosure');

        if (null !== $this->conflictDropFailsWith) {
            throw $this->conflictDropFailsWith;
        }

        return $this->conflictDrops;
    }

    public function synchronize(): SyncOutcome
    {
        $this->log->record('synchronize');

        if (null !== $this->syncFailsWith) {
            throw $this->syncFailsWith;
        }

        return $this->outcome;
    }
}
