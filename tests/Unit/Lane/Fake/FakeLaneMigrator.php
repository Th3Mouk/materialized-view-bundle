<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Unit\Lane\Fake;

use Th3Mouk\MaterializedViewBundle\Lane\LaneMigrator;
use Throwable;

final class FakeLaneMigrator implements LaneMigrator
{
    public ?bool $dryRunSeen = null;

    public function __construct(
        private readonly LaneCallLog $log,
        private readonly ?Throwable $failWith = null,
    ) {
    }

    public function migrate(bool $dryRun): void
    {
        $this->dryRunSeen = $dryRun;
        $this->log->record('migrate');

        if (null !== $this->failWith) {
            throw $this->failWith;
        }
    }
}
