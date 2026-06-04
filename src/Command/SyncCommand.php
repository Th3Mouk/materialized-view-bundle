<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\RebuildStrategy;
use Th3Mouk\MaterializedView\Core\Dependency\DropDependentPolicy;
use Th3Mouk\MaterializedView\Core\MaterializedViewManager;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;
use Th3Mouk\MaterializedView\Core\Sync\MaterializedViewComparator;
use Th3Mouk\MaterializedView\Core\Sync\MaterializedViewComparison;
use Th3Mouk\MaterializedView\Core\Sync\MissingDependencyPolicy;
use Th3Mouk\MaterializedView\Core\Sync\SyncOptions;
use Th3Mouk\MaterializedViewBundle\Command\Port\InitialRefreshDispatcher;
use Th3Mouk\MaterializedViewBundle\Exception\AsyncRefreshRequiresTargetResolver;
use Th3Mouk\MaterializedViewBundle\Exception\UnknownRebuildStrategy;

#[AsCommand(
    name: 'matview:sync',
    description: 'Create or rebuild missing or drifted managed materialized views.',
)]
final class SyncCommand extends Command
{
    public function __construct(
        private readonly MaterializedViewRegistry $registry,
        private readonly MaterializedViewManager $manager,
        private readonly MaterializedViewComparator $comparator,
        private readonly InitialRefreshDispatcher $refreshDispatcher,
        private readonly bool $analyzeAfterSyncByDefault,
        private readonly bool $preserveExistingGrantsByDefault,
        private readonly bool $pruneOrphansByDefault,
        private readonly bool $asyncRequiresTargetResolver,
        private readonly MissingDependencyPolicy $missingDependencyPolicy,
        private readonly DropDependentPolicy $dropDependentPolicy,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('prune', null, InputOption::VALUE_NONE, 'Drop managed-but-undeclared views (never at boot). Overrides sync.prune_orphans_by_default.')
            ->addOption('refresh-initial', null, InputOption::VALUE_NONE, 'Perform a synchronous first refresh of created/rebuilt views.')
            ->addOption('enqueue-refresh', null, InputOption::VALUE_NONE, 'Schedule initial refreshes asynchronously instead of running them inline.')
            ->addOption('no-analyze', null, InputOption::VALUE_NONE, 'Skip ANALYZE after sync. Overrides sync.analyze_after_sync.')
            ->addOption('strategy', null, InputOption::VALUE_REQUIRED, 'Force every view to rebuild with this strategy this run, overriding per-definition strategies: drop_create|side_by_side.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the actions without executing them.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        if ($input->getOption('dry-run')) {
            return $this->dryRun($style);
        }

        $forcedStrategy = $this->forcedStrategy($input);
        $registry = $this->registryForStrategy($forcedStrategy);
        $options = $this->buildOptions($input, $forcedStrategy);

        if ($input->getOption('enqueue-refresh')) {
            $this->ensureAsyncIsAllowed();
        }

        $outcome = $this->manager->syncAll($registry, $options);

        if ($input->getOption('enqueue-refresh')) {
            foreach ([...$outcome->created, ...$outcome->rebuilt] as $qualifiedName) {
                $this->refreshDispatcher->dispatch($registry->get($qualifiedName), false);
            }
        }

        $style->table(
            ['Action', 'Views'],
            [
                ['Created', $this->format($outcome->created)],
                ['Rebuilt', $this->format($outcome->rebuilt)],
                ['Up to date', $this->format($outcome->upToDate)],
                ['Pruned', $this->format($outcome->pruned)],
                ['Orphans kept', $this->format($outcome->orphansKept)],
                ['Skipped (missing dependency)', $this->format($outcome->skipped)],
            ],
        );

        if ([] !== $outcome->skipped) {
            $style->warning(\sprintf(
                'Skipped %d view(s) with a missing schema/table dependency: %s.',
                \count($outcome->skipped),
                implode(', ', $outcome->skipped),
            ));
        }

        $style->success(\sprintf('Sync complete: %d change(s).', $outcome->changedCount()));

        return Command::SUCCESS;
    }

    private function dryRun(SymfonyStyle $style): int
    {
        $plan = $this->comparator->compare($this->registry);

        $style->table(
            ['Action', 'Views'],
            [
                ['Create', $this->format($this->planNames($plan->toCreate()))],
                ['Rebuild', $this->format($this->planNames($plan->toRebuild()))],
                ['Orphans (require --prune)', $this->format($this->planNames($plan->orphans()))],
                ['Up to date', $this->format($this->planNames($plan->upToDate()))],
            ],
        );

        $style->note('Dry run: no changes were applied.');

        return Command::SUCCESS;
    }

    private function forcedStrategy(InputInterface $input): ?RebuildStrategy
    {
        $strategy = $input->getOption('strategy');

        if (!\is_string($strategy)) {
            return null;
        }

        return RebuildStrategy::tryFrom($strategy)
            ?? throw UnknownRebuildStrategy::value($strategy, RebuildStrategy::cases());
    }

    private function registryForStrategy(?RebuildStrategy $forcedStrategy): MaterializedViewRegistry
    {
        if (null === $forcedStrategy) {
            return $this->registry;
        }

        return MaterializedViewRegistry::fromDefinitions(array_map(
            static fn (MaterializedViewDefinition $definition): MaterializedViewDefinition => $definition->withRebuildStrategy($forcedStrategy),
            $this->registry->all(),
        ));
    }

    private function buildOptions(InputInterface $input, ?RebuildStrategy $forcedStrategy): SyncOptions
    {
        $options = SyncOptions::default()
            ->withPrune($input->getOption('prune') ? true : $this->pruneOrphansByDefault)
            ->withRefreshInitial((bool) $input->getOption('refresh-initial'))
            ->withAnalyzeAfterSync($input->getOption('no-analyze') ? false : $this->analyzeAfterSyncByDefault)
            ->withPreserveExistingGrants($this->preserveExistingGrantsByDefault)
            ->withMissingDependencyPolicy($this->missingDependencyPolicy)
            ->withDropDependentPolicy($this->dropDependentPolicy);

        if (RebuildStrategy::SideBySide === $forcedStrategy) {
            $options = $options->withSideBySideSwapToken(bin2hex(random_bytes(4)));
        }

        return $options;
    }

    private function ensureAsyncIsAllowed(): void
    {
        if ($this->asyncRequiresTargetResolver && !$this->refreshDispatcher->canDispatch()) {
            throw AsyncRefreshRequiresTargetResolver::forSync();
        }
    }

    /**
     * @param list<MaterializedViewComparison> $comparisons
     *
     * @return list<string>
     */
    private function planNames(array $comparisons): array
    {
        return array_map(static fn (MaterializedViewComparison $comparison): string => $comparison->name->qualifiedName(), $comparisons);
    }

    /**
     * @param list<string> $names
     */
    private function format(array $names): string
    {
        return [] === $names ? '-' : implode(', ', $names);
    }
}
