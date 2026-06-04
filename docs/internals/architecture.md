# Architecture (maintainers)

The bundle is a **thin Symfony adapter** over `th3mouk/materialized-view`. It owns wiring, discovery, commands, the lane, async dispatch and ORM guard registration — never domain logic.

## Layout

```text
th3mouk/materialized-view-bundle
├── config/                 # service definitions (services.php)
├── src/
│   ├── Th3MoukMaterializedViewBundle.php   # AbstractBundle::loadExtension + autoconfiguration
│   ├── Attribute/          # #[AsMaterializedViewProvider]
│   ├── Command/            # matview:* console commands
│   ├── DependencyInjection/# Configuration tree + extension glue
│   ├── Lane/               # doctrine-lane orchestration (advisory lock + MigrateCommand)
│   ├── Messenger/          # AsyncRefreshRequest handler + RefreshTargetResolver wiring
│   └── Readiness/          # injectable readiness guard for repositories / API Platform state providers
└── tests/
```

## Class map (folder → planned classes)

| Folder | Planned classes | Role |
|---|---|---|
| `src` (root) | `Th3MoukMaterializedViewBundle` | `loadExtension`, `registerAttributeForAutoconfiguration` |
| `Attribute` | `AsMaterializedViewProvider` | Marks a class as a definition provider (default method `definitions()`) |
| `DependencyInjection` | `Configuration`, extension/compiler glue | The config tree → services |
| `Command` | `GenerateCommand`, `ListCommand`, `ValidateCommand`, `DiffCommand`, `DropCommand`, `SyncCommand`, `PruneCommand`, `RefreshCommand`, `DumpSqlCommand`, `DoctrineLaneCommand` | The `matview:*` surface |
| `Lane` | `DoctrineLane` | Advisory lock + primary + in-process migrate + sync |
| `Messenger` | `AsyncRefreshRequestHandler`, a `RefreshTargetResolver` implementation | Dispatch/consume async refreshes, resolving the target database per message |
| `Readiness` | `MaterializedViewReadinessGuard` | Guard reads on unpopulated views |
| `config` | `services.php` | DI wiring |

## Discovery (autoconfiguration)

The bundle registers attribute autoconfiguration, so any application class carrying `#[AsMaterializedViewProvider]` is tagged and collected into the registry — no interface required:

```php
$builder->registerAttributeForAutoconfiguration(
    AsMaterializedViewProvider::class,
    static function (ChildDefinition $definition, AsMaterializedViewProvider $attribute): void {
        $definition->addTag('th3mouk.materialized_view_provider', ['method' => $attribute->method]);
    },
);
```

This is deliberately more idiomatic than a filesystem scan: Symfony's container collects tagged services and the brittle PSR-4 reverse-resolution other libraries use is avoided.

## Service surface (per connection)

The bundle builds the core `MaterializedViewManager` (and supporting services) from the configured DBAL connection, and a registry from the tagged providers. Commands and the lane depend on those services. Lazy where the work is expensive.

## What stays in the core

Definitions, SQL generation, introspection, dependency resolution, rebuild strategies, refresh runtime, locks, hashing, privileges and the read-only ORM mapping all live in `th3mouk/materialized-view`. If you find domain logic creeping into the bundle, push it down.
