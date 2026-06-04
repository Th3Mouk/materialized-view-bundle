<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\SqlFileSource;
use Th3Mouk\MaterializedViewBundle\Command\PruneCommand;

#[Group('matview-commands')]
final class PruneCommandTest extends CommandTestCase
{
    public function testReportsNothingToPruneWhenNoOrphans(): void
    {
        $tester = new CommandTester($this->command());
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('No managed-but-undeclared materialized views to prune.', $tester->getDisplay());
    }

    public function testDryRunSucceedsWithNoOrphans(): void
    {
        $tester = new CommandTester($this->command());
        $exit = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exit);
    }

    private function command(): PruneCommand
    {
        $connection = $this->emptyConnection();
        $definition = MaterializedViewDefinition::create('public.demo')
            ->fromSql(SqlFileSource::fromAbsolutePath(__DIR__.'/fixtures/sample.sql'));

        return new PruneCommand(
            $this->registry([$definition]),
            $this->manager($connection),
            $this->comparator($connection),
        );
    }
}
