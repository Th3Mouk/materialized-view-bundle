# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/Th3Mouk/materialized-view-bundle/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/Th3Mouk/materialized-view-bundle/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/Th3Mouk/materialized-view-bundle/releases/tag/v1.0.0
