<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Unit\Lane;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Th3Mouk\MaterializedView\Core\Sync\SyncOutcome;
use Th3Mouk\MaterializedViewBundle\Lane\DoctrineLane;
use Th3Mouk\MaterializedViewBundle\Lane\LaneDegradedAfterFailedMigration;
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
            ['ensureConnectedToPrimary', 'acquireLock', 'hasPendingMigrations', 'dropAllManaged', 'migrate', 'synchronize', 'releaseLock'],
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
            ['ensureConnectedToPrimary', 'acquireLock', 'hasPendingMigrations', 'migrate', 'synchronize', 'releaseLock'],
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
            ['ensureConnectedToPrimary', 'acquireLock', 'hasPendingMigrations', 'dropAllManaged', 'migrate', 'releaseLock'],
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
            ['ensureConnectedToPrimary', 'tryAcquireLock', 'hasPendingMigrations', 'migrate', 'releaseLock'],
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

    /**
     * @param list<string> $created
     */
    private function outcome(array $created = []): SyncOutcome
    {
        return SyncOutcome::of($created, [], [], [], []);
    }
}
