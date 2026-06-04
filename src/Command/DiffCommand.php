<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Th3Mouk\MaterializedView\Core\Definition\PopulationPolicy;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;
use Th3Mouk\MaterializedView\Core\Sync\MaterializedViewComparator;
use Th3Mouk\MaterializedView\Core\Sync\MaterializedViewComparison;

#[AsCommand(
    name: 'matview:diff',
    description: 'Show the creations, changes, deletions and refreshes a sync would perform.',
)]
final class DiffCommand extends Command
{
    public function __construct(
        private readonly MaterializedViewRegistry $registry,
        private readonly MaterializedViewComparator $comparator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        $plan = $this->comparator->compare($this->registry);

        $this->renderSection($style, 'Create', $this->names($plan->toCreate()));
        $this->renderSection($style, 'Rebuild (drift)', $this->names($plan->toRebuild()));
        $this->renderSection($style, 'Delete (prune orphans)', $this->names($plan->orphans()));
        $this->renderSection($style, 'Refresh (Synchronous policy)', $this->refreshNames($plan->toCreate(), $plan->toRebuild()));
        $this->renderSection($style, 'Up to date', $this->names($plan->upToDate()));

        if (!$plan->hasPendingWrites() && [] === $plan->orphans()) {
            $style->success('Nothing to do: declared definitions match the database.');
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<MaterializedViewComparison> $comparisons
     *
     * @return list<string>
     */
    private function names(array $comparisons): array
    {
        return array_map(
            static fn (MaterializedViewComparison $comparison): string => $comparison->name->qualifiedName(),
            $comparisons,
        );
    }

    /**
     * @param list<MaterializedViewComparison> $created
     * @param list<MaterializedViewComparison> $rebuilt
     *
     * @return list<string>
     */
    private function refreshNames(array $created, array $rebuilt): array
    {
        $names = [];

        foreach ([...$created, ...$rebuilt] as $comparison) {
            if (PopulationPolicy::Synchronous === $comparison->definition?->populationPolicy()) {
                $names[] = $comparison->name->qualifiedName();
            }
        }

        return $names;
    }

    /**
     * @param list<string> $names
     */
    private function renderSection(SymfonyStyle $style, string $title, array $names): void
    {
        $style->section($title);

        if ([] === $names) {
            $style->writeln('  <fg=gray>(none)</>');

            return;
        }

        $style->listing($names);
    }
}
