<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Command\Port;

use Doctrine\Migrations\DependencyFactory;

final readonly class DoctrineMigrationsPendingInspector implements PendingMigrationsInspector
{
    public function __construct(
        private DependencyFactory $dependencyFactory,
    ) {
    }

    public function hasPendingMigrations(): bool
    {
        return \count($this->dependencyFactory->getMigrationStatusCalculator()->getNewMigrations()) > 0;
    }
}
