<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Th3Mouk\MaterializedView\Core\Introspection\PostgreSqlMaterializedViewIntrospector;
use Th3Mouk\MaterializedView\Core\Introspection\ReadinessChecker;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;
use Th3Mouk\MaterializedView\Core\Sync\MaterializedViewComparator;
use Th3Mouk\MaterializedView\Core\Sync\SyncAction;

#[AsCommand(
    name: 'matview:list',
    description: 'List declared materialized-view definitions and their real database state.',
)]
final class ListCommand extends Command
{
    public function __construct(
        private readonly MaterializedViewRegistry $registry,
        private readonly MaterializedViewComparator $comparator,
        private readonly PostgreSqlMaterializedViewIntrospector $introspector,
        private readonly ReadinessChecker $readinessChecker,
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

        $plan = $this->comparator->compare($this->registry);

        $rows = [];

        foreach ($this->registry->all() as $definition) {
            $name = $definition->name();
            $comparison = $plan->forName($name->qualifiedName());
            $live = $this->introspector->find($name);

            $rows[] = [
                $name->qualifiedName(),
                $definition->populationPolicy()->value,
                null === $live ? 'missing' : 'present',
                null === $live ? 'n/a' : ($this->readinessChecker->isReady($name) ? 'yes' : 'no'),
                $comparison?->action->value ?? SyncAction::Create->value,
            ];
        }

        $style->table(
            ['View', 'Population', 'Exists', 'Populated', 'State'],
            $rows,
        );

        return Command::SUCCESS;
    }
}
