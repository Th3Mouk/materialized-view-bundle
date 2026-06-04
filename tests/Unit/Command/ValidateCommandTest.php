<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewIndex;
use Th3Mouk\MaterializedView\Core\Definition\SqlFileSource;
use Th3Mouk\MaterializedViewBundle\Command\ValidateCommand;

#[Group('matview-commands')]
final class ValidateCommandTest extends CommandTestCase
{
    public function testWarnsWhenNoDefinitions(): void
    {
        $tester = new CommandTester($this->command([]));
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('No materialized-view definitions are declared.', $tester->getDisplay());
    }

    public function testFailsWhenViewMissingFromDatabase(): void
    {
        $definition = MaterializedViewDefinition::create('public.demo')
            ->fromSql(SqlFileSource::fromAbsolutePath(__DIR__.'/fixtures/sample.sql'));

        $tester = new CommandTester($this->command([$definition]));
        $exit = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('does not exist', $tester->getDisplay());
        self::assertStringContainsString('Validation failed.', $tester->getDisplay());
    }

    public function testReportsUnreadableSqlSource(): void
    {
        $definition = MaterializedViewDefinition::create('public.demo')
            ->fromSql(SqlFileSource::fromAbsolutePath(__DIR__.'/fixtures/does-not-exist.sql'));

        $tester = new CommandTester($this->command([$definition]));
        $exit = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('does-not-exist.sql', $tester->getDisplay());
    }

    public function testReportsConcurrentRefreshNeedsUniqueIndex(): void
    {
        $definition = MaterializedViewDefinition::create('public.demo')
            ->fromSql(SqlFileSource::fromAbsolutePath(__DIR__.'/fixtures/sample.sql'))
            ->withIndex(MaterializedViewIndex::regular(
                name: 'ix_demo_id',
                columns: ['id'],
                concurrently: true,
            ));

        $tester = new CommandTester($this->command([$definition]));
        $tester->execute([]);

        self::assertStringContainsString('Concurrent refresh requires a unique index', $tester->getDisplay());
    }

    /**
     * @param iterable<MaterializedViewDefinition> $definitions
     */
    private function command(iterable $definitions): ValidateCommand
    {
        $connection = $this->emptyConnection();

        return new ValidateCommand(
            $this->registry($definitions),
            $this->comparator($connection),
            $this->introspector($connection),
            $this->readinessChecker($connection),
            $this->externalDependencyGuard($connection),
        );
    }
}
