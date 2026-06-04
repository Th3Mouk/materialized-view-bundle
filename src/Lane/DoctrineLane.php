<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Lane;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

final readonly class DoctrineLane
{
    public function __construct(
        private LaneGuard $guard,
        private LaneMigrator $migrator,
        private ManagedViewOperations $views,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function run(bool $dryRun = false): LaneResult
    {
        $this->guard->ensureConnectedToPrimary();

        if ($dryRun) {
            return $this->runDryRun();
        }

        $this->guard->acquireLock();

        try {
            return $this->runLocked();
        } finally {
            $this->releaseLock();
        }
    }

    private function runDryRun(): LaneResult
    {
        if (!$this->guard->tryAcquireLock()) {
            $this->logger->warning('Skipping lane dry-run: another process holds the lane lock on this database.');

            return LaneResult::dryRunLockContended();
        }

        try {
            $pending = $this->guard->hasPendingMigrations();

            $this->logger->info('Lane dry-run: {count} migration(s) pending.', [
                'count' => $pending ? 'one or more' : 'no',
            ]);

            $this->migrator->migrate(true);

            return LaneResult::dryRun($pending);
        } finally {
            $this->releaseLock();
        }
    }

    private function runLocked(): LaneResult
    {
        $pending = $this->guard->hasPendingMigrations();
        $dropped = false;

        if ($pending) {
            $this->logger->info('Pending migrations detected; dropping all managed materialized views before DDL.');
            $this->views->dropAllManaged();
            $dropped = true;
        }

        try {
            $this->migrator->migrate(false);
        } catch (Throwable $migrationFailure) {
            $this->logger->error('Migration failed inside the lane.', [
                'managed_views_dropped' => $dropped,
                'exception' => $migrationFailure->getMessage(),
            ]);

            throw $dropped ? LaneDegradedAfterFailedMigration::withDroppedViews($migrationFailure) : LaneDegradedAfterFailedMigration::withoutDroppedViews($migrationFailure);
        }

        $outcome = $this->views->synchronize();

        $this->logger->info('Lane completed.', [
            'migrations_pending' => $pending,
            'managed_views_dropped' => $dropped,
            'created' => \count($outcome->created),
            'rebuilt' => \count($outcome->rebuilt),
            'pruned' => \count($outcome->pruned),
        ]);

        return LaneResult::completed($pending, $dropped, $outcome);
    }

    private function releaseLock(): void
    {
        if (!$this->guard->releaseLock()) {
            $this->logger->warning('Lane lock was not held at release time.');
        }
    }
}
