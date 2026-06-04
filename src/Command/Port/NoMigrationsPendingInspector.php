<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Command\Port;

final readonly class NoMigrationsPendingInspector implements PendingMigrationsInspector
{
    public function hasPendingMigrations(): bool
    {
        return false;
    }
}
