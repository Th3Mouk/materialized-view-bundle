<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Lane;

interface LaneGuard
{
    public function ensureConnectedToPrimary(): void;

    public function ensureMetadataInitialized(): void;

    public function acquireLock(): void;

    public function tryAcquireLock(): bool;

    public function releaseLock(): bool;

    public function hasPendingMigrations(): bool;

    public function hasNonTransactionalPendingMigrations(): bool;

    /**
     * Versions of the pending non-transactional migrations that contain a statement able to
     * raise a dependency conflict, or whose SQL could not be determined. Empty when every
     * statement of every one of them is dependency-safe.
     *
     * @return list<string>
     */
    public function dependencyUnsafeNonTransactionalMigrations(): array;
}
