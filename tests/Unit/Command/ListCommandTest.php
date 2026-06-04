<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\PopulationPolicy;
use Th3Mouk\MaterializedView\Core\Definition\SqlFileSource;
use Th3Mouk\MaterializedViewBundle\Command\ListCommand;

#[Group('matview-commands')]
final class ListCommandTest extends CommandTestCase
{
    public function testWarnsWhenNoDefinitionsDeclared(): void
    {
        $connection = $this->emptyConnection();
        $command = new ListCommand(
            $this->registry([]),
            $this->comparator($connection),
            $this->introspector($connection),
            $this->readinessChecker($connection),
        );

        $tester = new CommandTester($command);
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('No materialized-view definitions are declared.', $tester->getDisplay());
    }

    public function testListsDeclaredViewWithState(): void
    {
        $connection = $this->emptyConnection();
        $command = new ListCommand(
            $this->registry([$this->definition()]),
            $this->comparator($connection),
            $this->introspector($connection),
            $this->readinessChecker($connection),
        );

        $tester = new CommandTester($command);
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('public.demo', $display);
        self::assertStringContainsString('synchronous', $display);
        self::assertStringContainsString('missing', $display);
    }

    private function definition(): MaterializedViewDefinition
    {
        return MaterializedViewDefinition::create('public.demo')
            ->fromSql(SqlFileSource::fromAbsolutePath(__DIR__.'/fixtures/sample.sql'))
            ->withPopulationPolicy(PopulationPolicy::Synchronous);
    }
}
