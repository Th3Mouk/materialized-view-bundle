<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Unit\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\SqlFileSource;
use Th3Mouk\MaterializedView\Core\Dependency\DropDependentPolicy;
use Th3Mouk\MaterializedViewBundle\Command\DropCommand;
use Th3Mouk\MaterializedViewBundle\Command\Port\PendingMigrationsInspector;

#[Group('matview-commands')]
final class DropCommandTest extends CommandTestCase
{
    public function testRejectsWithoutAnyFlag(): void
    {
        $tester = new CommandTester($this->command([$this->definition()], false));
        $exit = $tester->execute([]);

        self::assertSame(Command::INVALID, $exit);
        self::assertStringContainsString('--if-pending or --all-managed', $tester->getDisplay());
    }

    public function testIfPendingSkipsWhenNoPendingMigrations(): void
    {
        $tester = new CommandTester($this->command([$this->definition()], false));
        $exit = $tester->execute(['--if-pending' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('No pending migrations', $tester->getDisplay());
    }

    public function testIfPendingDropsWhenMigrationsPending(): void
    {
        $tester = new CommandTester($this->command([$this->definition()], true));
        $exit = $tester->execute(['--if-pending' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Dropped', $display);
        self::assertStringContainsString('public.demo', $display);
    }

    public function testAllManagedDropsUnconditionally(): void
    {
        $tester = new CommandTester($this->command([$this->definition()], false));
        $exit = $tester->execute(['--all-managed' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('public.demo', $tester->getDisplay());
    }

    public function testDryRunDoesNotDrop(): void
    {
        $tester = new CommandTester($this->command([$this->definition()], true));
        $exit = $tester->execute(['--if-pending' => true, '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Dry run', $tester->getDisplay());
    }

    public function testReportsNothingToDropWhenRegistryEmpty(): void
    {
        $tester = new CommandTester($this->command([], false));
        $exit = $tester->execute(['--all-managed' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('No managed materialized views to drop.', $tester->getDisplay());
    }

    public function testCascadePolicyDropsManagedViewsWithCascade(): void
    {
        $statements = [];
        $tester = new CommandTester($this->commandWith(
            $this->recordingConnection($statements),
            [$this->definition()],
            true,
            DropDependentPolicy::Cascade,
        ));

        $exit = $tester->execute(['--all-managed' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertContains('DROP MATERIALIZED VIEW IF EXISTS "public"."demo" CASCADE', $statements);
    }

    public function testRefusePolicyDropsManagedViewsWithoutCascade(): void
    {
        $statements = [];
        $tester = new CommandTester($this->commandWith(
            $this->recordingConnection($statements),
            [$this->definition()],
            true,
            DropDependentPolicy::Refuse,
        ));

        $exit = $tester->execute(['--all-managed' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertContains('DROP MATERIALIZED VIEW IF EXISTS "public"."demo"', $statements);
        foreach ($statements as $statement) {
            self::assertStringNotContainsString('CASCADE', $statement);
        }
    }

    /**
     * @param iterable<MaterializedViewDefinition> $definitions
     */
    private function command(iterable $definitions, bool $hasPending): DropCommand
    {
        return $this->commandWith($this->emptyConnection(), $definitions, $hasPending);
    }

    /**
     * @param iterable<MaterializedViewDefinition> $definitions
     */
    private function commandWith(
        Connection $connection,
        iterable $definitions,
        bool $hasPending,
        DropDependentPolicy $dropDependentPolicy = DropDependentPolicy::Refuse,
    ): DropCommand {
        $inspector = $this->createStub(PendingMigrationsInspector::class);
        $inspector->method('hasPendingMigrations')->willReturn($hasPending);

        return new DropCommand(
            $this->registry($definitions),
            $this->manager($connection),
            $this->dependencyResolver($connection),
            $inspector,
            $dropDependentPolicy,
        );
    }

    /**
     * @param list<string> $statements
     */
    private function recordingConnection(array &$statements): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $identifier): string => '"'.$identifier.'"',
        );
        $connection->method('fetchOne')->willReturn(false);
        $connection->method('fetchAssociative')->willReturn(false);
        $connection->method('fetchAllAssociative')->willReturn([]);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 0;
            },
        );

        return $connection;
    }

    private function definition(): MaterializedViewDefinition
    {
        return MaterializedViewDefinition::create('public.demo')
            ->fromSql(SqlFileSource::fromAbsolutePath(__DIR__.'/fixtures/sample.sql'));
    }
}
