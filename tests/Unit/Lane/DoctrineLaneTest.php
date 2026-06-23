<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Unit\Lane;

use Doctrine\DBAL\Driver\AbstractException as DriverAbstractException;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Sync\SyncOutcome;
use Th3Mouk\MaterializedViewBundle\Lane\DoctrineLane;
use Th3Mouk\MaterializedViewBundle\Lane\LaneDegradedAfterFailedMigration;
use Th3Mouk\MaterializedViewBundle\Lane\LaneDropStrategy;
use Th3Mouk\MaterializedViewBundle\Lane\ReactiveDropMadeNoProgress;
use Th3Mouk\MaterializedViewBundle\Lane\UnsupportedLaneDropStrategy;
use Th3Mouk\MaterializedViewBundle\Tests\Unit\Lane\Fake\FakeLaneGuard;
use Th3Mouk\MaterializedViewBundle\Tests\Unit\Lane\Fake\FakeLaneMigrator;
use Th3Mouk\MaterializedViewBundle\Tests\Unit\Lane\Fake\FakeManagedViewOperations;
use Th3Mouk\MaterializedViewBundle\Tests\Unit\Lane\Fake\LaneCallLog;

#[Group('lane')]
final class DoctrineLaneTest extends TestCase
{
    public function testRunsDropMigrateSyncInOrderWhenMigrationsPending(): void
    {
        $log = new LaneCallLog();
        $guard = new FakeLaneGuard($log, hasPending: true);
        $migrator = new FakeLaneMigrator($log);
        $views = new FakeManagedViewOperations($log, $this->outcome(created: ['public.a']));

        $result = new DoctrineLane($guard, $migrator, $views)->run();

        self::assertSame(
            ['ensureConnectedToPrimary', 'acquireLock', 'ensureMetadataInitialized', 'hasPendingMigrations', 'dropAllManaged', 'migrate', 'synchronize', 'releaseLock'],
            $log->calls(),
        );
        self::assertTrue($result->migrationsPending);
        self::assertTrue($result->managedViewsDropped);
        self::assertTrue($result->synchronized);
        self::assertFalse($result->dryRun);
        self::assertSame(['public.a'], $result->outcome?->created);
    }

    public function testSkipsDropWhenNoMigrationsPending(): void
    {
        $log = new LaneCallLog();
        $guard = new FakeLaneGuard($log, hasPending: false);
        $migrator = new FakeLaneMigrator($log);
        $views = new FakeManagedViewOperations($log, $this->outcome());

        $result = new DoctrineLane($guard, $migrator, $views)->run();

        self::assertSame(
            ['ensureConnectedToPrimary', 'acquireLock', 'ensureMetadataInitialized', 'hasPendingMigrations', 'migrate', 'synchronize', 'releaseLock'],
            $log->calls(),
        );
        self::assertFalse($result->managedViewsDropped);
        self::assertFalse($log->contains('dropAllManaged'));
        self::assertFalse($migrator->dryRunSeen);
    }

    public function testEnsureConnectedToPrimaryHappensBeforeAcquiringTheLock(): void
    {
        $log = new LaneCallLog();
        $guard = new FakeLaneGuard($log, hasPending: false);

        new DoctrineLane($guard, new FakeLaneMigrator($log), new FakeManagedViewOperations($log, $this->outcome()))->run();

        self::assertLessThan($log->indexOf('acquireLock'), $log->indexOf('ensureConnectedToPrimary'));
    }

    public function testDoesNotSynchroniseAndReportsDegradationWhenMigrationFailsAfterDrop(): void
    {
        $log = new LaneCallLog();
        $guard = new FakeLaneGuard($log, hasPending: true);
        $migrator = new FakeLaneMigrator($log, failWith: new RuntimeException('boom'));
        $views = new FakeManagedViewOperations($log, $this->outcome());

        try {
            new DoctrineLane($guard, $migrator, $views)->run();
            self::fail('Expected LaneDegradedAfterFailedMigration.');
        } catch (LaneDegradedAfterFailedMigration $degraded) {
            self::assertInstanceOf(RuntimeException::class, $degraded->getPrevious());
            self::assertStringContainsString('degraded', $degraded->getMessage());
        }

        self::assertFalse($log->contains('synchronize'));
        self::assertSame(
            ['ensureConnectedToPrimary', 'acquireLock', 'ensureMetadataInitialized', 'hasPendingMigrations', 'dropAllManaged', 'migrate', 'releaseLock'],
            $log->calls(),
        );
    }

