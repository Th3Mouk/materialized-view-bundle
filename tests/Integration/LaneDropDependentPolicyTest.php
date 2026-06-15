<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Th3Mouk\MaterializedView\Core\Definition\InlineSqlSource;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Dependency\DropDependentPolicy;
use Th3Mouk\MaterializedView\Core\Dependency\PostgresDependencyConflict;
use Th3Mouk\MaterializedView\Core\Exception\UnmanagedDependentFound;
use Th3Mouk\MaterializedView\Core\MaterializedViewManager;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;
use Th3Mouk\MaterializedView\Core\Sql\ManagementMarker;
use Th3Mouk\MaterializedView\Core\Sync\SyncOptions;
use Th3Mouk\MaterializedViewBundle\Lane\MaterializedViewManagerOperations;
use Throwable;

/**
 * End-to-end check, against a real PostgreSQL, that the deploy lane threads the configured
 * drop.on_external_dependent policy into the reactive drop AND the drop-all fallback — so an
 * unmanaged dependent (e.g. a Superset view) is cascaded under `cascade` and refused under
 * `refuse`, the same way synchronisation already behaves. 1.2.1 wired the policy into
 * synchronise() only; this covers the remaining dropConflictClosure()/dropAllManaged() paths.
 *
 * Skipped when MATVIEW_TEST_DATABASE_URL is unset or unreachable.
 */
#[Group('lane')]
final class LaneDropDependentPolicyTest extends TestCase
{
    private const string BASE = 'matview_drop_policy_base';

    private const string VIEW = 'public.matview_drop_policy_mv';

    private const string CONSUMER = 'public.matview_drop_policy_consumer';

    private Connection $connection;

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
        $this->seed();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testReactiveDropCascadesThroughAnUnmanagedDependent(): void
    {
        $dropped = $this->operations(DropDependentPolicy::Cascade)->dropConflictClosure($this->conflictOnBase());

        self::assertSame(
            [self::VIEW],
            array_map(static fn (MaterializedViewName $name): string => $name->qualifiedName(), $dropped),
        );
        self::assertNull($this->relationOid(self::VIEW), 'The managed matview must be dropped.');
        self::assertNull($this->relationOid(self::CONSUMER), 'The unmanaged dependent must be cascaded.');
    }

    public function testReactiveDropRefusesAnUnmanagedDependentUnderRefuse(): void
    {
        try {
            $this->operations(DropDependentPolicy::Refuse)->dropConflictClosure($this->conflictOnBase());
            self::fail('Expected the unmanaged dependent to block the reactive drop.');
        } catch (UnmanagedDependentFound $exception) {
            self::assertStringContainsString('matview_drop_policy_consumer', $exception->getMessage());
        }

        self::assertNotNull($this->relationOid(self::VIEW), 'A refused drop must leave the matview intact.');
        self::assertNotNull($this->relationOid(self::CONSUMER), 'A refused drop must leave the dependent intact.');
    }

    public function testDropAllManagedCascadesThroughAnUnmanagedDependent(): void
    {
        $this->operations(DropDependentPolicy::Cascade)->dropAllManaged();

        self::assertNull($this->relationOid(self::VIEW));
        self::assertNull($this->relationOid(self::CONSUMER));
    }

    private function operations(DropDependentPolicy $policy): MaterializedViewManagerOperations
    {
        return new MaterializedViewManagerOperations(
            MaterializedViewManager::forConnection($this->connection, new NullLogger()),
            $this->registry(),
            $this->connection,
            SyncOptions::default()->withDropDependentPolicy($policy),
        );
    }

    private function registry(): MaterializedViewRegistry
    {
        return MaterializedViewRegistry::fromDefinitions([
            MaterializedViewDefinition::create(self::VIEW)->fromSql(
                InlineSqlSource::fromString('SELECT id FROM '.self::BASE),
            ),
        ]);
    }

    private function conflictOnBase(): PostgresDependencyConflict
    {
        $conflict = PostgresDependencyConflict::fromRawError(
            '2BP01',
            'ERROR:  cannot drop table '.self::BASE.' because other objects depend on it',
        );
        self::assertNotNull($conflict);

        return $conflict;
    }

    private function relationOid(string $relation): ?int
    {
        $oid = $this->connection->fetchOne('SELECT to_regclass(:relation)::oid', ['relation' => $relation]);

        return (null === $oid || false === $oid) ? null : (int) $oid;
    }

    private function seed(): void
    {
        $this->connection->executeStatement('CREATE TABLE '.self::BASE.' (id int)');
        $this->connection->executeStatement('CREATE MATERIALIZED VIEW '.self::VIEW.' AS SELECT id FROM '.self::BASE);
        $this->connection->executeStatement(\sprintf(
            'COMMENT ON MATERIALIZED VIEW %s IS %s',
            self::VIEW,
            $this->connection->getDatabasePlatform()->quoteStringLiteral(ManagementMarker::create('test-hash')->toJson()),
        ));
        $this->connection->executeStatement('CREATE VIEW '.self::CONSUMER.' AS SELECT id FROM '.self::VIEW);
    }

    private function cleanup(): void
    {
        $this->connection->executeStatement('DROP VIEW IF EXISTS '.self::CONSUMER.' CASCADE');
        $this->connection->executeStatement('DROP MATERIALIZED VIEW IF EXISTS '.self::VIEW.' CASCADE');
        $this->connection->executeStatement('DROP TABLE IF EXISTS '.self::BASE.' CASCADE');
    }
}
