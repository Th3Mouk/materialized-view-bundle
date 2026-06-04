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
use Th3Mouk\MaterializedView\Core\MaterializedViewManager;
use Th3Mouk\MaterializedViewBundle\Command\RefreshCommand;

#[Group('matview-commands')]
final class RefreshCommandTest extends CommandTestCase
{
    public function testRejectsWhenNeitherNameNorAll(): void
    {
        $tester = new CommandTester($this->command());
        $exit = $tester->execute([]);

        self::assertSame(Command::INVALID, $exit);
        self::assertStringContainsString('Provide a view name or --all.', $tester->getDisplay());
    }

    public function testRejectsWhenBothNameAndAll(): void
    {
        $tester = new CommandTester($this->command());
        $exit = $tester->execute(['name' => 'public.demo', '--all' => true]);

        self::assertSame(Command::INVALID, $exit);
        self::assertStringContainsString('not both', $tester->getDisplay());
    }

    public function testRefreshesSingleView(): void
    {
        $tester = new CommandTester($this->command());
        $exit = $tester->execute(['name' => 'public.demo']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Refreshed "public.demo".', $tester->getDisplay());
    }

    public function testRefreshAllReportsCount(): void
    {
        $tester = new CommandTester($this->command());
        $exit = $tester->execute(['--all' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Refreshed 1 materialized view(s).', $tester->getDisplay());
    }

    public function testIfPopulatedSkipsUnpopulatedView(): void
    {
        $tester = new CommandTester($this->command());
        $exit = $tester->execute([
            'name' => 'public.demo',
            '--concurrently' => true,
            '--if-populated' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Skipped "public.demo"', $tester->getDisplay());
    }

    public function testAcceptsTimeoutOption(): void
    {
        $tester = new CommandTester($this->command());
        $exit = $tester->execute(['name' => 'public.demo', '--timeout' => '30s']);

        self::assertSame(Command::SUCCESS, $exit);
    }

    public function testAppliesConfiguredTimeoutsByDefault(): void
    {
        $statements = [];
        $connection = $this->recordingConnection($statements);

        $command = new RefreshCommand(
            $this->registry([$this->definition()]),
            MaterializedViewManager::forConnection($connection),
            $this->introspector($connection),
            $this->readinessChecker($connection),
            true,
            '7s',
            '90s',
        );

        $tester = new CommandTester($command);
        $exit = $tester->execute(['name' => 'public.demo']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertContains("SET lock_timeout = '7s'", $statements);
        self::assertContains("SET statement_timeout = '90s'", $statements);
    }

    public function testTimeoutOptionOverridesConfiguredTimeouts(): void
    {
        $statements = [];
        $connection = $this->recordingConnection($statements);

        $command = new RefreshCommand(
            $this->registry([$this->definition()]),
            MaterializedViewManager::forConnection($connection),
            $this->introspector($connection),
            $this->readinessChecker($connection),
            true,
            '7s',
            '90s',
        );

        $tester = new CommandTester($command);
        $tester->execute(['name' => 'public.demo', '--timeout' => '30s']);

        self::assertContains("SET lock_timeout = '30s'", $statements);
        self::assertContains("SET statement_timeout = '30s'", $statements);
    }

    /**
     * @param list<string> $statements
     */
    private function recordingConnection(array &$statements): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(false);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $identifier): string => '"'.$identifier.'"',
        );
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 0;
            },
        );

        return $connection;
    }

    private function command(): RefreshCommand
    {
        $connection = $this->emptyConnection();

        return new RefreshCommand(
            $this->registry([$this->definition()]),
            $this->manager($connection),
            $this->introspector($connection),
            $this->readinessChecker($connection),
            true,
            '10s',
            '0',
        );
    }

    private function definition(): MaterializedViewDefinition
    {
        return MaterializedViewDefinition::create('public.demo')
            ->fromSql(SqlFileSource::fromAbsolutePath(__DIR__.'/fixtures/sample.sql'));
    }
}
