<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\SqlFileSource;
use Th3Mouk\MaterializedViewBundle\Command\DumpSqlCommand;

#[Group('matview-commands')]
final class DumpSqlCommandTest extends CommandTestCase
{
    public function testDumpsUpSqlForAllDeclaredViews(): void
    {
        $tester = new CommandTester(new DumpSqlCommand($this->registry([$this->definition()])));
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('public.demo (up)', $display);
        self::assertStringContainsString('CREATE MATERIALIZED VIEW "public"."demo"', $display);
        self::assertStringContainsString('DROP MATERIALIZED VIEW IF EXISTS "public"."demo"', $display);
    }

    public function testDumpsDownSqlForSingleView(): void
    {
        $tester = new CommandTester(new DumpSqlCommand($this->registry([$this->definition()])));
        $exit = $tester->execute(['name' => 'public.demo', '--down' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('public.demo (down)', $display);
        self::assertStringContainsString('DROP MATERIALIZED VIEW IF EXISTS "public"."demo";', $display);
        self::assertStringNotContainsString('CREATE MATERIALIZED VIEW', $display);
    }

    public function testWarnsWhenNoDefinitions(): void
    {
        $tester = new CommandTester(new DumpSqlCommand($this->registry([])));
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('No materialized-view definitions are declared.', $tester->getDisplay());
    }

    private function definition(): MaterializedViewDefinition
    {
        return MaterializedViewDefinition::create('public.demo')
            ->fromSql(SqlFileSource::fromAbsolutePath(__DIR__.'/fixtures/sample.sql'));
    }
}