    public function testReportsNonDroppedDegradationWhenMigrationFailsWithoutPending(): void
    {
        $log = new LaneCallLog();
        $guard = new FakeLaneGuard($log, hasPending: false);
        $migrator = new FakeLaneMigrator($log, failWith: new RuntimeException('boom'));
        $views = new FakeManagedViewOperations($log, $this->outcome());

        try {
            new DoctrineLane($guard, $migrator, $views)->run();
            self::fail('Expected LaneDegradedAfterFailedMigration.');
        } catch (LaneDegradedAfterFailedMigration $degraded) {
            self::assertStringContainsString('intact', $degraded->getMessage());
        }

        self::assertFalse($log->contains('dropAllManaged'));
        self::assertFalse($log->contains('synchronize'));
        self::assertTrue($log->contains('releaseLock'));
    }

    public function testReleasesLockEvenWhenSynchronisationThrows(): void
    {
        $log = new LaneCallLog();
        $guard = new FakeLaneGuard($log, hasPending: false);
        $views = new FakeManagedViewOperations($log, $this->outcome(), syncFailsWith: new RuntimeException('sync down'));

        try {
            new DoctrineLane($guard, new FakeLaneMigrator($log), $views)->run();
            self::fail('Expected the synchronisation failure to propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('sync down', $exception->getMessage());
        }

        self::assertSame(1, $guard->releaseCount);
        self::assertSame('releaseLock', $log->last());
    }

    public function testDryRunUsesTryLockMigratesInDryRunModeAndSkipsDropAndSync(): void
    {
        $log = new LaneCallLog();
        $guard = new FakeLaneGuard($log, hasPending: true);
        $migrator = new FakeLaneMigrator($log);
        $views = new FakeManagedViewOperations($log, $this->outcome());

        $result = new DoctrineLane($guard, $migrator, $views)->run(dryRun: true);

        self::assertSame(
            ['ensureConnectedToPrimary', 'tryAcquireLock', 'ensureMetadataInitialized', 'hasPendingMigrations', 'migrate', 'releaseLock'],
            $log->calls(),
        );
        self::assertTrue($migrator->dryRunSeen);
        self::assertTrue($result->dryRun);
        self::assertTrue($result->migrationsPending);
        self::assertFalse($result->synchronized);
        self::assertFalse($log->contains('dropAllManaged'));
    }

    public function testDryRunReportsContentionWithoutMigratingWhenLockUnavailable(): void
    {
        $log = new LaneCallLog();
        $guard = new FakeLaneGuard($log, hasPending: true, lockAcquirable: false);
        $migrator = new FakeLaneMigrator($log);

        $result = new DoctrineLane($guard, $migrator, new FakeManagedViewOperations($log, $this->outcome()))->run(dryRun: true);

        self::assertSame(['ensureConnectedToPrimary', 'tryAcquireLock'], $log->calls());
        self::assertTrue($result->lockContended);
        self::assertNull($migrator->dryRunSeen);
        self::assertFalse($log->contains('releaseLock'));
    }

    public function testReactiveStrategyDropsOnlyTheConflictClosureAndRetries(): void
    {
        $log = new LaneCallLog();
        $guard = new FakeLaneGuard($log, hasPending: true);
        $migrator = new FakeLaneMigrator($log, failWith: $this->dependencyConflict('2BP01'), failTimes: 1);
        $views = new FakeManagedViewOperations(
            $log,
            $this->outcome(rebuilt: ['public.order_totals']),
            conflictDrops: [MaterializedViewName::create('public', 'order_totals')],
        );

        $result = new DoctrineLane($guard, $migrator, $views, LaneDropStrategy::ReactiveRetry)->run();

        self::assertSame(
            ['ensureConnectedToPrimary', 'acquireLock', 'ensureMetadataInitialized', 'hasPendingMigrations', 'hasNonTransactionalPendingMigrations', 'migrate', 'dropConflictClosure', 'migrate', 'synchronize', 'releaseLock'],
            $log->calls(),
        );
        self::assertFalse($log->contains('dropAllManaged'), 'The reactive strategy must not drop every managed view.');
        self::assertTrue($result->managedViewsDropped);
        self::assertTrue($result->synchronized);
    }

    public function testReactiveStrategyStillRunsTheMigratorWhenNoMigrationsArePending(): void
    {
        $log = new LaneCallLog();
        $guard = new FakeLaneGuard($log, hasPending: false);
        $migrator = new FakeLaneMigrator($log);
        $views = new FakeManagedViewOperations($log, $this->outcome());

        new DoctrineLane($guard, $migrator, $views, LaneDropStrategy::ReactiveRetry)->run();

        // The migrator must run even with nothing pending so Doctrine logs "No migrations to
        // execute." — the up-to-date database stays visible in the boot logs.
        self::assertSame(
            ['ensureConnectedToPrimary', 'acquireLock', 'ensureMetadataInitialized', 'hasPendingMigrations', 'migrate', 'synchronize', 'releaseLock'],
            $log->calls(),
        );
    }

    public function testReactiveStrategyFallsBackToDropAllWhenANonTransactionalMigrationIsPending(): void
    {
        $log = new LaneCallLog();
        $guard = new FakeLaneGuard($log, hasPending: true, hasNonTransactional: true);
        $migrator = new FakeLaneMigrator($log);
        $views = new FakeManagedViewOperations($log, $this->outcome());

        new DoctrineLane($guard, $migrator, $views, LaneDropStrategy::ReactiveRetry)->run();

        self::assertSame(
            ['ensureConnectedToPrimary', 'acquireLock', 'ensureMetadataInitialized', 'hasPendingMigrations', 'hasNonTransactionalPendingMigrations', 'hasPendingMigrations', 'dropAllManaged', 'migrate', 'synchronize', 'releaseLock'],
            $log->calls(),
        );
        self::assertFalse($log->contains('dropConflictClosure'));
    }

    public function testReactiveStrategyAbortsWhenADropRoundFreesNothing(): void
    {
        $log = new LaneCallLog();
        $guard = new FakeLaneGuard($log, hasPending: true);
        $migrator = new FakeLaneMigrator($log, failWith: $this->dependencyConflict('0A000'));
        $views = new FakeManagedViewOperations($log, $this->outcome(), conflictDrops: []);

        $this->expectException(ReactiveDropMadeNoProgress::class);

        try {
            new DoctrineLane($guard, $migrator, $views, LaneDropStrategy::ReactiveRetry)->run();
        } finally {
            self::assertTrue($log->contains('releaseLock'), 'The lane lock must always be released.');
            self::assertFalse($log->contains('synchronize'));
        }
    }

    public function testReactiveStrategyAbortsWhenADropSetRepeatsWithoutProgress(): void
    {
        $log = new LaneCallLog();
        $guard = new FakeLaneGuard($log, hasPending: true);
        $migrator = new FakeLaneMigrator($log, failWith: $this->dependencyConflict('2BP01'));
        $views = new FakeManagedViewOperations(
            $log,
            $this->outcome(),
            conflictDrops: [MaterializedViewName::create('public', 'order_totals')],
        );

        $this->expectException(ReactiveDropMadeNoProgress::class);

        new DoctrineLane($guard, $migrator, $views, LaneDropStrategy::ReactiveRetry)->run();
    }

    public function testReactiveStrategyReportsDegradationOnANonConflictMigrationFailure(): void
    {
        $log = new LaneCallLog();
        $guard = new FakeLaneGuard($log, hasPending: true);
        $migrator = new FakeLaneMigrator($log, failWith: new RuntimeException('unrelated migration error'));
        $views = new FakeManagedViewOperations($log, $this->outcome());

        $this->expectException(LaneDegradedAfterFailedMigration::class);

        try {
            new DoctrineLane($guard, $migrator, $views, LaneDropStrategy::ReactiveRetry)->run();
        } finally {
            self::assertFalse($log->contains('dropConflictClosure'), 'A non-conflict failure is not retried.');
            self::assertTrue($log->contains('releaseLock'));
        }
    }

    public function testCustomImpactStrategyFailsLoudly(): void
    {
        $log = new LaneCallLog();
        $guard = new FakeLaneGuard($log, hasPending: true);

        $this->expectException(UnsupportedLaneDropStrategy::class);

        try {
            new DoctrineLane($guard, new FakeLaneMigrator($log), new FakeManagedViewOperations($log, $this->outcome()), LaneDropStrategy::CustomImpact)->run();
        } finally {
            self::assertTrue($log->contains('releaseLock'), 'The lock acquired before dispatch must be released.');
            self::assertFalse($log->contains('migrate'));
        }
    }

    private function dependencyConflict(string $sqlState): DriverException
    {
        $message = \sprintf(
            "ERROR:  cannot drop column total of table orders because other objects depend on it\nDETAIL:  materialized view order_totals depends on column total of table orders [sqlstate %s]",
            $sqlState,
        );

        return new DriverException(
            new class($message, $sqlState) extends DriverAbstractException {},
            null,
        );
    }

    /**
     * @param list<string> $created
     * @param list<string> $rebuilt
     */
    private function outcome(array $created = [], array $rebuilt = []): SyncOutcome
    {
        return SyncOutcome::of($created, $rebuilt, [], [], []);
    }
}
