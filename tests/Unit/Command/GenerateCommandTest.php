<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Tester\CommandTester;
use Th3Mouk\MaterializedViewBundle\Command\GenerateCommand;
use Th3Mouk\MaterializedViewBundle\Command\Port\MaterializedViewScaffolder;
use Th3Mouk\MaterializedViewBundle\Command\Port\ScaffoldResult;

#[Group('matview-commands')]
final class GenerateCommandTest extends CommandTestCase
{
    public function testPassesNameAndBumpFalseToScaffolder(): void
    {
        $scaffolder = $this->createMock(MaterializedViewScaffolder::class);
        $scaffolder->expects(self::once())
            ->method('scaffold')
            ->with('public.sales_by_category', false)
            ->willReturn(ScaffoldResult::of(['db/matviews/sales_by_category.sql', 'src/View/SalesByCategoryView.php']));

        $tester = new CommandTester(new GenerateCommand($scaffolder));
        $exit = $tester->execute(['name' => 'public.sales_by_category']);

        self::assertSame(0, $exit);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('db/matviews/sales_by_category.sql', $tester->getDisplay());
        self::assertStringContainsString('Scaffolded "public.sales_by_category".', $tester->getDisplay());
    }

    public function testForwardsBumpFlag(): void
    {
        $scaffolder = $this->createMock(MaterializedViewScaffolder::class);
        $scaffolder->expects(self::once())
            ->method('scaffold')
            ->with('public.sales_by_category', true)
            ->willReturn(ScaffoldResult::of(['db/matviews/sales_by_category_v002.sql'], ['src/View/SalesByCategoryView.php']));

        $tester = new CommandTester(new GenerateCommand($scaffolder));
        $tester->execute(['name' => 'public.sales_by_category', '--bump' => true]);

        self::assertStringContainsString('Updated', $tester->getDisplay());
        self::assertStringContainsString('db/matviews/sales_by_category_v002.sql', $tester->getDisplay());
    }

    public function testReportsWhenNothingGenerated(): void
    {
        $scaffolder = $this->createStub(MaterializedViewScaffolder::class);
        $scaffolder->method('scaffold')->willReturn(ScaffoldResult::of([]));

        $tester = new CommandTester(new GenerateCommand($scaffolder));
        $tester->execute(['name' => 'public.sales_by_category']);

        self::assertStringContainsString('Nothing was generated.', $tester->getDisplay());
    }
}
