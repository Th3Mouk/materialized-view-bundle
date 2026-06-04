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
use Th3Mouk\MaterializedView\Core\Migration\MaterializedViewMigrationSql;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;

#[AsCommand(
    name: 'matview:dump-sql',
    description: 'Print the up/down SQL for the migration-owned mode.',
)]
final class DumpSqlCommand extends Command
{
    public function __construct(
        private readonly MaterializedViewRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'Qualified view name to dump (defaults to all declared views).')
            ->addOption('down', null, InputOption::VALUE_NONE, 'Print the down (drop) SQL instead of the up SQL.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        $name = $input->getArgument('name');
        $down = (bool) $input->getOption('down');

        $definitions = null === $name
            ? $this->registry->all()
            : [$this->registry->get($name)];

        if ([] === $definitions) {
            $style->warning('No materialized-view definitions are declared.');

            return Command::SUCCESS;
        }

        foreach ($definitions as $definition) {
            $style->section(\sprintf('%s (%s)', $definition->name()->qualifiedName(), $down ? 'down' : 'up'));

            foreach ($this->statementsFor($definition, $down) as $statement) {
                $output->writeln($statement.';');
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @return iterable<string>
     */
    private function statementsFor(MaterializedViewDefinition $definition, bool $down): iterable
    {
        return $down
            ? MaterializedViewMigrationSql::drop($definition)
            : MaterializedViewMigrationSql::create($definition);
    }
}
