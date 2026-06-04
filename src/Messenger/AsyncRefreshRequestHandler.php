<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Messenger;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Th3Mouk\MaterializedView\Core\MaterializedViewManager;
use Th3Mouk\MaterializedView\Core\Refresh\AsyncRefreshRequest;
use Th3Mouk\MaterializedView\Core\Refresh\RefreshTargetResolver;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;

#[AsMessageHandler]
final readonly class AsyncRefreshRequestHandler
{
    public function __construct(
        private MaterializedViewRegistry $registry,
        private RefreshTargetResolver $targetResolver,
        private SharedTransportGuard $sharedTransportGuard,
        private LoggerInterface $logger = new NullLogger(),
        private int $refreshLockNamespace = MaterializedViewManager::DEFAULT_REFRESH_LOCK_NAMESPACE,
    ) {
    }

    public function __invoke(AsyncRefreshRequest $request): void
    {
        $this->sharedTransportGuard->ensureAsyncIsSupported();

        $definition = $this->registry->get($request->viewName);

        $connection = $this->targetResolver->resolve($request);

        MaterializedViewManager::forConnection(
            connection: $connection,
            logger: $this->logger,
            refreshLockNamespace: $this->refreshLockNamespace,
        )->refresh($definition, $request->options);
    }
}
