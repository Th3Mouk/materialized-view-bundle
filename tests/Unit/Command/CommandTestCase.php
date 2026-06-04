<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Unit\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Dependency\CatalogDependencyResolver;
use Th3Mouk\MaterializedView\Core\Dependency\ExternalDependencyGuard;
use Th3Mouk\MaterializedView\Core\Hashing\DefinitionHasher;
use Th3Mouk\MaterializedView\Core\Introspection\PostgreSqlMaterializedViewIntrospector;
use Th3Mouk\MaterializedView\Core\Introspection\ReadinessChecker;
use Th3Mouk\MaterializedView\Core\MaterializedViewManager;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;
use Th3Mouk\MaterializedView\Core\Sync\MaterializedViewComparator;

abstract class CommandTestCase extends TestCase
{
    /**
     * @return Connection&Stub
     */
    protected function emptyConnection(): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([]);
        $connection->method('fetchAssociative')->willReturn(false);
        $connection->method('fetchOne')->willReturn(false);
        $connection->method('executeStatement')->willReturn(0);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $identifier): string => '"'.$identifier.'"',
        );

        return $connection;
    }

    /**
     * @param iterable<MaterializedViewDefinition> $definitions
     */
    protected function registry(iterable $definitions): MaterializedViewRegistry
    {
        return MaterializedViewRegistry::fromDefinitions($definitions);
    }

    protected function comparator(Connection $connection): MaterializedViewComparator
    {
        return new MaterializedViewComparator(
            new PostgreSqlMaterializedViewIntrospector($connection),
            DefinitionHasher::create(),
        );
    }

    protected function introspector(Connection $connection): PostgreSqlMaterializedViewIntrospector
    {
        return new PostgreSqlMaterializedViewIntrospector($connection);
    }

    protected function readinessChecker(Connection $connection): ReadinessChecker
    {
        return new ReadinessChecker($connection);
    }

    protected function dependencyResolver(Connection $connection): CatalogDependencyResolver
    {
        return new CatalogDependencyResolver($connection);
    }

    protected function externalDependencyGuard(Connection $connection): ExternalDependencyGuard
    {
        return new ExternalDependencyGuard($this->dependencyResolver($connection));
    }

    protected function manager(Connection $connection): MaterializedViewManager
    {
        return MaterializedViewManager::forConnection($connection);
    }
}
