<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Unit\Command\Port;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Th3Mouk\MaterializedView\Core\Definition\PopulationPolicy;
use Th3Mouk\MaterializedView\Core\Definition\RebuildStrategy;
use Th3Mouk\MaterializedView\Core\Definition\SqlFileSource;
use Th3Mouk\MaterializedViewBundle\Command\Port\FilesystemMaterializedViewScaffolder;

#[Group('matview-commands')]
final class FilesystemMaterializedViewScaffolderTest extends TestCase
{
    private string $projectDir;

    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->projectDir = sys_get_temp_dir().'/matview-scaffold-'.bin2hex(random_bytes(6));
        $this->filesystem->mkdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectDir);
    }

    public function testGeneratedProviderPointsAtTheWrittenSqlFile(): void
    {
        $scaffolder = $this->scaffolder();

        $result = $scaffolder->scaffold('public.sales_by_category', false);

        $sqlPath = $this->projectDir.'/db/matviews/sales_by_category.sql';
        self::assertContains($sqlPath, $result->createdFiles);
        self::assertFileExists($sqlPath);

        $providerSource = $this->generatedProviderSource();
        self::assertStringContainsString("SqlFileSource::fromProjectPath('db/matviews/sales_by_category.sql')", $providerSource);

        $source = SqlFileSource::fromProjectPath('db/matviews/sales_by_category.sql', $this->projectDir);
        self::assertSame($sqlPath, $source->identifier());
        self::assertSame("SELECT 1 AS placeholder\n", $source->sql());
    }

    public function testGeneratedProviderUsesTheConfiguredPopulationPolicy(): void
    {
        $scaffolder = $this->scaffolder(populationPolicy: PopulationPolicy::Synchronous);

        $scaffolder->scaffold('public.sales_by_category', false);

        self::assertStringContainsString(
            '->withPopulationPolicy(PopulationPolicy::Synchronous)',
            $this->generatedProviderSource(),
        );
    }

    public function testGeneratedProviderOmitsRebuildStrategyForTheDefault(): void
    {
        $scaffolder = $this->scaffolder(rebuildStrategy: RebuildStrategy::DropCreate);

        $scaffolder->scaffold('public.sales_by_category', false);

        $source = $this->generatedProviderSource();
        self::assertStringNotContainsString('withRebuildStrategy', $source);
        self::assertStringNotContainsString('use Th3Mouk\\MaterializedView\\Core\\Definition\\RebuildStrategy;', $source);
    }

    public function testGeneratedProviderEmitsNonDefaultRebuildStrategy(): void
    {
        $scaffolder = $this->scaffolder(rebuildStrategy: RebuildStrategy::SideBySide);

        $scaffolder->scaffold('public.sales_by_category', false);

        $source = $this->generatedProviderSource();
        self::assertStringContainsString('use Th3Mouk\\MaterializedView\\Core\\Definition\\RebuildStrategy;', $source);
        self::assertStringContainsString('->withRebuildStrategy(RebuildStrategy::SideBySide)', $source);
    }

    private function scaffolder(
        PopulationPolicy $populationPolicy = PopulationPolicy::Async,
        RebuildStrategy $rebuildStrategy = RebuildStrategy::DropCreate,
    ): FilesystemMaterializedViewScaffolder {
        return new FilesystemMaterializedViewScaffolder(
            $this->filesystem,
            $this->projectDir.'/db/matviews',
            $this->projectDir.'/src/MaterializedView',
            'App\\MaterializedView',
            'stable',
            'public',
            $this->projectDir,
            $populationPolicy,
            $rebuildStrategy,
        );
    }

    private function generatedProviderSource(): string
    {
        $path = $this->projectDir.'/src/MaterializedView/SalesByCategoryView.php';
        self::assertFileExists($path);

        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }
}
