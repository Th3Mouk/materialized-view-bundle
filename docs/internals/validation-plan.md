# Validation plan (maintainers) — bundle

Bundle-level and multi-database boot scenarios. Core scenarios are in the native library's [validation plan](../../materialized-view/docs/internals/validation-plan.md).

## Bundle (unit / kernel)

- Kernel test: the bundle boots and registers services.
- Autoconfiguration: `#[AsMaterializedViewProvider]` classes are collected into the registry, without an application interface (incl. the `method:` variant).
- Configuration tree: defaults, validation, and each option in [Configuration reference](../guide/configuration-reference.md).
- Command wiring: `list`, `validate`, `diff`, `drop --if-pending`, `sync`, `prune`, `doctrine-lane`, `refresh`, `dump-sql`, `generate`.
- `DependencyFactory` injected via `#[Autowire(service: 'doctrine.migrations.dependency_factory')]`.
- `Async` refuses a per-connection Doctrine transport when no shared transport is configured.

## Integration (PostgreSQL + test kernel)

- `PrimaryReadReplicaConnection`: DDL/refresh route to the primary.
- `matview:doctrine-lane` holds a session-level advisory lock across `drop → migrate → sync`.
- The lane lock is taken on the primary and on the **same connection** as Doctrine Migrations; released in `finally`.
- The lane can invoke the existing `MigrateCommand` in-process without losing console output; explicit `MigratorConfiguration` if using the migrator directly.
- Async policy: `sync` does not perform a blocking initial refresh without an explicit option; the worker resolves the correct target DB before refreshing.
- `template.policy`: `empty`, `cloned_stale`, `maintained_template`; `maintained_template` documents/refuses a maintainer connection held during a clone.

## Boot scenarios (multiple databases)

- Run the boot script in `--dry-run`; verify the lane runs once per discovered database.
- Migration altering a column used by a matview: without the lane PostgreSQL blocks; with the lane the migration passes and `sync` rebuilds.
- Simulated rolling deploy: two concurrent lanes on the same database serialise via the advisory lock.
- Migration failure: the lane does not run `sync`; it exposes the degraded state.
- Old version serving traffic during the lane: readiness/fallback on an absent or unpopulated projection.
- Database cloned from a template with an already-commented matview: validate/refresh per `template.policy`; object GRANTs present, database-level GRANTs re-applied by provisioning.
