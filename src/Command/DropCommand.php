<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Th3Mouk\MaterializedView\Core\Dependency\CatalogDependencyResolver;
use Th3Mouk\MaterializedView\Core\Dependency\DropDependentPolicy;
use Th3Mouk\MaterializedView\Core\MaterializedViewManager;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;
use Th3Mouk\MaterializedViewBundle\Command\Port\PendingMigrationsInspector;

#[AsCommand(
    name: 'matview:drop',
    description: 'Drop managed materialized views to free the way before table migrations.',
)]
final class DropCommand extends Command
{
    public function __construct(
        private readonly MaterializedViewRegistry $registry,
        private readonly MaterializedViewManager $manager,
        private readonly CatalogDependencyResolver $dependencyResolver,
        private readonly PendingMigrationsInspector $pendingMigrationsInspector,
        private readonly DropDependentPolicy $dropDependentPolicy,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('if-pending', null, InputOption::VALUE_NONE, 'Drop only when Doctrine migrations are pending.')
            ->addOption('all-managed', null, InputOption::VALUE_NONE, 'Drop all managed views unconditionally.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the drops without executing them.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        $ifPending = (bool) $input->getOption('if-pending');
        $allManaged = (bool) $input->getOption('all-managed');

        if (!$ifPending && !$allManaged) {
            $style->error('Specify --if-pending or --all-managed.');

            return Command::INVALID;
        }

        if ($ifPending && !$allManaged && !$this->pendingMigrationsInspector->hasPendingMigrations()) {
            $style->success('No pending migrations: nothing to drop.');

            return Command::SUCCESS;
        }

        $dropOrder = $this->dependencyResolver->orderedForDrop($this->registry);

        if ([] === $dropOrder) {
            $style->success('No managed materialized views to drop.');

            return Command::SUCCESS;
        }

        if ($input->getOption('dry-run')) {
            $style->section('Would drop');
            $style->listing($dropOrder);
            $style->note('Dry run: no views were dropped.');

            return Command::SUCCESS;
        }

        foreach ($dropOrder as $qualifiedName) {
            $this->manager->drop(
                $this->registry->get($qualifiedName)->name(),
                dropDependentPolicy: $this->dropDependentPolicy,
            );
        }

        $style->section('Dropped');
        $style->listing($dropOrder);
        $style->success(\sprintf('Dropped %d managed materialized view(s).', \count($dropOrder)));

        return Command::SUCCESS;
    }
}
