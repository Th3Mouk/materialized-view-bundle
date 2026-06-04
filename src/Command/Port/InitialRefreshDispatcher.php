<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Command\Port;

use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;

interface InitialRefreshDispatcher
{
    public function dispatch(MaterializedViewDefinition $definition, bool $concurrently): void;

    public function canDispatch(): bool;
}
