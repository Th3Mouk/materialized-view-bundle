<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Doctrine\Migrations\DependencyFactory;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\Persistence\ConnectionRegistry;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;
use Th3Mouk\MaterializedView\Core\Definition\PopulationPolicy;
use Th3Mouk\MaterializedView\Core\Definition\RebuildStrategy;
use Th3Mouk\MaterializedView\Core\Dependency\CatalogDependencyResolver;
use Th3Mouk\MaterializedView\Core\Dependency\DropDependentPolicy;
use Th3Mouk\MaterializedView\Core\Dependency\ExternalDependencyGuard;
use Th3Mouk\MaterializedView\Core\Hashing\DefinitionHasher;
use Th3Mouk\MaterializedView\Core\Introspection\PostgreSqlMaterializedViewIntrospector;
use Th3Mouk\MaterializedView\Core\Introspection\ReadinessChecker;
use Th3Mouk\MaterializedView\Core\MaterializedViewManager;
use Th3Mouk\MaterializedView\Core\Refresh\RefreshTargetResolver;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;
use Th3Mouk\MaterializedView\Core\Sync\MaterializedViewComparator;
use Th3Mouk\MaterializedView\Core\Sync\MissingDependencyPolicy;
use Th3Mouk\MaterializedView\DoctrineOrm\Listener\MaterializedViewPostLoadListener;
use Th3Mouk\MaterializedView\DoctrineOrm\Listener\MaterializedViewWriteGuard;
use Th3Mouk\MaterializedView\DoctrineOrm\Mapping\MaterializedViewMetadataReader;
use Th3Mouk\MaterializedView\DoctrineOrm\Readiness\MaterializedViewReadinessGuard as OrmReadinessGuard;
use Th3Mouk\MaterializedViewBundle\Command\DiffCommand;
use Th3Mouk\MaterializedViewBundle\Command\DoctrineLaneCommand;
use Th3Mouk\MaterializedViewBundle\Command\DropCommand;
use Th3Mouk\MaterializedViewBundle\Command\DumpSqlCommand;
use Th3Mouk\MaterializedViewBundle\Command\GenerateCommand;
use Th3Mouk\MaterializedViewBundle\Command\ListCommand;
use Th3Mouk\MaterializedViewBundle\Command\Port\DoctrineMigrationsPendingInspector;
use Th3Mouk\MaterializedViewBundle\Command\Port\FilesystemMaterializedViewScaffolder;
use Th3Mouk\MaterializedViewBundle\Command\Port\InitialRefreshDispatcher;
use Th3Mouk\MaterializedViewBundle\Command\Port\MaterializedViewScaffolder;
use Th3Mouk\MaterializedViewBundle\Command\Port\MessengerInitialRefreshDispatcher;
use Th3Mouk\MaterializedViewBundle\Command\Port\NoMigrationsPendingInspector;
use Th3Mouk\MaterializedViewBundle\Command\Port\PendingMigrationsInspector;
use Th3Mouk\MaterializedViewBundle\Command\Port\UnsupportedInitialRefreshDispatcher;
use Th3Mouk\MaterializedViewBundle\Command\PruneCommand;
use Th3Mouk\MaterializedViewBundle\Command\RefreshCommand;
use Th3Mouk\MaterializedViewBundle\Command\SyncCommand;
use Th3Mouk\MaterializedViewBundle\Command\ValidateCommand;
use Th3Mouk\MaterializedViewBundle\DependencyInjection\Compiler\MaterializedViewProviderPass;
use Th3Mouk\MaterializedViewBundle\Messenger\AsyncRefreshRequestHandler;
use Th3Mouk\MaterializedViewBundle\Messenger\ConnectionRegistryRefreshTargetResolver;
use Th3Mouk\MaterializedViewBundle\Messenger\SharedTransportGuard;
use Th3Mouk\MaterializedViewBundle\Messenger\TransportScope;
use Th3Mouk\MaterializedViewBundle\Readiness\MaterializedViewReadinessGuard;
use Th3Mouk\MaterializedViewBundle\Readiness\ReadinessCacheScope;
use Th3Mouk\MaterializedViewBundle\Registry\MaterializedViewRegistryBuilder;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()
        ->autoconfigure()
        ->private();

    $connection = service('th3mouk_materialized_view.connection');
    $logger = service('th3mouk_materialized_view.logger');

    $services->set('th3mouk_materialized_view.introspector', PostgreSqlMaterializedViewIntrospector::class)
        ->args([$connection, $logger]);
    $services->alias(PostgreSqlMaterializedViewIntrospector::class, 'th3mouk_materialized_view.introspector');

    $services->set('th3mouk_materialized_view.readiness_checker', ReadinessChecker::class)
        ->args([$connection, $logger]);
    $services->alias(ReadinessChecker::class, 'th3mouk_materialized_view.readiness_checker');

    $services->set('th3mouk_materialized_view.hasher', DefinitionHasher::class)
        ->factory([DefinitionHasher::class, 'create']);

    $services->set('th3mouk_materialized_view.comparator', MaterializedViewComparator::class)
        ->args([
            service('th3mouk_materialized_view.introspector'),
            service('th3mouk_materialized_view.hasher'),
        ]);
    $services->alias(MaterializedViewComparator::class, 'th3mouk_materialized_view.comparator');

    $services->set('th3mouk_materialized_view.dependency_resolver', CatalogDependencyResolver::class)
        ->args([$connection]);
    $services->alias(CatalogDependencyResolver::class, 'th3mouk_materialized_view.dependency_resolver');

    $services->set('th3mouk_materialized_view.external_dependency_guard', ExternalDependencyGuard::class)
        ->args([service('th3mouk_materialized_view.dependency_resolver'), $logger]);
    $services->alias(ExternalDependencyGuard::class, 'th3mouk_materialized_view.external_dependency_guard');

    $services->set('th3mouk_materialized_view.manager', MaterializedViewManager::class)
        ->factory([MaterializedViewManager::class, 'forConnection'])
        ->args([
            $connection,
            $logger,
            param('th3mouk_materialized_view.refresh.lock_namespace'),
        ]);
    $services->alias(MaterializedViewManager::class, 'th3mouk_materialized_view.manager');

    $services->set(MaterializedViewProviderPass::REGISTRY_BUILDER_SERVICE, MaterializedViewRegistryBuilder::class)
        ->args(['$providers' => abstract_arg('filled by MaterializedViewProviderPass'), '$providerMethods' => []]);

    $services->set('th3mouk_materialized_view.registry', MaterializedViewRegistry::class)
        ->factory([service(MaterializedViewProviderPass::REGISTRY_BUILDER_SERVICE), 'build']);
    $services->alias(MaterializedViewRegistry::class, 'th3mouk_materialized_view.registry');

    $services->set('th3mouk_materialized_view.transport_scope', TransportScope::class)
        ->factory([TransportScope::class, 'from'])
        ->args([param('th3mouk_materialized_view.async.transport_scope')]);

    $services->set('th3mouk_materialized_view.shared_transport_guard', SharedTransportGuard::class)
        ->args([
            param('th3mouk_materialized_view.async.require_shared_transport'),
            service('th3mouk_materialized_view.transport_scope'),
        ]);
    $services->alias(SharedTransportGuard::class, 'th3mouk_materialized_view.shared_transport_guard');

    $services->set('th3mouk_materialized_view.readiness.cache_scope', ReadinessCacheScope::class)
        ->factory([ReadinessCacheScope::class, 'from'])
        ->args([param('th3mouk_materialized_view.readiness.cache_scope')]);

    $services->set('th3mouk_materialized_view.readiness_guard', MaterializedViewReadinessGuard::class)
        ->args([
            service('th3mouk_materialized_view.readiness_checker'),
            service('th3mouk_materialized_view.readiness.cache_scope'),
        ])
        ->tag('kernel.reset', ['method' => 'reset']);
    $services->alias(MaterializedViewReadinessGuard::class, 'th3mouk_materialized_view.readiness_guard');

    $migrationsAvailable = class_exists(DependencyFactory::class);

    if ($migrationsAvailable) {
        $services->set('th3mouk_materialized_view.port.pending_migrations_inspector', DoctrineMigrationsPendingInspector::class)
            ->args([service('doctrine.migrations.dependency_factory')]);
    } else {
        $services->set('th3mouk_materialized_view.port.pending_migrations_inspector', NoMigrationsPendingInspector::class);
    }

    $services->alias(PendingMigrationsInspector::class, 'th3mouk_materialized_view.port.pending_migrations_inspector');

    $services->set('th3mouk_materialized_view.sync.default_population_policy', PopulationPolicy::class)
        ->factory([PopulationPolicy::class, 'from'])
        ->args([param('th3mouk_materialized_view.sync.default_population_policy')]);

    $services->set('th3mouk_materialized_view.sync.default_rebuild_strategy', RebuildStrategy::class)
        ->factory([RebuildStrategy::class, 'from'])
        ->args([param('th3mouk_materialized_view.sync.default_rebuild_strategy')]);

    $services->set('th3mouk_materialized_view.sync.on_missing_dependency', MissingDependencyPolicy::class)
        ->factory([MissingDependencyPolicy::class, 'from'])
        ->args([param('th3mouk_materialized_view.sync.on_missing_dependency')]);

    $services->set('th3mouk_materialized_view.drop.on_external_dependent', DropDependentPolicy::class)
        ->factory([DropDependentPolicy::class, 'from'])
        ->args([param('th3mouk_materialized_view.drop.on_external_dependent')]);

    $services->set('th3mouk_materialized_view.port.scaffolder', FilesystemMaterializedViewScaffolder::class)
        ->args([
            service('th3mouk_materialized_view.filesystem'),
            param('th3mouk_materialized_view.scaffold.sql_directory'),
            param('th3mouk_materialized_view.scaffold.provider_directory'),
            param('th3mouk_materialized_view.scaffold.provider_namespace'),
            param('th3mouk_materialized_view.generation.file_naming'),
            param('th3mouk_materialized_view.default_schema'),
            param('kernel.project_dir'),
            service('th3mouk_materialized_view.sync.default_population_policy'),
            service('th3mouk_materialized_view.sync.default_rebuild_strategy'),
        ]);
    $services->alias(MaterializedViewScaffolder::class, 'th3mouk_materialized_view.port.scaffolder');

    $services->set('th3mouk_materialized_view.filesystem', Filesystem::class);

    $messengerAvailable = interface_exists(MessageBusInterface::class);

    if ($messengerAvailable) {
        $services->set('th3mouk_materialized_view.port.initial_refresh_dispatcher', MessengerInitialRefreshDispatcher::class)
            ->args([
                service('messenger.default_bus'),
                $connection,
                service('th3mouk_materialized_view.shared_transport_guard'),
                param('th3mouk_materialized_view.connection'),
            ]);
    } else {
        $services->set('th3mouk_materialized_view.port.initial_refresh_dispatcher', UnsupportedInitialRefreshDispatcher::class);
    }

    $services->alias(InitialRefreshDispatcher::class, 'th3mouk_materialized_view.port.initial_refresh_dispatcher');

    $services->set('th3mouk_materialized_view.command.list', ListCommand::class)
        ->args([
            service('th3mouk_materialized_view.registry'),
            service('th3mouk_materialized_view.comparator'),
            service('th3mouk_materialized_view.introspector'),
            service('th3mouk_materialized_view.readiness_checker'),
        ]);

    $services->set('th3mouk_materialized_view.command.validate', ValidateCommand::class)
        ->args([
            service('th3mouk_materialized_view.registry'),
            service('th3mouk_materialized_view.comparator'),
            service('th3mouk_materialized_view.introspector'),
            service('th3mouk_materialized_view.readiness_checker'),
            service('th3mouk_materialized_view.external_dependency_guard'),
        ]);

    $services->set('th3mouk_materialized_view.command.diff', DiffCommand::class)
        ->args([
            service('th3mouk_materialized_view.registry'),
            service('th3mouk_materialized_view.comparator'),
        ]);

    $services->set('th3mouk_materialized_view.command.drop', DropCommand::class)
        ->args([
            service('th3mouk_materialized_view.registry'),
            service('th3mouk_materialized_view.manager'),
            service('th3mouk_materialized_view.dependency_resolver'),
            service('th3mouk_materialized_view.port.pending_migrations_inspector'),
            service('th3mouk_materialized_view.drop.on_external_dependent'),
        ]);

    $services->set('th3mouk_materialized_view.command.sync', SyncCommand::class)
        ->args([
            service('th3mouk_materialized_view.registry'),
            service('th3mouk_materialized_view.manager'),
            service('th3mouk_materialized_view.comparator'),
            service('th3mouk_materialized_view.port.initial_refresh_dispatcher'),
            param('th3mouk_materialized_view.sync.analyze_after_sync'),
            param('th3mouk_materialized_view.sync.preserve_existing_grants'),
            param('th3mouk_materialized_view.sync.prune_orphans_by_default'),
            param('th3mouk_materialized_view.sync.async_requires_target_resolver'),
            service('th3mouk_materialized_view.sync.on_missing_dependency'),
            service('th3mouk_materialized_view.drop.on_external_dependent'),
        ]);

    $services->set('th3mouk_materialized_view.command.prune', PruneCommand::class)
        ->args([
            service('th3mouk_materialized_view.registry'),
            service('th3mouk_materialized_view.manager'),
            service('th3mouk_materialized_view.comparator'),
        ]);

    $services->set('th3mouk_materialized_view.command.refresh', RefreshCommand::class)
        ->args([
            service('th3mouk_materialized_view.registry'),
            service('th3mouk_materialized_view.manager'),
            service('th3mouk_materialized_view.introspector'),
            service('th3mouk_materialized_view.readiness_checker'),
            param('th3mouk_materialized_view.refresh.analyze_after_refresh'),
            param('th3mouk_materialized_view.refresh.lock_timeout'),
            param('th3mouk_materialized_view.refresh.statement_timeout'),
        ]);

    $services->set('th3mouk_materialized_view.command.generate', GenerateCommand::class)
        ->args([service('th3mouk_materialized_view.port.scaffolder')]);

    $services->set('th3mouk_materialized_view.command.dump_sql', DumpSqlCommand::class)
        ->args([service('th3mouk_materialized_view.registry')]);

    if ($migrationsAvailable) {
        $services->set('th3mouk_materialized_view.command.doctrine_lane', DoctrineLaneCommand::class)
            ->args([
                service('doctrine.migrations.dependency_factory'),
                service('th3mouk_materialized_view.registry'),
                param('th3mouk_materialized_view.lane.lock_namespace'),
                $logger,
            ]);
    }

    $services->set('th3mouk_materialized_view.messenger.target_resolver', ConnectionRegistryRefreshTargetResolver::class)
        ->args([service('doctrine')]);
    $services->alias(RefreshTargetResolver::class, 'th3mouk_materialized_view.messenger.target_resolver');

    if ($messengerAvailable) {
        $services->set('th3mouk_materialized_view.messenger.async_refresh_handler', AsyncRefreshRequestHandler::class)
            ->args([
                service('th3mouk_materialized_view.registry'),
                service('th3mouk_materialized_view.messenger.target_resolver'),
                service('th3mouk_materialized_view.shared_transport_guard'),
                $logger,
                param('th3mouk_materialized_view.refresh.lock_namespace'),
            ]);
    }

    if (interface_exists(ConnectionRegistry::class) && class_exists(MaterializedViewMetadataReader::class) && class_exists(OnFlushEventArgs::class)) {
        $services->set('th3mouk_materialized_view.orm.metadata_reader', MaterializedViewMetadataReader::class);
        $services->alias(MaterializedViewMetadataReader::class, 'th3mouk_materialized_view.orm.metadata_reader');

        $services->set('th3mouk_materialized_view.orm.readiness_guard', OrmReadinessGuard::class)
            ->args([
                service('doctrine.orm.entity_manager'),
                service('th3mouk_materialized_view.orm.metadata_reader'),
                service('th3mouk_materialized_view.readiness_checker'),
                $logger,
            ]);
        $services->alias(OrmReadinessGuard::class, 'th3mouk_materialized_view.orm.readiness_guard');

        $services->set('th3mouk_materialized_view.orm.write_guard', MaterializedViewWriteGuard::class)
            ->args([service('th3mouk_materialized_view.orm.metadata_reader'), $logger])
            ->tag('doctrine.event_listener', ['event' => 'onFlush']);

        $services->set('th3mouk_materialized_view.orm.post_load_listener', MaterializedViewPostLoadListener::class)
            ->args([service('th3mouk_materialized_view.orm.metadata_reader')])
            ->tag('doctrine.event_listener', ['event' => 'postLoad']);
    }
};
