<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Th3Mouk\MaterializedView\Core\Definition\InlineSqlSource;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\MaterializedViewManager;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;
use Th3Mouk\MaterializedView\Core\Sync\MissingDependencyPolicy;
use Th3Mouk\MaterializedView\Core\Sync\SyncOptions;
use Th3Mouk\MaterializedViewBundle\Lane\MaterializedViewManagerOperations;
use Throwable;

/**
 * End-to-end check, against a real PostgreSQL, that the deploy lane's view synchronisation
 * honours the configured missing-dependency policy — the regression fixed in 1.2.1, where the
 * lane always used SyncOptions::default() (fail) and ignored a configured skip.
 *
 * Skipped when MATVIEW_TEST_DATABASE_URL is unset or unreachable.
 */
#[Group('lane')]
final class LaneMissingDependencyPolicyTest extends TestCase
{
    private const string VIEW = 'public.matview_lane_missing_dep_probe';

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
        $this->dropProbe();
    }

    protected function tearDown(): void
    {
        $this->dropProbe();
    }

    public function testSkipPolicyToleratesAViewWithAMissingDependency(): void
    {
        $outcome = $this->operations(MissingDependencyPolicy::Skip)->synchronize();

        self::assertContains(self::VIEW, $outcome->skipped);
        self::assertNotContains(self::VIEW, $outcome->created);
    }

    public function testFailPolicyAbortsOnAViewWithAMissingDependency(): void
    {
        $this->expectException(DbalException::class);

        $this->operations(MissingDependencyPolicy::Fail)->synchronize();
    }

    private function operations(MissingDependencyPolicy $policy): MaterializedViewManagerOperations
    {
        return new MaterializedViewManagerOperations(
            MaterializedViewManager::forConnection($this->connection, new NullLogger()),
            $this->registry(),
            $this->connection,
            SyncOptions::default()->withMissingDependencyPolicy($policy),
        );
    }

    private function registry(): MaterializedViewRegistry
    {
        return MaterializedViewRegistry::fromDefinitions([
            MaterializedViewDefinition::create(self::VIEW)->fromSql(
                InlineSqlSource::fromString('SELECT 1 AS id FROM matview_absent_schema.absent_table'),
            ),
        ]);
    }

    private function dropProbe(): void
    {
        $this->connection->executeStatement('DROP MATERIALIZED VIEW IF EXISTS '.self::VIEW.' CASCADE');
    }
}
