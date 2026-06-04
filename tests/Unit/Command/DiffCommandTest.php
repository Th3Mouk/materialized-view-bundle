<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\SqlFileSource;
use Th3Mouk\MaterializedViewBundle\Command\DiffCommand;

#[Group('matview-commands')]
final class DiffCommandTest extends CommandTestCase
{
    public function testShowsCreateSectionForMissingView(): void
    {
        $connection = $this->emptyConnection();
        $command = new DiffCommand(
            $this->registry([$this->definition()]),
            $this->comparator($connection),
        );

        $tester = new CommandTester($command);
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Create', $display);
        self::assertStringContainsString('public.demo', $display);
    }

    public function testReportsNothingToDoWhenRegistryEmpty(): void
    {
        $connection = $this->emptyConnection();
        $command = new DiffCommand($this->registry([]), $this->comparator($connection));

        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertStringContainsString('Nothing to do', $tester->getDisplay());
    }

    private function definition(): MaterializedViewDefinition
    {
        return MaterializedViewDefinition::create('public.demo')
            ->fromSql(SqlFileSource::fromAbsolutePath(__DIR__.'/fixtures/sample.sql'));
    }
}
