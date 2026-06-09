<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Lane;

use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;

/**
 * Runs Doctrine Migrations in-process via the DependencyFactory, so the original DBAL
 * DriverException (and its SQLSTATE) propagates to the caller. The console-based migrator
 * cannot do this: it collapses any failure to a non-zero exit code, losing the exception
 * the reactive lane must branch on.
 *
 * Two in-process gotchas are encoded here:
 *   - A fresh DependencyFactory is built per call: a migration instance is frozen once it
 *     has run, so the reactive lane's retry — which re-invokes migrate() — must obtain new
 *     instances, exactly as separate `doctrine:migrations:migrate` invocations would.
 *   - The metadata storage is initialised before planning: a freshly template-cloned tenant
 *     database has no migration-versions table yet, which would otherwise abort planning.
 *
 * allOrNothing is disabled so a retry resumes from the version that failed rather than
 * rolling the whole batch back.
 */
final readonly class DoctrineMigrationsLaneMigrator implements LaneMigrator
{
    public function __construct(
        private DependencyFactory $dependencyFactory,
    ) {
    }

    public function migrate(bool $dryRun): void
    {
        $factory = $this->freshDependencyFactory();
        $factory->getMetadataStorage()->ensureInitialized();

        $plan = $factory->getMigrationPlanCalculator()->getPlanUntilVersion(
            $factory->getVersionAliasResolver()->resolveVersionAlias('latest'),
        );

        if (0 === \count($plan->getItems())) {
            return;
        }

        $factory->getMigrator()->migrate(
            $plan,
            new MigratorConfiguration()->setDryRun($dryRun)->setAllOrNothing(false),
        );
    }

    private function freshDependencyFactory(): DependencyFactory
    {
        return DependencyFactory::fromConnection(
            new ExistingConfiguration($this->dependencyFactory->getConfiguration()),
            new ExistingConnection($this->dependencyFactory->getConnection()),
        );
    }
}
