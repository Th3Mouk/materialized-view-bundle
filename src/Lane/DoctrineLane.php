<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Lane;

use Doctrine\DBAL\Exception\DriverException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Dependency\PostgresDependencyConflict;
use Throwable;

final readonly class DoctrineLane
{
    private const int MAX_REACTIVE_ATTEMPTS = 64;

    public function __construct(
        private LaneGuard $guard,
        private LaneMigrator $migrator,
        private ManagedViewOperations $views,
        private LaneDropStrategy $strategy = LaneDropStrategy::AllOnPending,
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
        return match ($this->strategy) {
            LaneDropStrategy::AllOnPending => $this->runDropAllOnPending(),
            LaneDropStrategy::ReactiveRetry => $this->runReactive(),
            LaneDropStrategy::CustomImpact => throw UnsupportedLaneDropStrategy::customImpact(),
        };
    }

    private function runDropAllOnPending(): LaneResult
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
            throw $this->degraded($migrationFailure, $dropped);
        }

        return $this->completed($pending, $dropped);
    }

    private function runReactive(): LaneResult
    {
        if (!$this->guard->hasPendingMigrations()) {
            return $this->completed(false, false);
        }

        if ($this->guard->hasNonTransactionalPendingMigrations()) {
            $this->logger->warning('A non-transactional migration is pending; falling back to dropping all managed views before DDL.');

            return $this->runDropAllOnPending();
        }

        $dropped = $this->migrateReactively();

        return $this->completed(true, [] !== $dropped);
    }

    /**
     * Migrate, and on each materialized-view dependency conflict (SQLSTATE 2BP01/0A000)
     * drop only the blocking managed closure and retry. The migrator resumes from the
     * failed version; the surgical drop runs in its own transaction. A bounded progress
     * guard (nothing dropped, a repeated drop set, or the attempt budget) aborts rather
     * than loop on an unresolvable conflict.
     *
     * @return list<MaterializedViewName>
     */
    private function migrateReactively(): array
    {
        $attempts = 0;
        $lastSignature = null;
        /** @var array<string, MaterializedViewName> $droppedTotal */
        $droppedTotal = [];

        while (true) {
            try {
                $this->migrator->migrate(false);

                return array_values($droppedTotal);
            } catch (Throwable $migrationFailure) {
                $conflict = $this->conflictFrom($migrationFailure);

                if (null === $conflict) {
                    throw $this->degraded($migrationFailure, [] !== $droppedTotal);
                }

                $dropped = $this->views->dropConflictClosure($conflict);
                $signature = implode(',', array_map(
                    static fn (MaterializedViewName $name): string => $name->qualifiedName(),
                    $dropped,
                ));

                if ([] === $dropped || $signature === $lastSignature || ++$attempts > self::MAX_REACTIVE_ATTEMPTS) {
                    throw ReactiveDropMadeNoProgress::forConflict($conflict->sqlState(), $migrationFailure);
                }

                $lastSignature = $signature;
                foreach ($dropped as $name) {
                    $droppedTotal[$name->qualifiedName()] = $name;
                }

                $this->logger->warning('Reactively dropped materialized views to clear a migration dependency conflict.', [
                    'sql_state' => $conflict->sqlState(),
                    'dropped' => $signature,
                ]);
            }
        }
    }

    private function conflictFrom(Throwable $failure): ?PostgresDependencyConflict
    {
        for ($current = $failure; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof DriverException) {
                return PostgresDependencyConflict::fromDriverException($current);
            }
        }

        return null;
    }

    private function degraded(Throwable $migrationFailure, bool $dropped): LaneDegradedAfterFailedMigration
    {
        $this->logger->error('Migration failed inside the lane.', [
            'managed_views_dropped' => $dropped,
            'exception' => $migrationFailure->getMessage(),
        ]);

        return $dropped
            ? LaneDegradedAfterFailedMigration::withDroppedViews($migrationFailure)
            : LaneDegradedAfterFailedMigration::withoutDroppedViews($migrationFailure);
    }

    private function completed(bool $pending, bool $dropped): LaneResult
    {
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
