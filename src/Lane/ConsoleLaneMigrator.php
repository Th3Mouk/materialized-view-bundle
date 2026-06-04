<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Lane;

use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class ConsoleLaneMigrator implements LaneMigrator
{
    public function __construct(
        private Application $application,
        private OutputInterface $output,
    ) {
    }

    public function migrate(bool $dryRun): void
    {
        $parameters = [
            'command' => 'doctrine:migrations:migrate',
            'version' => 'latest',
            '--no-interaction' => true,
            '--allow-no-migration' => true,
        ];

        if ($dryRun) {
            $parameters['--dry-run'] = true;
        }

        $input = new ArrayInput($parameters);
        $input->setInteractive(false);

        $exitCode = $this->application->find('doctrine:migrations:migrate')->run($input, $this->output);

        if (0 !== $exitCode) {
            throw new RuntimeException(\sprintf('doctrine:migrations:migrate exited with code %d.', $exitCode));
        }
    }
}
