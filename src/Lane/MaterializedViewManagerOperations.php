<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Lane;

use Doctrine\DBAL\Connection;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Dependency\CatalogDependencyResolver;
use Th3Mouk\MaterializedView\Core\Dependency\ExternalDependencyGuard;
use Th3Mouk\MaterializedView\Core\MaterializedViewManager;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;
use Th3Mouk\MaterializedView\Core\Sync\SyncOutcome;

final readonly class MaterializedViewManagerOperations implements ManagedViewOperations
{
    private CatalogDependencyResolver $dependencyResolver;

    private ExternalDependencyGuard $externalDependencyGuard;

    public function __construct(
        private MaterializedViewManager $manager,
        private MaterializedViewRegistry $registry,
        Connection $connection,
    ) {
        $this->dependencyResolver = new CatalogDependencyResolver($connection);
        $this->externalDependencyGuard = new ExternalDependencyGuard($this->dependencyResolver);
    }

    public function dropAllManaged(): void
    {
        foreach ($this->registry->all() as $definition) {
            $this->externalDependencyGuard->assertSafeToDrop($definition->name(), $this->registry);
        }

        foreach ($this->dependencyResolver->orderedForDrop($this->registry) as $qualifiedName) {
            $this->manager->drop(MaterializedViewName::fromString($qualifiedName));
        }
    }

    public function synchronize(): SyncOutcome
    {
        return $this->manager->syncAll($this->registry);
    }
}
