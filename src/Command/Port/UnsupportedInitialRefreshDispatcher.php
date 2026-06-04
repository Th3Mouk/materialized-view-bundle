<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Command\Port;

use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;

final readonly class UnsupportedInitialRefreshDispatcher implements InitialRefreshDispatcher
{
    public function dispatch(MaterializedViewDefinition $definition, bool $concurrently): void
    {
        throw InitialRefreshDispatchUnavailable::create();
    }

    public function canDispatch(): bool
    {
        return false;
    }
}
