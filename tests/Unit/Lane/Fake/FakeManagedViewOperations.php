<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Unit\Lane\Fake;

use Th3Mouk\MaterializedView\Core\Sync\SyncOutcome;
use Th3Mouk\MaterializedViewBundle\Lane\ManagedViewOperations;
use Throwable;

final readonly class FakeManagedViewOperations implements ManagedViewOperations
{
    public function __construct(
        private LaneCallLog $log,
        private SyncOutcome $outcome,
        private ?Throwable $syncFailsWith = null,
    ) {
    }

    public function dropAllManaged(): void
    {
        $this->log->record('dropAllManaged');
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
