<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Command\Port;

interface MaterializedViewScaffolder
{
    public function scaffold(string $viewName, bool $bump): ScaffoldResult;
}
