<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Lane;

use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Doctrine\Migrations\Metadata\Storage\MetadataStorage;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Query\Query;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\Version;
use Psr\Log\LoggerInterface;
use Th3Mouk\MaterializedView\Core\Dependency\DependencySafeStatement;
use Th3Mouk\MaterializedView\Core\Lock\LaneLock;
use Th3Mouk\MaterializedView\Core\Lock\PrimaryConnectionGuard;
use Th3Mouk\MaterializedView\Dbal\DbalConnection;
use Throwable;

/**
 * The SQL of the pending non-transactional migrations comes from Doctrine Migrations' own
 * dry run (MigratorConfiguration::setDryRun()), planned by the migration plan calculator and
 * collected by the migrator as Query objects; nothing parses migration files.
 *
 * The dry run uses a fresh DependencyFactory: a migration instance is frozen once it has
 * run, so the instances the real migration later obtains must stay untouched. It runs
 * without a logger, so no "++ migrating" progress is emitted for a migration that does not
 * actually run. Any failure while planning or collecting makes every pending
 * non-transactional migration count as dependency-unsafe.
 */
final readonly class DoctrineMigrationsLaneGuard implements LaneGuard
{
    private PrimaryConnectionGuard $primaryConnectionGuard;

    private LaneLock $laneLock;

    public function __construct(
        private DependencyFactory $dependencyFactory,
        int $laneNamespace,
        ?LoggerInterface $logger = null,
    ) {
        $connection = new DbalConnection($this->dependencyFactory->getConnection());

        $this->primaryConnectionGuard = new PrimaryConnectionGuard($connection);
        $this->laneLock = new LaneLock($connection, $laneNamespace, $logger);
    }

    public function ensureConnectedToPrimary(): void
    {
        $this->primaryConnectionGuard->ensureConnectedToPrimary();
    }

    public function ensureMetadataInitialized(): void
    {
        $this->dependencyFactory->getMetadataStorage()->ensureInitialized();
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

    public function hasNonTransactionalPendingMigrations(): bool
    {
        return array_any(
            $this->dependencyFactory->getMigrationStatusCalculator()->getNewMigrations()->getItems(),
            static fn (AvailableMigration $available): bool => !$available->getMigration()->isTransactional(),
        );
    }

    public function dependencyUnsafeNonTransactionalMigrations(): array
    {
        $versions = $this->pendingNonTransactionalVersions();

        if ([] === $versions) {
            return [];
        }

        try {
            $sqlByVersion = $this->dryRunSql($versions);
        } catch (Throwable) {
            return array_map(strval(...), $versions);
        }

        $unsafe = [];

        foreach ($versions as $version) {
            $statements = array_map(
                static fn (Query $query): string => $query->getStatement(),
                $sqlByVersion[(string) $version] ?? [],
            );

            if (!DependencySafeStatement::areAllSafe($statements)) {
                $unsafe[] = (string) $version;
            }
        }

        return $unsafe;
    }

    /**
     * @return list<Version>
     */
    private function pendingNonTransactionalVersions(): array
    {
        $pending = $this->dependencyFactory->getMigrationStatusCalculator()->getNewMigrations()->getItems();

        return array_values(array_map(
            static fn (AvailableMigration $available): Version => $available->getVersion(),
            array_filter(
                $pending,
                static fn (AvailableMigration $available): bool => !$available->getMigration()->isTransactional(),
            ),
        ));
    }

    /**
     * @param list<Version> $versions
     *
     * @return array<string, array<Query>>
     */
    private function dryRunSql(array $versions): array
    {
        $factory = DependencyFactory::fromConnection(
            new ExistingConfiguration($this->dependencyFactory->getConfiguration()),
            new ExistingConnection($this->dependencyFactory->getConnection()),
        );
        $factory->setService(
            MetadataStorage::class,
            new DryRunMetadataStorage($this->dependencyFactory->getMetadataStorage()),
        );

        $plan = $factory->getMigrationPlanCalculator()->getPlanForVersions($versions, Direction::UP);

        return $factory->getMigrator()->migrate(
            $plan,
            new MigratorConfiguration()->setDryRun(true)->setAllOrNothing(false),
        );
    }
}
