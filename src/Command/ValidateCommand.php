<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Dependency\ExternalDependencyGuard;
use Th3Mouk\MaterializedView\Core\Exception\CannotReadSqlSource;
use Th3Mouk\MaterializedView\Core\Introspection\PostgreSqlMaterializedViewIntrospector;
use Th3Mouk\MaterializedView\Core\Introspection\ReadinessChecker;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;
use Th3Mouk\MaterializedView\Core\Sync\MaterializedViewComparator;
use Th3Mouk\MaterializedView\Core\Sync\SyncAction;

#[AsCommand(
    name: 'matview:validate',
    description: 'Validate SQL, indexes, hash, existence, CONCURRENTLY preconditions and unmanaged dependents.',
)]
final class ValidateCommand extends Command
{
    public function __construct(
        private readonly MaterializedViewRegistry $registry,
        private readonly MaterializedViewComparator $comparator,
        private readonly PostgreSqlMaterializedViewIntrospector $introspector,
        private readonly ReadinessChecker $readinessChecker,
        private readonly ExternalDependencyGuard $externalDependencyGuard,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        if (0 === $this->registry->count()) {
            $style->warning('No materialized-view definitions are declared.');

            return Command::SUCCESS;
        }

        $rows = [];
        $hasError = false;

        foreach ($this->registry->all() as $definition) {
            $issues = $this->issuesFor($definition);

            if ([] !== $issues) {
                $hasError = true;
            }

            $rows[] = [
                $definition->name()->qualifiedName(),
                [] === $issues ? '<fg=green>ok</>' : '<fg=red>error</>',
                [] === $issues ? '-' : implode("\n", $issues),
            ];
        }

        $style->table(['View', 'Status', 'Issues'], $rows);

        if ($hasError) {
            $style->error('Validation failed.');

            return Command::FAILURE;
        }

        $style->success('All declared materialized views are valid.');

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function issuesFor(MaterializedViewDefinition $definition): array
    {
        $issues = [];
        $name = $definition->name();

        $sqlReadable = false;

        if (!$definition->hasSqlSource()) {
            $issues[] = 'No SQL source declared.';
        } else {
            try {
                $definition->sqlSource()->sql();
                $sqlReadable = true;
            } catch (CannotReadSqlSource $exception) {
                $issues[] = $exception->getMessage();
            }
        }

        $liveState = $this->introspector->find($name);

        if (null === $liveState) {
            $issues[] = 'View does not exist in the database (run matview:sync).';
        } elseif ($sqlReadable && SyncAction::Rebuild === $this->comparator->compareOne($definition, $liveState)->action) {
            $issues[] = 'Hash drift: the declared definition differs from the database.';
        }

        if ($this->refreshesConcurrently($definition)) {
            if (!$this->hasFullUniqueIndex($definition)) {
                $issues[] = 'Concurrent refresh requires a unique index covering all rows.';
            }

            if (null !== $liveState && !$this->readinessChecker->isReady($name)) {
                $issues[] = 'Concurrent refresh requires a populated view.';
            }
        }

        foreach ($this->externalDependencyGuard->unmanagedDependentsOf($name, $this->registry) as $dependent) {
            $issues[] = \sprintf('Unmanaged dependent "%s" blocks drop/rebuild/prune.', $dependent);
        }

        return $issues;
    }

    private function refreshesConcurrently(MaterializedViewDefinition $definition): bool
    {
        return array_any(
            $definition->indexes(),
            static fn ($index): bool => $index->concurrently,
        );
    }

    private function hasFullUniqueIndex(MaterializedViewDefinition $definition): bool
    {
        return array_any(
            $definition->indexes(),
            static fn ($index): bool => $index->coversAllRowsByColumnNamesOnly(),
        );
    }
}
