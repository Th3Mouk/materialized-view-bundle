<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\DependencyInjection;

use BackedEnum;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Definition\PopulationPolicy;
use Th3Mouk\MaterializedView\Core\Definition\RebuildStrategy;
use Th3Mouk\MaterializedView\Core\Dependency\DropDependentPolicy;
use Th3Mouk\MaterializedView\Core\MaterializedViewManager;
use Th3Mouk\MaterializedView\Core\Sync\MissingDependencyPolicy;

final class Configuration implements ConfigurationInterface
{
    public const string ALIAS = 'th3mouk_materialized_view';

    private const int DEFAULT_LANE_LOCK_NAMESPACE = 392818;

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);

        $rootNode = $treeBuilder->getRootNode();
        \assert($rootNode instanceof ArrayNodeDefinition);

        self::buildTree($rootNode);

        return $treeBuilder;
    }

    public static function buildTree(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('connection')
                    ->cannotBeEmpty()
                    ->defaultValue('default')
                    ->info('DBAL connection used for DDL/refresh (must reach the primary).')
                ->end()
                ->scalarNode('default_schema')
                    ->cannotBeEmpty()
                    ->defaultValue(MaterializedViewName::DEFAULT_SCHEMA)
                ->end()
                ->arrayNode('sql_paths')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultValue(['%kernel.project_dir%/db/matviews'])
                    ->info('Where the .sql definition files live.')
                ->end()
            ->end();

        self::appendGeneration($rootNode);
        self::appendMetadata($rootNode);
        self::appendSync($rootNode);
        self::appendDrop($rootNode);
        self::appendAsync($rootNode);
        self::appendLane($rootNode);
        self::appendRefresh($rootNode);
        self::appendReadiness($rootNode);
        self::appendTemplate($rootNode);
        self::appendDoctrine($rootNode);
    }

    private static function appendGeneration(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('generation')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('file_naming')
                            ->values(['stable', 'versioned'])
                            ->defaultValue('stable')
                            ->info('stable: db/matviews/<name>.sql; versioned: db/matviews/<name>_vNNN.sql.')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    private static function appendMetadata(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('metadata')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('storage')
                            ->values(['comment', 'comment_and_table'])
                            ->defaultValue('comment')
                            ->info('Where the canonical hash / management marker is stored.')
                        ->end()
                        ->scalarNode('table_name')
                            ->cannotBeEmpty()
                            ->defaultValue('th3mouk_materialized_view_refresh_log')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    private static function appendSync(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('sync')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('default_rebuild_strategy')
                            ->values(self::enumValues(RebuildStrategy::cases()))
                            ->defaultValue(RebuildStrategy::DropCreate->value)
                        ->end()
                        ->enumNode('default_population_policy')
                            ->values([
                                PopulationPolicy::Manual->value,
                                PopulationPolicy::Async->value,
                                PopulationPolicy::Synchronous->value,
                            ])
                            ->defaultValue(PopulationPolicy::Async->value)
                        ->end()
                        ->booleanNode('async_requires_target_resolver')
                            ->defaultTrue()
                            ->info('Safety: refuse async population unless a refresh target resolver is configured (you likely run the same views across multiple databases/connections).')
                        ->end()
                        ->booleanNode('analyze_after_sync')->defaultTrue()->end()
                        ->booleanNode('preserve_existing_grants')
                            ->defaultTrue()
                            ->info('Snapshot & replay GRANTs across rebuilds.')
                        ->end()
                        ->booleanNode('prune_orphans_by_default')
                            ->defaultFalse()
                            ->info('Never drop undeclared views implicitly.')
                        ->end()
                        ->scalarNode('on_missing_dependency')
                            ->defaultValue(MissingDependencyPolicy::Fail->value)
                            ->info('fail: abort the run; skip: log a warning and continue past views whose referenced schema/table is absent. Scalar (not enum) so it can be driven by an env var, e.g. %env(MATVIEW_ON_MISSING_DEPENDENCY)%; the value is validated at runtime by MissingDependencyPolicy::from().')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    private static function appendDrop(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('drop')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('on_external_dependent')
                            ->values(self::enumValues(DropDependentPolicy::cases()))
                            ->defaultValue(DropDependentPolicy::Refuse->value)
                            ->info('refuse: block drop/rebuild of a managed view with an unmanaged dependent; cascade: DROP ... CASCADE.')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    private static function appendAsync(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('async')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('require_shared_transport')->defaultTrue()->end()
                        ->enumNode('transport_scope')
                            ->values(['shared', 'per_connection_doctrine'])
                            ->defaultValue('shared')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    private static function appendLane(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('lane')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('use_advisory_lock')->defaultTrue()->end()
                        ->integerNode('lock_namespace')
                            ->defaultValue(self::DEFAULT_LANE_LOCK_NAMESPACE)
                            ->info('Reserved int4 namespace; document it app-wide.')
                        ->end()
                        ->scalarNode('minimum_memory_limit')
                            ->cannotBeEmpty()
                            ->defaultValue('512M')
                            ->info('The lane does more than `migrate`.')
                        ->end()
                        ->enumNode('drop_strategy')
                            ->values(['all_on_pending', 'reactive_retry', 'custom_impact'])
                            ->defaultValue('all_on_pending')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    private static function appendRefresh(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('refresh')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('use_advisory_locks')->defaultTrue()->end()
                        ->integerNode('lock_namespace')
                            ->defaultValue(MaterializedViewManager::DEFAULT_REFRESH_LOCK_NAMESPACE)
                            ->info('Distinct from the lane namespace.')
                        ->end()
                        ->booleanNode('analyze_after_refresh')->defaultTrue()->end()
                        ->scalarNode('lock_timeout')->defaultValue('10s')->end()
                        ->scalarNode('statement_timeout')->defaultValue('0')->end()
                    ->end()
                ->end()
            ->end();
    }

    private static function appendReadiness(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('readiness')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('cache_scope')
                            ->values(['request', 'process', 'none'])
                            ->defaultValue('request')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    private static function appendTemplate(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('template')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('policy')
                            ->values(['empty', 'cloned_stale', 'maintained_template'])
                            ->defaultValue('empty')
                            ->info('Behaviour for databases cloned from a PostgreSQL template.')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    private static function appendDoctrine(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('doctrine')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('orm_write_guard')
                            ->defaultTrue()
                            ->info('Register the onFlush write guard for matview entities.')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param list<BackedEnum> $cases
     *
     * @return list<string>
     */
    private static function enumValues(array $cases): array
    {
        return array_map(static fn (BackedEnum $case): string => (string) $case->value, $cases);
    }
}
