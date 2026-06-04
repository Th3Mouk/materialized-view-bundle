# Doctrine Migrations references (maintainers)

The `matview:doctrine-lane` runs migrations **programmatically, in-process**, while holding a per-database advisory lock. These are the contracts it relies on (verified in vendor at design time: `doctrine/migrations` 3.9.5, `doctrine/doctrine-migrations-bundle` 4.x). Re-verify on bumps.

- **Migration classes & lifecycle** — <https://www.doctrine-project.org/projects/doctrine-migrations/en/3.9/reference/migration-classes.html>
- **Migrations configuration** — <https://www.doctrine-project.org/projects/doctrine-migrations/en/3.9/reference/configuration.html>

## The service to inject

- Service id **`doctrine.migrations.dependency_factory`** (defined in `doctrine/doctrine-migrations-bundle` `config/services.php`).
- **No autowiring alias exists** for `Doctrine\Migrations\DependencyFactory` — inject explicitly:

```php
public function __construct(
    #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'doctrine.migrations.dependency_factory')]
    private readonly \Doctrine\Migrations\DependencyFactory $dependencyFactory,
) {}
```

## Detecting pending migrations

Same logic as `doctrine:migrations:up-to-date`:

```php
$status = $this->dependencyFactory->getMigrationStatusCalculator();
$pending = $status->getNewMigrations();   // AvailableMigrationsList (Countable)
$hasPending = \count($pending) > 0;
```

- `MigrationStatusCalculator::getNewMigrations(): AvailableMigrationsList` — verified.
- `TableMetadataStorage::getExecutedMigrations()` returns an empty list when the metadata table does not yet exist (fresh DB cloned from a template) — so `getNewMigrations()` is safe to call before any migration ran (it returns "all pending"), no exception.

## Running migrations in-process

The lane (`ConsoleLaneMigrator`) uses the **in-process command path**, not the direct migrator:

```php
$application->find('doctrine:migrations:migrate')->run(new ArrayInput([...]), $output);
```

This preserves config/output/events. The direct `Migrator` path is intentionally not used.

### The `doctrine:migrations:migrate` command id

`$application->find('doctrine:migrations:migrate')` resolves **only because `doctrine/doctrine-migrations-bundle` re-registers `MigrateCommand` under that name**. The native command's `#[AsCommand]` name is `migrations:migrate` (verified: `doctrine/migrations` `src/Tools/Console/Command/MigrateCommand.php`). The bundle service `doctrine_migrations.migrate_command` passes `'doctrine:migrations:migrate'` as the command name and tags it `console.command` with that name (verified: `doctrine/doctrine-migrations-bundle` `config/services.php`). The lane therefore **depends on `doctrine-migrations-bundle` being installed** — re-verify the renamed id on every bundle bump.

Verified symbols (used elsewhere, not by the lane): `DependencyFactory::getMigrator()`, `getMigrationPlanCalculator()`, `getMigrationStatusCalculator()`; `Migrator::migrate(MigrationPlanList, MigratorConfiguration)`.

## The hard limit to remember

**Doctrine Migrations exposes no reliable API for the tables a pending migration will touch.** Migrations are arbitrary `addSql()` (possibly raw/dynamic). This is *why* `drop --if-pending` is conservative (drop-all) at MVP and a targeted drop is an opt-in extension (an app-supplied `MigrationImpactResolver`, or a reactive catch-and-retry). Do not regress this into a fragile static SQL parser.

## Lock + connection co-location

- The advisory lock must be taken on the **same DBAL connection** the migrator uses, **after** `ensureConnectedToPrimary()`.
- With `keep_replica: false`, once switched to primary the connection stays on primary for the session — lock and migrations are co-located. Re-verify if that setting changes.

See the boot lane guide: [`../guide/boot-lane.md`](../guide/boot-lane.md).
