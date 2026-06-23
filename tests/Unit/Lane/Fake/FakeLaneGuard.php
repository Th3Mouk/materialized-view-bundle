<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Unit\Lane\Fake;

use Th3Mouk\MaterializedViewBundle\Lane\LaneGuard;

final class FakeLaneGuard implements LaneGuard
{
    public int $releaseCount = 0;

    public function __construct(
        private readonly LaneCallLog $log,
        private readonly bool $hasPending,
        private readonly bool $lockAcquirable = true,
        private readonly bool $lockHeldAtRelease = true,
        private readonly bool $hasNonTransactional = false,
    ) {
    }

    public function ensureConnectedToPrimary(): void
    {
        $this->log->record('ensureConnectedToPrimary');
    }

    public function ensureMetadataInitialized(): void
    {
        $this->log->record('ensureMetadataInitialized');
    }

    public function acquireLock(): void
    {
        $this->log->record('acquireLock');
    }

    public function tryAcquireLock(): bool
    {
        $this->log->record('tryAcquireLock');

        return $this->lockAcquirable;
    }

    public function releaseLock(): bool
    {
        ++$this->releaseCount;
        $this->log->record('releaseLock');

        return $this->lockHeldAtRelease;
    }

    public function hasPendingMigrations(): bool
    {
        $this->log->record('hasPendingMigrations');

        return $this->hasPending;
    }

    public function hasNonTransactionalPendingMigrations(): bool
    {
        $this->log->record('hasNonTransactionalPendingMigrations');

        return $this->hasNonTransactional;
    }
}
