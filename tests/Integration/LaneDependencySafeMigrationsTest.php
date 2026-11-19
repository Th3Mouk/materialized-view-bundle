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
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Th3Mouk\MaterializedView\Core\Definition\InlineSqlSource;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\MaterializedViewManager;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;
use Th3Mouk\MaterializedView\Core\Sync\SyncOptions;
use Th3Mouk\MaterializedViewBundle\Lane\DoctrineLane;
use Th3Mouk\MaterializedViewBundle\Lane\DoctrineMigrationsLaneGuard;
use Th3Mouk\MaterializedViewBundle\Lane\DoctrineMigrationsLaneMigrator;
use Th3Mouk\MaterializedViewBundle\Lane\LaneDropStrategy;
use Th3Mouk\MaterializedViewBundle\Lane\MaterializedViewManagerOperations;
use Throwable;

/**
 * Under reactive_retry, a pending non-transactional migration made only of dependency-safe
 * statements (index builds, constraint validation, maintenance) must no longer drop every
 * managed materialized view, while one that can raise a dependency conflict, or whose SQL
 * cannot be collected, keeps the drop-all fallback. Runs the real lane against Doctrine
 * Migrations and PostgreSQL; the SQL is collected through the migrations' own dry run.
 *
 * Skipped when MATVIEW_TEST_DATABASE_URL is unset or unreachable.
 */
#[Group('lane')]
final class LaneDependencySafeMigrationsTest extends TestCase
{
    private const string BASE = 'matview_safe_mig_base';

    private const string VIEW = 'public.matview_safe_mig_mv';

    private const int LANE_NAMESPACE = 0x6D617465;

    private Connection $connection;

    private string $migrationsDir;

    private string $namespace;

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

        $suffix = bin2hex(random_bytes(4));
        $this->migrationsDir = sys_get_temp_dir().'/matview-lane-safe-'.$suffix;
        $this->namespace = 'Th3Mouk\\MaterializedViewBundle\\Tests\\Integration\\SafeFixtures\\N'.$suffix;
        mkdir($this->migrationsDir, 0o775, true);

        $this->connection->executeStatement('CREATE TABLE '.self::BASE.' (id int PRIMARY KEY, label text)');
        $this->connection->executeStatement('ALTER TABLE '.self::BASE.' ADD CONSTRAINT chk_positive CHECK (id > 0) NOT VALID');
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

    public function testDependencySafeNonTransactionalMigrationKeepsTheManagedViews(): void
    {
        $this->writeMigration('20240101000000', [
            'CREATE UNIQUE INDEX CONCURRENTLY uniq_safe_mig_label ON '.self::BASE.' (label)',
            'ALTER TABLE '.self::BASE.' VALIDATE CONSTRAINT chk_positive',
            'ANALYZE '.self::BASE,
        ]);
        $operations = $this->operations();
        $operations->synchronize();
        $viewOid = $this->relationOid(self::VIEW);
        self::assertNotNull($viewOid);

        $factory = $this->dependencyFactory();
        $guard = new DoctrineMigrationsLaneGuard($factory, self::LANE_NAMESPACE);
        $guard->ensureMetadataInitialized();

        self::assertTrue($guard->hasNonTransactionalPendingMigrations());
        self::assertSame([], $guard->dependencyUnsafeNonTransactionalMigrations());

        $result = new DoctrineLane($guard, new DoctrineMigrationsLaneMigrator($factory), $operations, LaneDropStrategy::ReactiveRetry)->run();

        self::assertTrue($result->migrationsPending);
        self::assertFalse($result->managedViewsDropped);
        self::assertSame($viewOid, $this->relationOid(self::VIEW), 'The managed view must not have been dropped and recreated.');
        self::assertNotNull($this->relationOid('public.uniq_safe_mig_label'), 'The pending migration must have run on a factory the dry run left untouched.');
    }

