# Configuration reference

Full annotated configuration. Defaults are chosen to be safe for a reusable library that may run the same views across many databases/connections.

> **Effective vs reserved options (current stage).** Most options are active. The following are **reserved** — accepted and validated by the config schema, but **not yet effective** (they map to behaviour described in the spec but not implemented in the core at this stage). They are documented so the schema stays stable:
> - `metadata.storage` / `metadata.table_name` — only the `COMMENT`-hash store exists; the optional metadata table is not written yet.
> - `template.policy` — template-clone handling is not implemented in the core yet.
> - `lane.drop_strategy` — only `all_on_pending` is effective; `reactive_retry` / `custom_impact` require a future `MigrationImpactResolver`.
> - `lane.use_advisory_lock` / `lane.minimum_memory_limit` — the lane always locks; these are advisory only.
> - `sql_paths` — only the first entry is currently consumed (scaffolding output).

```yaml
th3mouk_materialized_view:
    # DBAL connection used for DDL/refresh (must reach the primary).
    connection: default
    default_schema: public

    # Where the .sql definition files live.
    sql_paths:
        - '%kernel.project_dir%/db/matviews'

    generation:
        # stable: db/matviews/<name>.sql (recommended; version lives in the COMMENT hash)
        # versioned: db/matviews/<name>_vNNN.sql (keeps every past definition on disk)
        file_naming: stable          # stable | versioned

    metadata:
        # Where the canonical hash / management marker is stored.
        storage: comment             # comment | comment_and_table
        table_name: th3mouk_materialized_view_refresh_log

    sync:
        default_rebuild_strategy: drop_create   # drop_create | side_by_side
        default_population_policy: async        # manual | async | synchronous
        async_requires_target_resolver: true    # safety: refuse async without a refresh target resolver (you likely run the same views across many databases)
        analyze_after_sync: true
        preserve_existing_grants: true          # snapshot & replay GRANTs across rebuilds
        prune_orphans_by_default: false         # never drop undeclared views implicitly
        on_missing_dependency: fail             # fail | skip — skip past views whose schema/table is absent

    drop:
        on_external_dependent: refuse           # refuse | cascade — cascade emits DROP ... CASCADE

    async:
        # Async population/refresh requires a SHARED transport (AMQP/Redis/SQS), not a
        # per-connection Doctrine transport. See guide/async-refresh.md.
        require_shared_transport: true
        transport_scope: shared      # shared | per_connection_doctrine

    lane:
        use_advisory_lock: true
        lock_namespace: 392818       # reserved int4 namespace; document it app-wide
        minimum_memory_limit: '512M' # the lane does more than `migrate`
        # MVP default is conservative drop-all; targeted/reactive are opt-in extensions.
        drop_strategy: all_on_pending # all_on_pending | reactive_retry | custom_impact

    refresh:
        use_advisory_locks: true
        lock_namespace: 392817       # distinct from the lane namespace
        analyze_after_refresh: true
        lock_timeout: '10s'
        statement_timeout: '0'

    readiness:
        cache_scope: request         # request | process | none

    template:
        # Behaviour for databases cloned from a PostgreSQL template.
        policy: empty                # empty | cloned_stale | maintained_template

    doctrine:
        orm_write_guard: true        # register the onFlush write guard for matview entities

    logging:
        # Monolog bridge (only effective when MonologBundle is installed; the core
        # library stays framework-agnostic and logs through PSR-3).
        enabled: true                # false → NullLogger (silent)
        channel: materialized_view   # a dedicated channel, or an existing one (e.g. "migration")
```

## Notes on key options

- **`sync.on_missing_dependency`** — default `fail`. When a view's referenced schema or table is absent, PostgreSQL raises `SQLSTATE 42P01` (undefined_table) / `3F000` (invalid_schema_name) and `matview:sync` aborts the whole run. Set `skip` to log a warning and continue with the other views; skipped views are reported in the `matview:sync` outcome (a dedicated *Skipped* bucket). The SQLSTATE is read from the DBAL exception chain (not matched on message text). Other database errors always abort, regardless of this setting. This node is a **scalar** (not an enum) so it can be driven per environment by an env var — e.g. `on_missing_dependency: '%env(MATVIEW_ON_MISSING_DEPENDENCY)%'`, with `fail` in production and `skip` in local/UAT where an external dependency (an FDW, another schema) may be absent. An out-of-range value is rejected at runtime by `MissingDependencyPolicy::from()` rather than at container compile.
- **`drop.on_external_dependent`** — default `refuse`, which keeps the `ExternalDependencyGuard` behaviour: a managed view with an **unmanaged** dependent (e.g. a hand-made view or an external consumer) cannot be dropped or rebuilt, and `CASCADE` is never implicit. Set `cascade` when external consumers are out of scope and managed views must be freely droppable/recreatable by migrations: `matview:drop`, the synchronizer rebuild and `MaterializedViewManager::drop` then emit `DROP MATERIALIZED VIEW ... CASCADE` and the guard no longer blocks. See [Rebuild strategies](../../materialized-view/docs/guide/rebuild-strategies.md).
- **`lane.drop_strategy`** — `all_on_pending` is the safe MVP default: when any migration is pending, drop all managed views, migrate, then `sync` recreates them. Doctrine cannot tell you which tables a migration touches, so a *targeted* drop is an opt-in extension (`custom_impact` with an app-supplied resolver) or a `reactive_retry` mode. See [Boot lane](boot-lane.md) and the core [Doctrine references](../../materialized-view/docs/internals/doctrine-references.md).
- **`async.*`** — `Async` only works with a shared transport. With a per-connection Doctrine transport the bundle refuses (or you must orchestrate draining). See [Async refresh](async-refresh.md).
- **`refresh.lock_namespace` / `lane.lock_namespace`** — reserved advisory-lock namespaces; keep them distinct from any other application advisory locks. Keys are stable and computed in PHP (never `hashtext`). Advisory locks are per-database, so separate databases don't collide.
- **`template.policy`** — pick `empty` or `maintained_template` deliberately when you clone databases from a template. See [Templates & cloning](templates-and-cloning.md).
- **`metadata.storage`** — `comment` travels with database clones; `comment_and_table` adds refresh observability.
- **`logging.*`** — the **Monolog bridge**. The core library is framework-agnostic and logs through PSR-3 (`NullLogger` by default). When MonologBundle is installed, the bundle declares the `channel` (via `prependExtension`) and binds a single channel logger (`th3mouk_materialized_view.logger` → `monolog.logger.<channel>`) into every service, so every library log lands on one channel. Use a **dedicated** channel (default `materialized_view`) or point `channel` at an **existing** application channel (e.g. `migration`) to reuse its handlers/formatters. `enabled: false` forces a `NullLogger` regardless of Monolog; without MonologBundle the bundle falls back to the framework `logger` service, then to `NullLogger`. The levels emitted by the core (`debug`/`info`/`notice`/`warning`) are documented in the core CHANGELOG.
