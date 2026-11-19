<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Lane;

use Doctrine\Migrations\Metadata\ExecutedMigrationsList;
use Doctrine\Migrations\Metadata\Storage\MetadataStorage;
use Doctrine\Migrations\Version\ExecutionResult;
use LogicException;

/**
 * Read-only view of a migration metadata storage, for planning a dry run.
 *
 * Doctrine appends its own bookkeeping statements (the comment and the insert into the
 * versions table) to a dry run's SQL whenever the storage exposes getSql(). This decorator
 * does not, so a dry run reports exactly the statements the migrations themselves emit.
 */
final readonly class DryRunMetadataStorage implements MetadataStorage
{
    public function __construct(private MetadataStorage $storage)
    {
    }

    public function ensureInitialized(): void
    {
        $this->storage->ensureInitialized();
    }

    public function getExecutedMigrations(): ExecutedMigrationsList
    {
        return $this->storage->getExecutedMigrations();
    }

    public function complete(ExecutionResult $result): void
    {
        throw new LogicException('A dry run never records a migration as executed.');
    }

    public function reset(): void
    {
        throw new LogicException('A dry run never resets the migration metadata storage.');
    }
}
