<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Introspection\PostgreSqlMaterializedViewIntrospector;
use Th3Mouk\MaterializedView\Core\Introspection\ReadinessChecker;
use Th3Mouk\MaterializedView\Core\MaterializedViewManager;
use Th3Mouk\MaterializedView\Core\Refresh\RefreshOptions;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;

#[AsCommand(
    name: 'matview:refresh',
    description: 'Refresh one materialized view, or all in catalog dependency order.',
)]
final class RefreshCommand extends Command
{
    public function __construct(
        private readonly MaterializedViewRegistry $registry,
        private readonly MaterializedViewManager $manager,
        private readonly PostgreSqlMaterializedViewIntrospector $introspector,
        private readonly ReadinessChecker $readinessChecker,
        private readonly bool $analyzeAfterRefreshByDefault,
        private readonly string $defaultLockTimeout,
        private readonly string $defaultStatementTimeout,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'Qualified view name to refresh.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Refresh every declared view in dependency order.')
            ->addOption('concurrently', null, InputOption::VALUE_NONE, 'Force REFRESH MATERIALIZED VIEW CONCURRENTLY.')
            ->addOption('if-populated', null, InputOption::VALUE_NONE, 'Skip a concurrent refresh on an unpopulated view.')
            ->addOption('no-analyze', null, InputOption::VALUE_NONE, 'Skip ANALYZE after refresh. Overrides refresh.analyze_after_refresh.')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Override the configured lock_timeout and statement_timeout for this run (e.g. 30s).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        $name = $input->getArgument('name');
        $all = (bool) $input->getOption('all');

        if ($all && null !== $name) {
            $style->error('Pass either a view name or --all, not both.');

            return Command::INVALID;
        }

        if (!$all && null === $name) {
            $style->error('Provide a view name or --all.');

            return Command::INVALID;
        }

        $options = $this->buildOptions($input);

        if ($all) {
            $this->manager->refreshAll($this->registry, $options);
            $style->success(\sprintf('Refreshed %d materialized view(s).', $this->registry->count()));

            return Command::SUCCESS;
        }

        \assert(\is_string($name));
        $definition = $this->registry->get($name);

        if ($this->shouldSkipUnpopulated($input, $definition)) {
            $style->note(\sprintf('Skipped "%s": not populated and --if-populated set.', $definition->name()->qualifiedName()));

            return Command::SUCCESS;
        }

        $this->manager->refresh($definition, $options);
        $style->success(\sprintf('Refreshed "%s".', $definition->name()->qualifiedName()));

        return Command::SUCCESS;
    }

    private function buildOptions(InputInterface $input): RefreshOptions
    {
        $override = $input->getOption('timeout');
        $hasOverride = \is_string($override) && '' !== $override;

        return RefreshOptions::default()
            ->withConcurrently((bool) $input->getOption('concurrently'))
            ->withAnalyzeAfterRefresh($input->getOption('no-analyze') ? false : $this->analyzeAfterRefreshByDefault)
            ->withLockTimeout($hasOverride ? $override : $this->defaultLockTimeout)
            ->withStatementTimeout($hasOverride ? $override : $this->defaultStatementTimeout);
    }

    private function shouldSkipUnpopulated(InputInterface $input, MaterializedViewDefinition $definition): bool
    {
        if (!$input->getOption('if-populated')) {
            return false;
        }

        $name = $definition->name();

        if (!$this->introspector->exists($name)) {
            return true;
        }

        return !$this->readinessChecker->isReady($name);
    }
}
