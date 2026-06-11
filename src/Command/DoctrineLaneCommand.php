<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Command;

use Doctrine\Migrations\DependencyFactory;
use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Th3Mouk\MaterializedView\Core\Dependency\DropDependentPolicy;
use Th3Mouk\MaterializedView\Core\MaterializedViewManager;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;
use Th3Mouk\MaterializedView\Core\Sync\MissingDependencyPolicy;
use Th3Mouk\MaterializedView\Core\Sync\SyncOptions;
use Th3Mouk\MaterializedView\Core\Sync\SyncOutcome;
use Th3Mouk\MaterializedViewBundle\Lane\ConsoleLaneMigrator;
use Th3Mouk\MaterializedViewBundle\Lane\DoctrineLane;
use Th3Mouk\MaterializedViewBundle\Lane\DoctrineMigrationsLaneGuard;
use Th3Mouk\MaterializedViewBundle\Lane\DoctrineMigrationsLaneMigrator;
use Th3Mouk\MaterializedViewBundle\Lane\LaneDropStrategy;
use Th3Mouk\MaterializedViewBundle\Lane\LaneMigrator;
use Th3Mouk\MaterializedViewBundle\Lane\LaneResult;
use Th3Mouk\MaterializedViewBundle\Lane\MaterializedViewManagerOperations;

#[AsCommand(
    name: 'matview:doctrine-lane',
    description: 'Run drop-if-pending -> migrate -> sync in a single advisory-locked process, per database.',
)]
final class DoctrineLaneCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'doctrine.migrations.dependency_factory')]
        private readonly DependencyFactory $dependencyFactory,
        private readonly MaterializedViewRegistry $registry,
        #[Autowire(param: 'th3mouk_materialized_view.lane.lock_namespace')]
        private readonly int $laneNamespace,
        #[Autowire(param: 'th3mouk_materialized_view.lane.drop_strategy')]
        private readonly string $dropStrategy = 'all_on_pending',
        // Without these the lane fell back to SyncOptions::default() and ignored a configured
        // on_missing_dependency=skip at boot.
        #[Autowire(service: 'th3mouk_materialized_view.sync.on_missing_dependency')]
        private readonly MissingDependencyPolicy $missingDependencyPolicy = MissingDependencyPolicy::Fail,
        #[Autowire(service: 'th3mouk_materialized_view.drop.on_external_dependent')]
        private readonly DropDependentPolicy $dropDependentPolicy = DropDependentPolicy::Refuse,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Report pending migrations and run the migration step in dry-run mode without dropping or synchronising views.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $connection = $this->dependencyFactory->getConnection();
        $strategy = LaneDropStrategy::from($this->dropStrategy);

        $syncOptions = SyncOptions::default()
            ->withMissingDependencyPolicy($this->missingDependencyPolicy)
            ->withDropDependentPolicy($this->dropDependentPolicy);

        $this->logger->info('Lane starting.', [
            'drop_strategy' => $strategy->value,
            'missing_dependency_policy' => $this->missingDependencyPolicy->value,
            'drop_dependent_policy' => $this->dropDependentPolicy->value,
            'dry_run' => $dryRun,
        ]);

        $lane = new DoctrineLane(
            guard: new DoctrineMigrationsLaneGuard($this->dependencyFactory, $this->laneNamespace, $this->logger),
            migrator: $this->migratorFor($strategy, $output),
            views: new MaterializedViewManagerOperations(
                MaterializedViewManager::forConnection($connection, $this->logger),
                $this->registry,
                $connection,
                $syncOptions,
            ),
            strategy: $strategy,
            logger: $this->logger,
        );

        $result = $lane->run($dryRun);

        $this->report($io, $result);

        return Command::SUCCESS;
    }

    private function requireApplication(): Application
    {
        return $this->getApplication()
            ?? throw new LogicException('The lane command must be registered in a console Application.');
    }

    private function migratorFor(LaneDropStrategy $strategy, OutputInterface $output): LaneMigrator
    {
        // The reactive strategy must see the original DBAL DriverException to branch on its
        // SQLSTATE; the console migrator only exposes an exit code, so it drives migrations
        // in-process instead.
        if (LaneDropStrategy::ReactiveRetry === $strategy) {
            return new DoctrineMigrationsLaneMigrator($this->dependencyFactory, $this->logger);
        }

        return new ConsoleLaneMigrator($this->requireApplication(), $output);
    }

    private function report(SymfonyStyle $io, LaneResult $result): void
    {
        if ($result->lockContended) {
            $io->warning('Another process holds the lane lock on this database; dry-run skipped.');

            return;
        }

        if ($result->dryRun) {
            $io->success(\sprintf(
                'Dry-run complete. Migrations pending: %s.',
                $result->migrationsPending ? 'yes' : 'no',
            ));

            return;
        }

        $this->reportOutcome($io, $result->outcome);
    }

    private function reportOutcome(SymfonyStyle $io, ?SyncOutcome $outcome): void
    {
        if (!$outcome instanceof SyncOutcome) {
            $io->success('Lane complete.');

            return;
        }

        $io->success(\sprintf(
            'Lane complete. Created: %d, rebuilt: %d, pruned: %d, up to date: %d.',
            \count($outcome->created),
            \count($outcome->rebuilt),
            \count($outcome->pruned),
            \count($outcome->upToDate),
        ));
    }
}
