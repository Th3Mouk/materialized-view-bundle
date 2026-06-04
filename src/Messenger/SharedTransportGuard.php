<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Messenger;

final readonly class SharedTransportGuard
{
    public function __construct(
        private bool $requireSharedTransport,
        private TransportScope $transportScope,
    ) {
    }

    public function isSatisfied(): bool
    {
        return !$this->requireSharedTransport || $this->transportScope->isShared();
    }

    /**
     * @throws AsyncRefreshRequiresSharedTransport
     */
    public function ensureAsyncIsSupported(): void
    {
        if (!$this->isSatisfied()) {
            throw AsyncRefreshRequiresSharedTransport::forScope($this->transportScope);
        }
    }
}