    public function testMigrationWithAStatementThatCanConflictKeepsTheDropAllFallback(): void
    {
        $this->writeMigration('20240101000000', [
            'CREATE INDEX CONCURRENTLY idx_safe_mig_label ON '.self::BASE.' (label)',
            'ALTER TABLE '.self::BASE.' ALTER COLUMN label TYPE varchar(100)',
        ]);
        $operations = $this->operations();
        $operations->synchronize();
        $viewOid = $this->relationOid(self::VIEW);

        $factory = $this->dependencyFactory();
        $guard = new DoctrineMigrationsLaneGuard($factory, self::LANE_NAMESPACE);
        $guard->ensureMetadataInitialized();

        self::assertSame(['Version20240101000000'], array_map($this->shortVersion(...), $guard->dependencyUnsafeNonTransactionalMigrations()));

        $result = new DoctrineLane($guard, new DoctrineMigrationsLaneMigrator($factory), $operations, LaneDropStrategy::ReactiveRetry)->run();

        self::assertTrue($result->managedViewsDropped);
        self::assertNotSame($viewOid, $this->relationOid(self::VIEW), 'The drop-all fallback must have recreated the view.');
    }

    public function testMigrationWhoseSqlCannotBeCollectedIsTreatedAsUnsafe(): void
    {
        $this->writeMigration('20240101000000', [], throwsInUp: true);

        $guard = new DoctrineMigrationsLaneGuard($this->dependencyFactory(), self::LANE_NAMESPACE);
        $guard->ensureMetadataInitialized();

        self::assertSame(['Version20240101000000'], array_map($this->shortVersion(...), $guard->dependencyUnsafeNonTransactionalMigrations()));
    }

    private function shortVersion(string $version): string
    {
        return substr($version, (int) strrpos($version, '\\') + 1);
    }

    private function operations(): MaterializedViewManagerOperations
    {
        $registry = MaterializedViewRegistry::fromDefinitions([
            MaterializedViewDefinition::create(self::VIEW)->fromSql(
                InlineSqlSource::fromString('SELECT id, label FROM '.self::BASE),
            ),
        ]);

        return new MaterializedViewManagerOperations(
            MaterializedViewManager::forConnection($this->connection, new NullLogger()),
            $registry,
            $this->connection,
            SyncOptions::default(),
        );
    }

    private function dependencyFactory(): DependencyFactory
    {
        $configuration = new Configuration();
        $configuration->addMigrationsDirectory($this->namespace, $this->migrationsDir);
        $configuration->setAllOrNothing(false);
        $configuration->setCheckDatabasePlatform(false);
        $configuration->setMetadataStorageConfiguration(new TableMetadataStorageConfiguration());

        return DependencyFactory::fromConnection(
            new ExistingConfiguration($configuration),
            new ExistingConnection($this->connection),
        );
    }

    /**
     * @param list<string> $statements
     */
    private function writeMigration(string $version, array $statements, bool $throwsInUp = false): void
    {
        $body = $throwsInUp
            ? "throw new \\RuntimeException('cannot plan');"
            : implode("\n        ", array_map(
                static fn (string $statement): string => '$this->addSql('.var_export($statement, true).');',
                $statements,
            ));

        $php = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$this->namespace};

            use Doctrine\\DBAL\\Schema\\Schema;
            use Doctrine\\Migrations\\AbstractMigration;

            final class Version{$version} extends AbstractMigration
            {
                public function isTransactional(): bool
                {
                    return false;
                }

                public function up(Schema \$schema): void
                {
                    {$body}
                }

                public function down(Schema \$schema): void
                {
                }
            }
            PHP;

        file_put_contents($this->migrationsDir.'/Version'.$version.'.php', $php);
    }

    private function relationOid(string $relation): ?int
    {
        $oid = $this->connection->fetchOne('SELECT to_regclass(:relation)::oid', ['relation' => $relation]);

        return (null === $oid || false === $oid) ? null : (int) $oid;
    }

    private function cleanup(): void
    {
        $this->connection->executeStatement('DROP MATERIALIZED VIEW IF EXISTS '.self::VIEW.' CASCADE');
        $this->connection->executeStatement('DROP TABLE IF EXISTS '.self::BASE.' CASCADE');
        $this->connection->executeStatement('DROP TABLE IF EXISTS doctrine_migration_versions CASCADE');
    }
}
