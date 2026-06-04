<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle;

use Override;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Th3Mouk\MaterializedViewBundle\Attribute\AsMaterializedViewProvider;
use Th3Mouk\MaterializedViewBundle\DependencyInjection\Compiler\MaterializedViewProviderPass;
use Th3Mouk\MaterializedViewBundle\DependencyInjection\Configuration;

final class Th3MoukMaterializedViewBundle extends AbstractBundle
{
    protected string $extensionAlias = Configuration::ALIAS;

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new MaterializedViewProviderPass());
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $rootNode = $definition->rootNode();
        \assert($rootNode instanceof ArrayNodeDefinition);

        Configuration::buildTree($rootNode);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        $container->parameters()->set(Configuration::ALIAS.'.config', $config);

        $this->exposeParameters($container, $config);

        $this->configureOrmWriteGuard($builder, $config);

        $connectionName = self::stringValue($config['connection'] ?? 'default');
        $builder->setAlias(
            Configuration::ALIAS.'.connection',
            new Alias(\sprintf('doctrine.dbal.%s_connection', $connectionName), false),
        );

        $builder->registerAttributeForAutoconfiguration(
            AsMaterializedViewProvider::class,
            static function (ChildDefinition $definition, AsMaterializedViewProvider $attribute): void {
                $definition->addTag(AsMaterializedViewProvider::TAG, [
                    'method' => $attribute->method,
                ]);
            },
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configureOrmWriteGuard(ContainerBuilder $builder, array $config): void
    {
        $doctrine = self::section($config, 'doctrine');

        if (self::boolValue($doctrine['orm_write_guard'] ?? true)) {
            return;
        }

        $builder->removeDefinition('th3mouk_materialized_view.orm.write_guard');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function exposeParameters(ContainerConfigurator $container, array $config): void
    {
        $alias = Configuration::ALIAS;
        $connectionName = self::stringValue($config['connection'] ?? 'default');

        $sqlPaths = self::arrayValue($config['sql_paths'] ?? []);
        $sqlDirectory = $sqlPaths[0] ?? '%kernel.project_dir%/db/matviews';

        $generation = self::section($config, 'generation');
        $metadata = self::section($config, 'metadata');
        $sync = self::section($config, 'sync');
        $drop = self::section($config, 'drop');
        $async = self::section($config, 'async');
        $lane = self::section($config, 'lane');
        $refresh = self::section($config, 'refresh');
        $readiness = self::section($config, 'readiness');
        $template = self::section($config, 'template');
        $doctrine = self::section($config, 'doctrine');

        $container->parameters()
            ->set($alias.'.connection', $connectionName)
            ->set($alias.'.default_schema', self::stringValue($config['default_schema'] ?? 'public'))
            ->set($alias.'.sql_paths', self::arrayValue($config['sql_paths'] ?? []))
            ->set($alias.'.scaffold.sql_directory', $sqlDirectory)
            ->set($alias.'.scaffold.provider_directory', '%kernel.project_dir%/src/MaterializedView')
            ->set($alias.'.scaffold.provider_namespace', 'App\\MaterializedView')
            ->set($alias.'.generation.file_naming', self::stringValue($generation['file_naming'] ?? 'stable'))
            ->set($alias.'.metadata.storage', self::stringValue($metadata['storage'] ?? 'comment'))
            ->set($alias.'.metadata.table_name', self::stringValue($metadata['table_name'] ?? ''))
            ->set($alias.'.sync.default_rebuild_strategy', self::stringValue($sync['default_rebuild_strategy'] ?? 'drop_create'))
            ->set($alias.'.sync.default_population_policy', self::stringValue($sync['default_population_policy'] ?? 'async'))
            ->set($alias.'.sync.async_requires_target_resolver', self::boolValue($sync['async_requires_target_resolver'] ?? true))
            ->set($alias.'.sync.analyze_after_sync', self::boolValue($sync['analyze_after_sync'] ?? true))
            ->set($alias.'.sync.preserve_existing_grants', self::boolValue($sync['preserve_existing_grants'] ?? true))
            ->set($alias.'.sync.prune_orphans_by_default', self::boolValue($sync['prune_orphans_by_default'] ?? false))
            ->set($alias.'.sync.on_missing_dependency', self::stringValue($sync['on_missing_dependency'] ?? 'fail'))
            ->set($alias.'.drop.on_external_dependent', self::stringValue($drop['on_external_dependent'] ?? 'refuse'))
            ->set($alias.'.async.require_shared_transport', self::boolValue($async['require_shared_transport'] ?? true))
            ->set($alias.'.async.transport_scope', self::stringValue($async['transport_scope'] ?? 'shared'))
            ->set($alias.'.lane.lock_namespace', self::intValue($lane['lock_namespace'] ?? 392818))
            ->set($alias.'.refresh.lock_namespace', self::intValue($refresh['lock_namespace'] ?? 392817))
            ->set($alias.'.refresh.analyze_after_refresh', self::boolValue($refresh['analyze_after_refresh'] ?? true))
            ->set($alias.'.refresh.lock_timeout', self::stringValue($refresh['lock_timeout'] ?? '10s'))
            ->set($alias.'.refresh.statement_timeout', self::stringValue($refresh['statement_timeout'] ?? '0'))
            ->set($alias.'.readiness.cache_scope', self::stringValue($readiness['cache_scope'] ?? 'request'))
            ->set($alias.'.template.policy', self::stringValue($template['policy'] ?? 'empty'))
            ->set($alias.'.doctrine.orm_write_guard', self::boolValue($doctrine['orm_write_guard'] ?? true));
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function section(array $config, string $key): array
    {
        $value = $config[$key] ?? [];

        return \is_array($value) ? $value : [];
    }

    private static function stringValue(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private static function boolValue(mixed $value): bool
    {
        return (bool) $value;
    }

    private static function intValue(mixed $value): int
    {
        return \is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return list<string>
     */
    private static function arrayValue(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_map(self::stringValue(...), $value));
    }

    #[Override]
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
