<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Messenger;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ConnectionRegistry;
use Th3Mouk\MaterializedView\Core\Exception\CannotResolveRefreshTarget;
use Th3Mouk\MaterializedView\Core\Refresh\AsyncRefreshRequest;
use Th3Mouk\MaterializedView\Core\Refresh\RefreshTargetResolver;
use Throwable;

final readonly class ConnectionRegistryRefreshTargetResolver implements RefreshTargetResolver
{
    public function __construct(
        private ConnectionRegistry $connectionRegistry,
    ) {
    }

    public function resolve(AsyncRefreshRequest $request): Connection
    {
        return $this->hintedConnection($request)
            ?? $this->scanForRequestedDatabase($request)
            ?? throw CannotResolveRefreshTarget::forRequest($request);
    }

    private function hintedConnection(AsyncRefreshRequest $request): ?Connection
    {
        try {
            $connection = $this->connectionRegistry->getConnection($request->connectionName);
        } catch (Throwable) {
            return null;
        }

        if (!$connection instanceof Connection) {
            return null;
        }

        return $this->targetsRequestedDatabase($connection, $request) ? $connection : null;
    }

    private function scanForRequestedDatabase(AsyncRefreshRequest $request): ?Connection
    {
        foreach ($this->connectionRegistry->getConnections() as $connection) {
            if ($connection instanceof Connection && $this->targetsRequestedDatabase($connection, $request)) {
                return $connection;
            }
        }

        return null;
    }

    private function targetsRequestedDatabase(Connection $connection, AsyncRefreshRequest $request): bool
    {
        try {
            $database = $connection->getDatabase();
        } catch (Throwable) {
            return false;
        }

        return null !== $database && $database === $request->databaseName;
    }
}
