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
use Th3Mouk\MaterializedViewBundle\Command\Port\MaterializedViewScaffolder;

#[AsCommand(
    name: 'matview:generate',
    description: 'Scaffold the .sql file and an attributed provider class for a materialized view.',
)]
final class GenerateCommand extends Command
{
    public function __construct(
        private readonly MaterializedViewScaffolder $scaffolder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Qualified view name (e.g. public.sales_by_category).')
            ->addOption('bump', null, InputOption::VALUE_NONE, 'Create the next versioned SQL file and update the definition (versioned naming).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        $name = $input->getArgument('name');
        \assert(\is_string($name));

        $result = $this->scaffolder->scaffold($name, (bool) $input->getOption('bump'));

        if ([] !== $result->createdFiles) {
            $style->section('Created');
            $style->listing($result->createdFiles);
        }

        if ([] !== $result->updatedFiles) {
            $style->section('Updated');
            $style->listing($result->updatedFiles);
        }

        if ([] === $result->createdFiles && [] === $result->updatedFiles) {
            $style->warning('Nothing was generated.');

            return Command::SUCCESS;
        }

        $style->success(\sprintf('Scaffolded "%s".', $name));

        return Command::SUCCESS;
    }
}
