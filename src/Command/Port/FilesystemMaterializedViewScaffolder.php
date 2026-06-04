<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Command\Port;

use Symfony\Component\Filesystem\Filesystem;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Definition\PopulationPolicy;
use Th3Mouk\MaterializedView\Core\Definition\RebuildStrategy;

final readonly class FilesystemMaterializedViewScaffolder implements MaterializedViewScaffolder
{
    private const string STABLE = 'stable';

    private const string VERSIONED = 'versioned';

    private const string PLACEHOLDER_SQL = "SELECT 1 AS placeholder\n";

    public function __construct(
        private Filesystem $filesystem,
        private string $sqlDirectory,
        private string $providerDirectory,
        private string $providerNamespace,
        private string $fileNaming,
        private string $defaultSchema,
        private string $projectDir,
        private PopulationPolicy $defaultPopulationPolicy,
        private RebuildStrategy $defaultRebuildStrategy,
    ) {
    }

    public function scaffold(string $viewName, bool $bump): ScaffoldResult
    {
        $name = MaterializedViewName::fromString($viewName, $this->defaultSchema);

        $created = [];
        $updated = [];

        $sqlFileName = $this->resolveSqlFileName($name, $bump);
        $sqlPath = $this->sqlDirectory.'/'.$sqlFileName;

        if (!$this->filesystem->exists($sqlPath)) {
            $this->filesystem->dumpFile($sqlPath, $this->sqlTemplate($bump, $name));
            $created[] = $sqlPath;
        }

        $providerPath = $this->providerDirectory.'/'.$this->providerClassName($name).'.php';
        $providerExists = $this->filesystem->exists($providerPath);

        $this->filesystem->dumpFile($providerPath, $this->providerTemplate($name, $sqlPath));

        if ($providerExists) {
            $updated[] = $providerPath;
        } else {
            $created[] = $providerPath;
        }

        return ScaffoldResult::of($created, $updated);
    }

    private function resolveSqlFileName(MaterializedViewName $name, bool $bump): string
    {
        if (self::VERSIONED !== $this->fileNaming) {
            return $name->name.'.sql';
        }

        return $name->name.'_v'.str_pad((string) $this->nextVersion($name, $bump), 3, '0', \STR_PAD_LEFT).'.sql';
    }

    private function nextVersion(MaterializedViewName $name, bool $bump): int
    {
        $highest = 0;
        $prefix = $name->name.'_v';

        foreach ($this->existingSqlFiles() as $file) {
            if (!str_starts_with($file, $prefix) || !str_ends_with($file, '.sql')) {
                continue;
            }

            $version = (int) substr($file, \strlen($prefix), -4);
            $highest = max($highest, $version);
        }

        if (0 === $highest) {
            return 1;
        }

        return $bump ? $highest + 1 : $highest;
    }

    /**
     * @return list<string>
     */
    private function existingSqlFiles(): array
    {
        if (!$this->filesystem->exists($this->sqlDirectory)) {
            return [];
        }

        $entries = scandir($this->sqlDirectory);

        if (false === $entries) {
            return [];
        }

        return array_values(array_filter($entries, static fn (string $entry): bool => '.' !== $entry && '..' !== $entry));
    }

    private function sqlTemplate(bool $bump, MaterializedViewName $name): string
    {
        if (!$bump || self::STABLE === $this->fileNaming) {
            return self::PLACEHOLDER_SQL;
        }

        return $this->previousSql($name) ?? self::PLACEHOLDER_SQL;
    }

    private function previousSql(MaterializedViewName $name): ?string
    {
        $previousVersion = $this->nextVersion($name, false);

        if (0 === $previousVersion) {
            return null;
        }

        $previousPath = \sprintf(
            '%s/%s_v%s.sql',
            $this->sqlDirectory,
            $name->name,
            str_pad((string) $previousVersion, 3, '0', \STR_PAD_LEFT),
        );

        if (!$this->filesystem->exists($previousPath)) {
            return null;
        }

        $contents = file_get_contents($previousPath);

        return false === $contents ? null : $contents;
    }

    private function providerTemplate(MaterializedViewName $name, string $sqlPath): string
    {
        $class = $this->providerClassName($name);
        $sqlRelativePath = $this->projectRelativePath($sqlPath);
        $populationPolicy = $this->defaultPopulationPolicy->name;
        $rebuildStrategyImport = $this->rebuildStrategyImport();
        $rebuildStrategyCall = $this->rebuildStrategyCall();

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$this->providerNamespace};

            use Th3Mouk\\MaterializedView\\Core\\Definition\\MaterializedViewDefinition;
            use Th3Mouk\\MaterializedView\\Core\\Definition\\PopulationPolicy;{$rebuildStrategyImport}
            use Th3Mouk\\MaterializedView\\Core\\Definition\\SqlFileSource;
            use Th3Mouk\\MaterializedViewBundle\\Attribute\\AsMaterializedViewProvider;

            #[AsMaterializedViewProvider]
            final class {$class}
            {
                public function definitions(): iterable
                {
                    yield MaterializedViewDefinition::create('{$name->qualifiedName()}')
                        ->fromSql(SqlFileSource::fromProjectPath('{$sqlRelativePath}')){$rebuildStrategyCall}
                        ->withPopulationPolicy(PopulationPolicy::{$populationPolicy});
                }
            }

            PHP;
    }

    private function rebuildStrategyImport(): string
    {
        if (RebuildStrategy::DropCreate === $this->defaultRebuildStrategy) {
            return '';
        }

        return "\nuse Th3Mouk\\MaterializedView\\Core\\Definition\\RebuildStrategy;";
    }

    private function rebuildStrategyCall(): string
    {
        if (RebuildStrategy::DropCreate === $this->defaultRebuildStrategy) {
            return '';
        }

        return "\n                ->withRebuildStrategy(RebuildStrategy::{$this->defaultRebuildStrategy->name})";
    }

    private function projectRelativePath(string $sqlPath): string
    {
        $relativeDirectory = $this->filesystem->makePathRelative(\dirname($sqlPath), $this->projectDir);

        return rtrim($relativeDirectory, '/').'/'.basename($sqlPath);
    }

    private function providerClassName(MaterializedViewName $name): string
    {
        $studly = str_replace(' ', '', ucwords(str_replace('_', ' ', $name->name)));

        return $studly.'View';
    }
}
