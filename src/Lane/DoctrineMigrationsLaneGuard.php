<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Lane;

use Doctrine\Migrations\DependencyFactory;
use Psr\Log\LoggerInterface;
use Th3Mouk\MaterializedView\Core\Lock\LaneLock;
use Th3Mouk\MaterializedView\Core\Lock\PrimaryConnectionGuard;

final readonly class DoctrineMigrationsLaneGuard implements LaneGuard
{
    private PrimaryConnectionGuard $primaryConnectionGuard;

    private LaneLock $laneLock;

    public function __construct(
        private DependencyFactory $dependencyFactory,
        int $laneNamespace,
        ?LoggerInterface $logger = null,
    ) {
        $connection = $this->dependencyFactory->getConnection();

        $this->primaryConnectionGuard = new PrimaryConnectionGuard($connection);
        $this->laneLock = new LaneLock($connection, $laneNamespace, $logger);
    }

    public function ensureConnectedToPrimary(): void
    {
        $this->primaryConnectionGuard->ensureConnectedToPrimary();
    }

    public function acquireLock(): void
    {
        $this->laneLock->acquire();
    }

    public function tryAcquireLock(): bool
    {
        return $this->laneLock->tryAcquire();
    }

    public function releaseLock(): bool
    {
        return $this->laneLock->release();
    }

    public function hasPendingMigrations(): bool
    {
        return \count($this->dependencyFactory->getMigrationStatusCalculator()->getNewMigrations()) > 0;
    }
}
