<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Lane;

interface LaneGuard
{
    public function ensureConnectedToPrimary(): void;

    public function acquireLock(): void;

    public function tryAcquireLock(): bool;

    public function releaseLock(): bool;

    public function hasPendingMigrations(): bool;

    public function hasNonTransactionalPendingMigrations(): bool;
}
