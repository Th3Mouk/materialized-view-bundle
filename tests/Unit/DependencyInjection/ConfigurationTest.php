<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Th3Mouk\MaterializedViewBundle\DependencyInjection\Configuration;

#[Group('materialized-view-bundle')]
final class ConfigurationTest extends TestCase
{
    /**
     * @param array<string, mixed> $configs
     *
     * @return array<string, mixed>
     */
    private function process(array ...$configs): array
    {
        return new Processor()->processConfiguration(new Configuration(), $configs);
    }

    public function testEmptyConfigurationFillsAllDefaults(): void
    {
        $config = $this->process([]);

        self::assertSame('default', $config['connection']);
        self::assertSame('public', $config['default_schema']);
        self::assertSame(['%kernel.project_dir%/db/matviews'], $config['sql_paths']);

        self::assertSame('stable', $config['generation']['file_naming']);

        self::assertSame('comment', $config['metadata']['storage']);
        self::assertSame('th3mouk_materialized_view_refresh_log', $config['metadata']['table_name']);

        self::assertSame('drop_create', $config['sync']['default_rebuild_strategy']);
        self::assertSame('async', $config['sync']['default_population_policy']);
        self::assertTrue($config['sync']['async_requires_target_resolver']);
        self::assertTrue($config['sync']['analyze_after_sync']);
        self::assertTrue($config['sync']['preserve_existing_grants']);
        self::assertFalse($config['sync']['prune_orphans_by_default']);
        self::assertSame('fail', $config['sync']['on_missing_dependency']);

        self::assertSame('refuse', $config['drop']['on_external_dependent']);

        self::assertTrue($config['async']['require_shared_transport']);
        self::assertSame('shared', $config['async']['transport_scope']);

        self::assertTrue($config['lane']['use_advisory_lock']);
        self::assertSame(392818, $config['lane']['lock_namespace']);
        self::assertSame('512M', $config['lane']['minimum_memory_limit']);
        self::assertSame('all_on_pending', $config['lane']['drop_strategy']);

        self::assertTrue($config['refresh']['use_advisory_locks']);
        self::assertSame(392817, $config['refresh']['lock_namespace']);
        self::assertTrue($config['refresh']['analyze_after_refresh']);
        self::assertSame('10s', $config['refresh']['lock_timeout']);
        self::assertSame('0', $config['refresh']['statement_timeout']);

        self::assertSame('request', $config['readiness']['cache_scope']);
        self::assertSame('empty', $config['template']['policy']);
        self::assertTrue($config['doctrine']['orm_write_guard']);
    }

    public function testLaneAndRefreshNamespacesDefaultToDistinctReservedValues(): void
    {
        $config = $this->process([]);

        self::assertNotSame($config['lane']['lock_namespace'], $config['refresh']['lock_namespace']);
    }

    public function testScalarOverridesArePreserved(): void
    {
        $config = $this->process([
            'connection' => 'analytics',
            'default_schema' => 'reporting',
            'sql_paths' => ['%kernel.project_dir%/db/views', '%kernel.project_dir%/db/extra'],
        ]);

        self::assertSame('analytics', $config['connection']);
        self::assertSame('reporting', $config['default_schema']);
        self::assertSame(
            ['%kernel.project_dir%/db/views', '%kernel.project_dir%/db/extra'],
            $config['sql_paths'],
        );
    }

    public function testConfigsAreMergedWithLastWinning(): void
    {
        $config = $this->process(
            ['connection' => 'first', 'default_schema' => 'one'],
            ['connection' => 'second'],
        );

        self::assertSame('second', $config['connection']);
        self::assertSame('one', $config['default_schema']);
    }

    /**
     * @param array<string, mixed> $invalidSection
     */
    #[DataProvider('invalidEnumProvider')]
    public function testRejectsInvalidEnumValues(array $invalidSection, string $expectedMessageFragment): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($expectedMessageFragment, '/').'/');

        $this->process($invalidSection);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidEnumProvider(): iterable
    {
        yield 'generation.file_naming' => [
            ['generation' => ['file_naming' => 'nope']],
            'file_naming',
        ];
        yield 'metadata.storage' => [
            ['metadata' => ['storage' => 'database']],
            'storage',
        ];
        yield 'sync.default_rebuild_strategy' => [
            ['sync' => ['default_rebuild_strategy' => 'in_place']],
            'default_rebuild_strategy',
        ];
        yield 'sync.default_population_policy' => [
            ['sync' => ['default_population_policy' => 'required_before_read']],
            'default_population_policy',
        ];
        yield 'drop.on_external_dependent' => [
            ['drop' => ['on_external_dependent' => 'restrict']],
            'on_external_dependent',
        ];
        yield 'async.transport_scope' => [
            ['async' => ['transport_scope' => 'local']],
            'transport_scope',
        ];
        yield 'lane.drop_strategy' => [
            ['lane' => ['drop_strategy' => 'targeted']],
            'drop_strategy',
        ];
        yield 'readiness.cache_scope' => [
            ['readiness' => ['cache_scope' => 'forever']],
            'cache_scope',
        ];
        yield 'template.policy' => [
            ['template' => ['policy' => 'shared_template']],
            'policy',
        ];
    }

    public function testEmptyConnectionIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['connection' => '']);
    }

    public function testPopulationPolicyDoesNotExposeRequiredBeforeReadAsDefaultSurface(): void
    {
        $config = $this->process(['sync' => ['default_population_policy' => 'synchronous']]);

        self::assertSame('synchronous', $config['sync']['default_population_policy']);
    }

    public function testAcceptsTheOptInMissingDependencyAndExternalDependentPolicies(): void
    {
        $config = $this->process([
            'sync' => ['on_missing_dependency' => 'skip'],
            'drop' => ['on_external_dependent' => 'cascade'],
        ]);

        self::assertSame('skip', $config['sync']['on_missing_dependency']);
        self::assertSame('cascade', $config['drop']['on_external_dependent']);
    }

    public function testOnMissingDependencyAcceptsAnEnvPlaceholderForPerEnvironmentControl(): void
    {
        $config = $this->process(['sync' => ['on_missing_dependency' => '%env(MATVIEW_ON_MISSING_DEPENDENCY)%']]);

        self::assertSame('%env(MATVIEW_ON_MISSING_DEPENDENCY)%', $config['sync']['on_missing_dependency']);
    }
}
