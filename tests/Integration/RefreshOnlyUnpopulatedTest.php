<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Th3Mouk\MaterializedView\Core\Definition\InlineSqlSource;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Introspection\PostgreSqlMaterializedViewIntrospector;
use Th3Mouk\MaterializedView\Core\Introspection\ReadinessChecker;
use Th3Mouk\MaterializedView\Core\MaterializedViewManager;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;
use Th3Mouk\MaterializedView\Dbal\DbalConnection;
use Th3Mouk\MaterializedViewBundle\Command\RefreshCommand;

/**
 * `matview:refresh --only-unpopulated` against real PostgreSQL: populated views stay
 * untouched, unpopulated ones are refreshed in dependency order, nothing to do exits 0.
 *
 * Skipped when MATVIEW_TEST_DATABASE_URL is unset or unreachable.
 */
#[Group('refresh')]
final class RefreshOnlyUnpopulatedTest extends TestCase
{
    private const string BASE = 'matview_refresh_only_base';

    private const string ROLLUP = 'matview_refresh_only_rollup';

    private Connection $connection;

    private MaterializedViewRegistry $registry;

    protected function setUp(): void
    {
        $url = getenv('MATVIEW_TEST_DATABASE_URL');

        if (false === $url || '' === $url) {
            self::markTestSkipped('MATVIEW_TEST_DATABASE_URL is not set.');
        }

        $parser = new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
        $this->connection = DriverManager::getConnection($parser->parse($url));
        $this->dropObjects();

        $this->connection->executeStatement('CREATE TABLE public.matview_refresh_only_source (id integer NOT NULL)');

        $base = MaterializedViewDefinition::create('public.'.self::BASE)
            ->fromSql(InlineSqlSource::fromString('SELECT id FROM public.matview_refresh_only_source'))
            ->withNoData();
        $rollup = MaterializedViewDefinition::create('public.'.self::ROLLUP)
            ->fromSql(InlineSqlSource::fromString('SELECT id FROM public.'.self::BASE))
            ->withNoData();
        $this->registry = MaterializedViewRegistry::fromDefinitions([$rollup, $base]);

        $manager = MaterializedViewManager::forConnection($this->connection);
        $manager->create($base);
        $manager->create($rollup);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->dropObjects();
            $this->connection->close();
        }
    }

    public function testRefreshesOnlyTheUnpopulatedViewsThenHasNothingLeftToDo(): void
    {
        $this->connection->executeStatement('INSERT INTO public.matview_refresh_only_source (id) VALUES (1)');
        $this->connection->executeStatement('REFRESH MATERIALIZED VIEW public.'.self::BASE);
        $this->connection->executeStatement('INSERT INTO public.matview_refresh_only_source (id) VALUES (2)');

        $first = new CommandTester($this->command());
        $exit = $first->execute(['--all' => true, '--only-unpopulated' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Skipped "public.'.self::BASE.'": already populated.', $first->getDisplay());
        self::assertStringContainsString('Refreshed 1 materialized view(s).', $first->getDisplay());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM public.'.self::BASE));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM public.'.self::ROLLUP));

        $second = new CommandTester($this->command());

        self::assertSame(Command::SUCCESS, $second->execute(['--all' => true, '--only-unpopulated' => true]));
        self::assertStringContainsString('Refreshed 0 materialized view(s).', $second->getDisplay());
    }

    public function testASingleViewIsRefreshedOnlyWhenUnpopulated(): void
    {
        $first = new CommandTester($this->command());
        $first->execute(['name' => 'public.'.self::BASE, '--only-unpopulated' => true]);

        self::assertStringContainsString('Refreshed "public.'.self::BASE.'".', $first->getDisplay());

        $second = new CommandTester($this->command());

        self::assertSame(Command::SUCCESS, $second->execute(['name' => 'public.'.self::BASE, '--only-unpopulated' => true]));
        self::assertStringContainsString('Skipped "public.'.self::BASE.'": already populated.', $second->getDisplay());
    }

    private function command(): RefreshCommand
    {
        $driver = new DbalConnection($this->connection);

        return new RefreshCommand(
            $this->registry,
            MaterializedViewManager::forConnection($this->connection),
            new PostgreSqlMaterializedViewIntrospector($driver),
            new ReadinessChecker($driver),
            true,
            '10s',
            '0',
        );
    }

    private function dropObjects(): void
    {
        $this->connection->executeStatement('DROP MATERIALIZED VIEW IF EXISTS public.'.self::ROLLUP);
        $this->connection->executeStatement('DROP MATERIALIZED VIEW IF EXISTS public.'.self::BASE);
        $this->connection->executeStatement('DROP TABLE IF EXISTS public.matview_refresh_only_source');
    }
}
