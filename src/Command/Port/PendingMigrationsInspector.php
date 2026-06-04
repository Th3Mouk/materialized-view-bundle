<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Command\Port;

interface PendingMigrationsInspector
{
    public function hasPendingMigrations(): bool;
}
