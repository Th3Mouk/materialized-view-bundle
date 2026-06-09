<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Unit\Lane\Fake;

use Th3Mouk\MaterializedViewBundle\Lane\LaneMigrator;
use Throwable;

final class FakeLaneMigrator implements LaneMigrator
{
    public ?bool $dryRunSeen = null;

    private int $calls = 0;

    public function __construct(
        private readonly LaneCallLog $log,
        private readonly ?Throwable $failWith = null,
        private readonly int $failTimes = \PHP_INT_MAX,
    ) {
    }

    public function migrate(bool $dryRun): void
    {
        $this->dryRunSeen = $dryRun;
        ++$this->calls;
        $this->log->record('migrate');

        if (null !== $this->failWith && $this->calls <= $this->failTimes) {
            throw $this->failWith;
        }
    }
}
