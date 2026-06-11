# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.2.2] - 2026-06-11

### Fixed
- **Doctrine migration progress is logged even when the database is up to date.** The
  in-process migrator returned early on an empty plan, and the reactive lane skipped the
  migrator entirely when no migration was pending — so an up-to-date boot logged nothing from
  the migration step. The migrator now always runs (Doctrine logs `No migrations to execute.`
  on an empty plan, `++ migrating … / … migrated` otherwise), and the reactive lane invokes it
  even with nothing pending. Verified end-to-end: with the lane logger now reaching the
  migration channel (1.2.1), these lines appear on stdout/Datadog alongside `Lane starting`.

## [1.2.1] - 2026-06-11

### Fixed
- **Deploy lane now applies the configured `sync.on_missing_dependency` and
  `drop.on_external_dependent` policies.** `matview:doctrine-lane` synchronised views with
  `SyncOptions::default()` (`fail` / `refuse`), so a configured `on_missing_dependency: skip`
  was silently ignored at boot — a missing schema/FDW aborted the lane instead of being
  skipped. The command now threads both policies into `syncAll()` and logs the effective
  policy (`Lane starting`).
- **Per-migration progress is logged again under `reactive_retry`.**
  `DoctrineMigrationsLaneMigrator` built its in-process `DependencyFactory` without a logger,
  so Doctrine fell back to a `NullLogger` and emitted no `++ migrating … / … migrated` lines.
  The lane's logger is now forwarded to the factory.

## [1.2.0] - 2026-06-09

### Added
- **Reactive deploy-lane drop strategy (`lane.drop_strategy: reactive_retry`).**
  The deploy lane (`matview:doctrine-lane`) can now clear a migration blocked by a
  managed materialized view by dropping **only** the conflicting closure and
  retrying, instead of dropping every managed view up front:
  ```yaml
  th3mouk_materialized_view:
      lane:
          drop_strategy: reactive_retry   # default: all_on_pending
  ```
  It migrates first; on a dependency-conflict SQLSTATE (`2BP01`/`0A000`) it drops
  the blocking managed closure in its own transaction and re-runs the migrator
  (which resumes from the failed version). A bounded progress guard
  (`ReactiveDropMadeNoProgress`) aborts rather than loop on an unresolvable
  conflict, and a pending **non-transactional** migration falls back to the
  `all_on_pending` behaviour. `all_on_pending` remains the default, so existing
  deployments are unaffected. The reserved `custom_impact` value now fails loudly
  (`UnsupportedLaneDropStrategy`) rather than silently behaving like another
  strategy.

  Internally this adds `DoctrineMigrationsLaneMigrator` (drives migrations
  in-process so the original DBAL exception — and its SQLSTATE — survives, which
  the console migrator cannot), backed by the new library v1.2 primitives
  (`PostgresDependencyConflict`, `CatalogDependencyResolver::resolveConflictClosure()`,
  `MaterializedViewManager::dropConflictClosure()`).

### Changed
- Requires `th3mouk/materialized-view` `^1.2`.

## [1.1.0] - 2026-06-05

### Added
- **Monolog bridge.** A new `logging` configuration section routes the library's
  PSR-3 logs to a Monolog channel:
  ```yaml
  th3mouk_materialized_view:
      logging:
          enabled: true            # false → a NullLogger (silent), whatever Monolog does
          channel: materialized_view
  ```
  When MonologBundle is installed, the bundle declares the channel (via
  `prependExtension`) and binds a single channel logger
  (`th3mouk_materialized_view.logger` → `monolog.logger.<channel>`) into every core
  service. Point `channel` at a **dedicated** stream (the default) or at an
  **existing application channel** (e.g. `migration`) for fine-grained control of
  handlers and formatters. Without MonologBundle it falls back to the framework
  `logger` service, and to a `NullLogger` when none is available.

### Changed
- Requires `th3mouk/materialized-view` `^1.1` (its services now accept a PSR-3
  logger). The native library remains framework-agnostic; this bundle is the only
  Monolog-aware layer.

## [1.0.0] - 2026-06-05

### Added
- Initial release. Symfony integration for `th3mouk/materialized-view`:
  autoconfiguration of `#[AsMaterializedViewProvider]` view definitions, the
  `matview:*` console commands (`generate`, `list`, `validate`, `diff`, `drop`,
  `sync`, `prune`, `refresh`, `dump-sql`, `doctrine-lane`), the locked deploy lane
  (`drop --if-pending → migrate → sync` in one advisory-locked process per
  database), async refresh dispatching over Messenger, read-only ORM guards, and
  the `sync.on_missing_dependency` / `drop.on_external_dependent` policies.

### Changed
- Development dependency `phpunit/phpunit` upgraded to `^13.0`.

[Unreleased]: https://github.com/Th3Mouk/materialized-view-bundle/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/Th3Mouk/materialized-view-bundle/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/Th3Mouk/materialized-view-bundle/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/Th3Mouk/materialized-view-bundle/releases/tag/v1.0.0
