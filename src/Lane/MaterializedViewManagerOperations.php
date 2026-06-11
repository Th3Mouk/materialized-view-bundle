<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Lane;

use Doctrine\DBAL\Connection;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Dependency\CatalogDependencyResolver;
use Th3Mouk\MaterializedView\Core\Dependency\ExternalDependencyGuard;
use Th3Mouk\MaterializedView\Core\Dependency\PostgresDependencyConflict;
use Th3Mouk\MaterializedView\Core\MaterializedViewManager;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;
use Th3Mouk\MaterializedView\Core\Sync\SyncOptions;
use Th3Mouk\MaterializedView\Core\Sync\SyncOutcome;
use Throwable;

final readonly class MaterializedViewManagerOperations implements ManagedViewOperations
{
    private CatalogDependencyResolver $dependencyResolver;

    private ExternalDependencyGuard $externalDependencyGuard;

    public function __construct(
        private MaterializedViewManager $manager,
        private MaterializedViewRegistry $registry,
        private Connection $connection,
        // Null falls back to SyncOptions::default() in the manager; the lane passes the configured policies.
        private ?SyncOptions $syncOptions = null,
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

    public function dropConflictClosure(PostgresDependencyConflict $conflict): array
    {
        // The failed migration's own transaction was already rolled back before its
        // exception surfaced, so the surgical drop opens a fresh transaction of its own.
        $this->connection->beginTransaction();

        try {
            $dropped = $this->manager->dropConflictClosure($conflict);
            $this->connection->commit();

            return $dropped;
        } catch (Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }

    public function synchronize(): SyncOutcome
    {
        return $this->manager->syncAll($this->registry, $this->syncOptions);
    }
}
