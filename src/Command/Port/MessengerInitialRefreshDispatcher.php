<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Command\Port;

use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\MessageBusInterface;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Refresh\AsyncRefreshRequest;
use Th3Mouk\MaterializedView\Core\Refresh\RefreshOptions;
use Th3Mouk\MaterializedViewBundle\Messenger\SharedTransportGuard;

final readonly class MessengerInitialRefreshDispatcher implements InitialRefreshDispatcher
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private Connection $connection,
        private SharedTransportGuard $sharedTransportGuard,
        private string $connectionName,
    ) {
    }

    public function dispatch(MaterializedViewDefinition $definition, bool $concurrently): void
    {
        $this->sharedTransportGuard->ensureAsyncIsSupported();

        $this->messageBus->dispatch(AsyncRefreshRequest::for(
            connectionName: $this->connectionName,
            databaseName: $this->connection->getDatabase() ?? $this->connectionName,
            viewName: $definition->name()->qualifiedName(),
            options: RefreshOptions::default()->withConcurrently($concurrently),
        ));
    }

    public function canDispatch(): bool
    {
        return $this->sharedTransportGuard->isSatisfied();
    }
}
