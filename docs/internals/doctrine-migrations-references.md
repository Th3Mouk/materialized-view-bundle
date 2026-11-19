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

## Collecting the SQL of pending migrations (dry run)

Under `reactive_retry`, a pending non-transactional migration only keeps the drop-all fallback when it contains a statement that can raise a dependency conflict. Its SQL comes from Doctrine Migrations' own dry run, never from parsing migration files — **Dry Run** — <https://www.doctrine-project.org/projects/doctrine-migrations/en/3.9/reference/managing-migrations.html#dry-run> (the `migrate --dry-run` contract), with the migration's `up()` and `addSql()` described in [Migration classes](https://www.doctrine-project.org/projects/doctrine-migrations/en/3.9/reference/migration-classes.html):

```php
$factory = DependencyFactory::fromConnection(new ExistingConfiguration($configuration), new ExistingConnection($connection));
$factory->setService(MetadataStorage::class, new DryRunMetadataStorage($storage));

$plan = $factory->getMigrationPlanCalculator()->getPlanForVersions($versions, Direction::UP);
$sqlByVersion = $factory->getMigrator()->migrate($plan, new MigratorConfiguration()->setDryRun(true)->setAllOrNothing(false));
// array<string, Query[]>: version => the planned statements, read with Query::getStatement()
```

The statements are handed to the core's `DependencySafeStatement`; the bundle holds no SQL knowledge.

- **A fresh `DependencyFactory`** is built for the dry run: `FilesystemMigrationsRepository` caches migration instances and `AbstractMigration::freeze()` makes an instance reject further `addSql()` once it has run, so reusing the lane's factory would break the real run that follows.
- **`DryRunMetadataStorage`** wraps the real storage and hides its `getSql()`. `DbalExecutor` appends the storage's bookkeeping statements (`-- Version … update table metadata`, `INSERT INTO doctrine_migration_versions …`) to a dry run's SQL whenever `method_exists($storage, 'getSql')`; without the wrapper every migration would look unsafe.
- A dry run still calls `preUp()`, `up()` and `postUp()` and reads the schema; SQL a migration runs directly on the connection instead of through `addSql()` is executed by the dry run, exactly as with `doctrine:migrations:migrate --dry-run`.
- Any failure while planning or collecting (a throwing `up()`, a missing class, a database error) marks every pending non-transactional migration as dependency-unsafe: the drop-all fallback is kept.
- No logger is given to the dry run's factory, so it emits no `++ migrating` progress for a migration that does not run.

*Verified in vendor `doctrine/migrations` 3.9.8: `MigrationPlanCalculator::getPlanForVersions()`, `MigratorConfiguration::setDryRun()`, `Migrator::migrate(): array<string, Query[]>` (`DbalMigrator`), `DbalExecutor::executeMigration()` (dry-run branch and the `getSql` bookkeeping), `Query::getStatement()`, `DependencyFactory::setService()`.*

## The hard limit to remember

**Doctrine Migrations exposes no reliable API for the tables a pending migration will touch.** Migrations are arbitrary `addSql()` (possibly raw/dynamic). This is *why* `drop --if-pending` is conservative (drop-all) at MVP and a targeted drop is an opt-in extension (an app-supplied `MigrationImpactResolver`, or a reactive catch-and-retry). The dry run above does not lift it: it only yields the statements, which the lane uses to recognise migrations that provably cannot conflict. Do not regress this into a fragile static SQL parser of migration files.

## Lock + connection co-location

- The advisory lock must be taken on the **same DBAL connection** the migrator uses, **after** `ensureConnectedToPrimary()`.
- With `keep_replica: false`, once switched to primary the connection stays on primary for the session — lock and migrations are co-located. Re-verify if that setting changes.

See the boot lane guide: [`../guide/boot-lane.md`](../guide/boot-lane.md).
