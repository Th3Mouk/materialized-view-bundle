<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Exception\MetadataStorageError;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Dependency\PostgresDependencyConflict;
use Th3Mouk\MaterializedView\Core\Sync\SyncOutcome;
use Th3Mouk\MaterializedViewBundle\Lane\DoctrineLane;
use Th3Mouk\MaterializedViewBundle\Lane\DoctrineMigrationsLaneGuard;
use Th3Mouk\MaterializedViewBundle\Lane\DoctrineMigrationsLaneMigrator;
use Th3Mouk\MaterializedViewBundle\Lane\LaneDropStrategy;
use Th3Mouk\MaterializedViewBundle\Lane\ManagedViewOperations;
use Throwable;

/**
 * Regression for the metadata-storage ordering bug: the lane read the Doctrine migration status
 * (hasPendingMigrations()) BEFORE initialising the metadata storage, so a doctrine_migration_versions
 * table whose schema has drifted from the one doctrine/migrations expects made the lane abort with
 * MetadataStorageError ("The metadata storage is not up to date, please run the sync-metadata-storage
 * command…") before any migration ran. A plain doctrine:migrations:migrate self-heals because
 * MigrateCommand calls ensureInitialized() (which alters the drifted table in place) first.
 *
 * This builds a real DependencyFactory against a Postgres whose doctrine_migration_versions.version
 * column is VARCHAR(255) instead of the expected VARCHAR(191), runs the lane, and asserts it does
 * NOT throw MetadataStorageError and that the pending migration is applied. With the fix reverted,
 * the lane throws before the migration runs and the probe table is never created.
 *
 * Skipped when MATVIEW_TEST_DATABASE_URL is unset or unreachable.
 */
#[Group('lane')]
final class LaneDriftedMetadataStorageTest extends TestCase
{
    private const string PROBE = 'matview_lane_drift_probe';

    private const int LANE_NAMESPACE = 0x6D617464; // arbitrary advisory-lock namespace for this test

    private Connection $connection;

    private string $migrationsDir;

    protected function setUp(): void
    {
        $url = getenv('MATVIEW_TEST_DATABASE_URL');

        if (false === $url || '' === $url) {
            self::markTestSkipped('MATVIEW_TEST_DATABASE_URL is not set.');
        }

        try {
            $connection = DriverManager::getConnection(
                new DsnParser(['postgres' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql'])->parse($url),
            );
            $connection->executeQuery('SELECT 1');
        } catch (Throwable $exception) {
            self::markTestSkipped('Test database is not reachable: '.$exception->getMessage());
        }

        $this->connection = $connection;
        $this->cleanup();
        $this->migrationsDir = $this->writeMigration();
    }

    protected function tearDown(): void
    {
        $this->cleanup();

        if (isset($this->migrationsDir) && is_dir($this->migrationsDir)) {
            foreach (glob($this->migrationsDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->migrationsDir);
        }
    }

    public function testLaneSelfHealsADriftedMetadataStorageTableWithoutASeparateSyncStep(): void
    {
        $this->seedDriftedMetadataTable();

        $factory = $this->dependencyFactory();

        // Before the fix, this guard read on the drifted table throws — proving the bug exists.
        $guardStatusThrows = false;
        try {
            $factory->getMigrationStatusCalculator()->getNewMigrations();
        } catch (MetadataStorageError) {
            $guardStatusThrows = true;
        }
        self::assertTrue(
            $guardStatusThrows,
            'Sanity check: a drifted metadata table must make a raw status read throw, otherwise the fixture no longer reproduces the bug.',
        );

        $lane = new DoctrineLane(
            new DoctrineMigrationsLaneGuard($factory, self::LANE_NAMESPACE),
            new DoctrineMigrationsLaneMigrator($factory),
            new RecordingNoopViews(),
            LaneDropStrategy::ReactiveRetry,
        );

        // The whole point: no MetadataStorageError, and the pending migration is applied.
        $result = $lane->run();

        self::assertTrue($result->migrationsPending, 'The seeded migration must be seen as pending.');
        self::assertNotNull(
            $this->relationOid(self::PROBE),
            'The pending migration must have run, creating the probe table — proving the lane self-healed the drifted metadata storage.',
        );
    }

    private function dependencyFactory(): DependencyFactory
    {
        $configuration = new Configuration();
        $configuration->addMigrationsDirectory(
            'Th3Mouk\\MaterializedViewBundle\\Tests\\Integration\\DriftFixtures',
            $this->migrationsDir,
        );
        $configuration->setAllOrNothing(false);
        $configuration->setCheckDatabasePlatform(false);
        $configuration->setMetadataStorageConfiguration(new TableMetadataStorageConfiguration());

        return DependencyFactory::fromConnection(
            new ExistingConfiguration($configuration),
            new ExistingConnection($this->connection),
        );
    }

    /**
     * Create doctrine_migration_versions with a drifted schema: version is VARCHAR(255) instead of
     * the VARCHAR(191) doctrine/migrations expects, so its schema comparator reports a diff and a
     * status read throws MetadataStorageError until ensureInitialized() alters the table in place.
     */
    private function seedDriftedMetadataTable(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE doctrine_migration_versions (
                version VARCHAR(255) NOT NULL PRIMARY KEY,
                executed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                execution_time INTEGER DEFAULT NULL
            )
            SQL);
    }

    private function writeMigration(): string
    {
        $dir = sys_get_temp_dir().'/matview-lane-drift-'.bin2hex(random_bytes(4));
        mkdir($dir, 0o775, true);

        $probe = self::PROBE;
        $php = <<<PHP
            <?php

            declare(strict_types=1);

            namespace Th3Mouk\\MaterializedViewBundle\\Tests\\Integration\\DriftFixtures;

            use Doctrine\\DBAL\\Schema\\Schema;
            use Doctrine\\Migrations\\AbstractMigration;

            final class Version20240101000000 extends AbstractMigration
            {
                public function up(Schema \$schema): void
                {
                    \$this->addSql('CREATE TABLE {$probe} (id INT NOT NULL)');
                }

                public function down(Schema \$schema): void
                {
                    \$this->addSql('DROP TABLE {$probe}');
                }
            }
            PHP;

        file_put_contents($dir.'/Version20240101000000.php', $php);

        return $dir;
    }

    private function relationOid(string $relation): ?int
    {
        $oid = $this->connection->fetchOne('SELECT to_regclass(:relation)::oid', ['relation' => $relation]);

        return (null === $oid || false === $oid) ? null : (int) $oid;
    }

    private function cleanup(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS '.self::PROBE.' CASCADE');
        $this->connection->executeStatement('DROP TABLE IF EXISTS doctrine_migration_versions CASCADE');
    }
}

/**
 * Minimal ManagedViewOperations: the drift regression exercises only the migration path, so view
 * synchronisation is a no-op that returns an empty outcome.
 */
final readonly class RecordingNoopViews implements ManagedViewOperations
{
    public function dropAllManaged(): void
    {
    }

    public function dropConflictClosure(PostgresDependencyConflict $conflict): array
    {
        return [];
    }

    public function synchronize(): SyncOutcome
    {
        return SyncOutcome::of([], [], [], [], []);
    }
}
