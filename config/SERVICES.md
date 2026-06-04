# DI service contract (for `config/services.php`)

`Th3MoukMaterializedViewBundle::loadExtension()` imports `../config/services.php` (written in the Assembly phase) and:

- registers attribute autoconfiguration for `#[AsMaterializedViewProvider]`, tagging matching classes with `th3mouk.materialized_view_provider` (`AsMaterializedViewProvider::TAG`) and a `method` attribute (default `definitions`);
- stores the processed configuration as the container parameter `th3mouk_materialized_view.config` (`Configuration::ALIAS . '.config'`);
- registers `MaterializedViewProviderPass` (added in `build()`), which collects the tagged providers into a `ServiceLocator` and injects it, plus the `id => method` map, into the registry-builder service.

## Services `config/services.php` MUST declare

| Service id | Class | Notes |
|---|---|---|
| `th3mouk_materialized_view.registry_builder` | `Th3Mouk\MaterializedViewBundle\Registry\MaterializedViewRegistryBuilder` | Constant `MaterializedViewProviderPass::REGISTRY_BUILDER_SERVICE`. Leave both arguments (`$providers`, `$providerMethods`) unset/empty: the compiler pass fills them. The pass is a no-op if this service is absent. |
| `th3mouk_materialized_view.registry` | `Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry` | Built via a factory: `[service('th3mouk_materialized_view.registry_builder'), 'build']`. |
| `th3mouk_materialized_view.manager` | `Th3Mouk\MaterializedView\Core\MaterializedViewManager` | Built via factory `MaterializedViewManager::forConnection($connection, $logger, $refreshLockNamespace)`. The DBAL connection comes from the configured `connection` option (`doctrine.dbal.<name>_connection`); `$refreshLockNamespace` from `refresh.lock_namespace`. |

## Aliases `config/services.php` SHOULD declare

- `Th3Mouk\MaterializedView\Core\MaterializedViewManager` → `th3mouk_materialized_view.manager` (autowiring).
- `Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry` → `th3mouk_materialized_view.registry` (autowiring).

## Configuration values the wiring needs

`exposeParameters()` flattens the processed config into discrete `th3mouk_materialized_view.*` parameters (see `Configuration`); `config/services.php` reads them with `param(...)`. The whole tree is also stored as `th3mouk_materialized_view.config`, but no service reads that array — `loadExtension()` reads the raw config once more, only to gate the ORM write guard.

- `connection` → which `doctrine.dbal.*_connection` to inject.
- `refresh.lock_namespace`, `lane.lock_namespace` → reserved advisory-lock namespaces.
- **Effective** — `sync.*`, `async.*`, `refresh.analyze_after_refresh`, `refresh.lock_timeout`, `refresh.statement_timeout`, `readiness.cache_scope`, `doctrine.orm_write_guard`, `generation.file_naming`, `default_schema`, `sql_paths[0]` → consumed by the command/lane/messenger/readiness/ORM services wired in their own domains. Specifically: `refresh.lock_timeout` / `refresh.statement_timeout` seed `RefreshOptions` when `matview:refresh` runs without `--timeout`; the three `sync.*` toggles seed `SyncOptions` in `matview:sync`; `readiness.cache_scope` drives the `MaterializedViewReadinessGuard` (`kernel.reset`) cache behaviour; `doctrine.orm_write_guard: false` removes the ORM write-guard listener.
- **Reserved (validated by the schema, not yet effective)** — `metadata.*`, `template.policy`, `lane.drop_strategy` (only `all_on_pending` acts), `lane.use_advisory_lock`, `lane.minimum_memory_limit`, `refresh.use_advisory_locks`, `sql_paths[1..]` → accepted by `Configuration`; behaviour planned in the spec, not implemented in the core at this stage. See `docs/guide/configuration-reference.md`.
