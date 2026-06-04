<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Th3Mouk\MaterializedView\Core\MaterializedViewManager;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;
use Th3Mouk\MaterializedView\Core\Sync\MaterializedViewComparator;
use Th3Mouk\MaterializedView\Core\Sync\SyncOptions;

#[AsCommand(
    name: 'matview:prune',
    description: 'Drop managed-but-undeclared materialized views (destructive).',
)]
final class PruneCommand extends Command
{
    public function __construct(
        private readonly MaterializedViewRegistry $registry,
        private readonly MaterializedViewManager $manager,
        private readonly MaterializedViewComparator $comparator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the orphans without dropping them.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        $orphans = array_map(
            static fn ($comparison): string => $comparison->name->qualifiedName(),
            $this->comparator->compare($this->registry)->orphans(),
        );

        if ([] === $orphans) {
            $style->success('No managed-but-undeclared materialized views to prune.');

            return Command::SUCCESS;
        }

        if ($input->getOption('dry-run')) {
            $style->section('Would prune');
            $style->listing($orphans);
            $style->note('Dry run: no views were dropped.');

            return Command::SUCCESS;
        }

        $outcome = $this->manager->syncAll($this->registry, SyncOptions::default()->withPrune());

        if ([] === $outcome->pruned) {
            $style->warning('No views were pruned (orphans may have unmanaged dependents).');

            return Command::SUCCESS;
        }

        $style->section('Pruned');
        $style->listing($outcome->pruned);
        $style->success(\sprintf('Pruned %d materialized view(s).', \count($outcome->pruned)));

        return Command::SUCCESS;
    }
}
