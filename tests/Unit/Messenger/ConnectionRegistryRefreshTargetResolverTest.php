<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Unit\Messenger;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ConnectionRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Th3Mouk\MaterializedView\Core\Exception\CannotResolveRefreshTarget;
use Th3Mouk\MaterializedView\Core\Refresh\AsyncRefreshRequest;
use Th3Mouk\MaterializedViewBundle\Messenger\ConnectionRegistryRefreshTargetResolver;

#[Group('materialized-view-bundle')]
final class ConnectionRegistryRefreshTargetResolverTest extends TestCase
{
    public function testResolvesTheConnectionWhenItTargetsTheRequestedDatabase(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabase')->willReturn('reporting_a');

        $resolver = new ConnectionRegistryRefreshTargetResolver(
            $this->registryReturning($connection),
        );

        $resolved = $resolver->resolve($this->request(connectionName: 'reporting', databaseName: 'reporting_a'));

        self::assertSame($connection, $resolved);
    }

    public function testRefusesWhenTheResolvedConnectionTargetsAnotherDatabase(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabase')->willReturn('reporting_x');

        $resolver = new ConnectionRegistryRefreshTargetResolver(
            $this->registryReturning($connection),
        );

        $this->expectException(CannotResolveRefreshTarget::class);

        $resolver->resolve($this->request(connectionName: 'reporting', databaseName: 'reporting_a'));
    }

    public function testRefusesWhenTheConnectionHasNoResolvableDatabase(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabase')->willReturn(null);

        $resolver = new ConnectionRegistryRefreshTargetResolver(
            $this->registryReturning($connection),
        );

        $this->expectException(CannotResolveRefreshTarget::class);

        $resolver->resolve($this->request(connectionName: 'reporting', databaseName: 'reporting_a'));
    }

    public function testRefusesWhenTheRegistryReturnsANonDbalConnection(): void
    {
        $registry = $this->createStub(ConnectionRegistry::class);
        $registry->method('getConnection')->willReturn(new stdClass());

        $resolver = new ConnectionRegistryRefreshTargetResolver($registry);

        $this->expectException(CannotResolveRefreshTarget::class);

        $resolver->resolve($this->request(connectionName: 'reporting', databaseName: 'reporting_a'));
    }

    public function testRefusesWhenTheConnectionNameIsUnknown(): void
    {
        $registry = $this->createStub(ConnectionRegistry::class);
        $registry->method('getConnection')
            ->willThrowException(new InvalidArgumentException('Unknown connection.'));

        $resolver = new ConnectionRegistryRefreshTargetResolver($registry);

        $this->expectException(CannotResolveRefreshTarget::class);

        $resolver->resolve($this->request(connectionName: 'ghost', databaseName: 'reporting_a'));
    }

    public function testRefusesWhenReadingTheDatabaseFails(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabase')->willThrowException(new RuntimeException('down'));

        $resolver = new ConnectionRegistryRefreshTargetResolver(
            $this->registryReturning($connection),
        );

        $this->expectException(CannotResolveRefreshTarget::class);

        $resolver->resolve($this->request(connectionName: 'reporting', databaseName: 'reporting_a'));
    }

    public function testSelectsTheConnectionTargetingTheRequestedDatabaseWhenTheHintPointsElsewhere(): void
    {
        $hinted = $this->createStub(Connection::class);
        $hinted->method('getDatabase')->willReturn('reporting_b');

        $matching = $this->createStub(Connection::class);
        $matching->method('getDatabase')->willReturn('reporting_a');

        $registry = $this->createStub(ConnectionRegistry::class);
        $registry->method('getConnection')->willReturn($hinted);
        $registry->method('getConnections')->willReturn([
            'default' => $hinted,
            'reporting_a' => $matching,
        ]);

        $resolver = new ConnectionRegistryRefreshTargetResolver($registry);

        $resolved = $resolver->resolve($this->request(connectionName: 'default', databaseName: 'reporting_a'));

        self::assertSame($matching, $resolved);
    }

    private function registryReturning(Connection $connection): ConnectionRegistry
    {
        $registry = $this->createStub(ConnectionRegistry::class);
        $registry->method('getConnection')->willReturn($connection);

        return $registry;
    }

    private function request(string $connectionName, string $databaseName): AsyncRefreshRequest
    {
        return AsyncRefreshRequest::for(
            connectionName: $connectionName,
            databaseName: $databaseName,
            viewName: 'public.sales_by_category',
        );
    }
}
