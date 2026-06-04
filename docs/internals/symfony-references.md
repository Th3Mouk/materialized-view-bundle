# Symfony references (maintainers)

The Symfony contracts the bundle relies on, with official documentation. Re-verify on every Symfony major/minor bump.

## Bundle & DI

- **Bundle system / `AbstractBundle::loadExtension()`** — <https://symfony.com/doc/current/bundles/extension.html>
  - `Th3MoukMaterializedViewBundle` extends `AbstractBundle`, imports `config/services.php`, and registers attribute autoconfiguration in `loadExtension()`.
- **Configuration / `Configuration` tree** — <https://symfony.com/doc/current/bundles/configuration.html>
- **`registerAttributeForAutoconfiguration()`** — <https://symfony.com/doc/current/service_container/tags.html#creating-custom-tags>
  - Used to collect `#[AsMaterializedViewProvider]` classes without an interface (registered in `loadExtension()`).
- **Service subscribers & locators (ServiceLocator)** — <https://symfony.com/doc/current/service_container/service_subscribers_locators.html>
  - The registry is **not** a tagged iterator. `MaterializedViewProviderPass` collects the `th3mouk.materialized_view_provider`-tagged services into a lazy `ServiceLocator` via `ServiceLocatorTagPass::register()`, then injects that locator plus an `id => method` map into `MaterializedViewRegistryBuilder`. Providers are only instantiated when the registry is built.
- **Bundle extension alias** — the bundle sets `protected string $extensionAlias = Configuration::ALIAS;` (`th3mouk_materialized_view`). Without it, `AbstractBundle` would derive the alias from the class name via `Container::underscore`, which inserts an underscore inside `Th3Mouk` and would not match the documented config key.

## Console

- **Console commands** — <https://symfony.com/doc/current/console.html>
  - The `matview:*` commands extend `Symfony\Component\Console\Command\Command` (`#[AsCommand]`).
  - The lane may invoke an existing command in-process via the `Application`: `$application->find('doctrine:migrations:migrate')->run(new ArrayInput([...]), $output)`.

## Dependency injection — Autowire attribute & connection alias

- **`#[Autowire]`** — <https://symfony.com/doc/current/service_container/autowiring.html#autowiring-other-methods-e-g-public-setters>
  - Used to inject the un-aliased Doctrine Migrations `DependencyFactory`: `#[Autowire(service: 'doctrine.migrations.dependency_factory')]`.
  - Used to inject reserved namespaces as parameters: `#[Autowire(param: 'th3mouk_materialized_view.lane.lock_namespace')]`. Note: services declared with explicit `->args([...])` in `config/services.php` (e.g. the `matview:*` commands) take their arguments from that wiring; the `#[Autowire(param:)]` attribute only applies when a service is autowired.
- **Connection alias** — `loadExtension()` resolves the configured `connection` to `th3mouk_materialized_view.connection` via `$builder->setAlias('th3mouk_materialized_view.connection', new Alias("doctrine.dbal.{$name}_connection", false))`. Every core service that needs DBAL is wired to this alias.

## Messenger (optional — async refresh)

- **Messenger** — <https://symfony.com/doc/current/messenger.html>
  - `AsyncRefreshRequest` is a message; `AsyncRefreshRequestHandler` resolves the target DB and calls `refresh()`.
- **Registering handlers / `#[AsMessageHandler]`** — <https://symfony.com/doc/current/messenger.html#registering-handlers>
  - `AsyncRefreshRequestHandler` carries `#[AsMessageHandler]`; the handler is auto-registered through `autoconfigure()` (no manual `messenger.message_handler` tag). The bundle injects `messenger.default_bus` (the `MessageBusInterface`) into `MessengerInitialRefreshDispatcher`.
  - **Transport must be shared** (AMQP/Redis/SQS), not a per-connection Doctrine transport. See [Async refresh](../guide/async-refresh.md).
  - **Doctrine transport caveat** — <https://symfony.com/doc/current/messenger.html#doctrine-transport> — stored per connection/DB; unsuitable as a per-connection transport for fleet-wide refreshes.

## Doctrine ORM event listeners (optional)

- **Doctrine events / `doctrine.event_listener` tag** — <https://symfony.com/doc/current/doctrine/events.html>
  - The read-only write guard is tagged `->tag('doctrine.event_listener', ['event' => 'onFlush'])` and the post-load listener `['event' => 'postLoad']`. Both are registered only when ORM is present (see optional-dependency gating below).
  - The `onFlush` write guard registration is gated by `doctrine.orm_write_guard`: when `false`, `loadExtension()` removes the `th3mouk_materialized_view.orm.write_guard` definition (the `postLoad` listener stays).

## Optional-dependency gating

`config/services.php` registers optional integrations only when their classes/interfaces are present, falling back to inert implementations otherwise:

- **Migrations** — `class_exists(Doctrine\Migrations\DependencyFactory::class)`: real `DoctrineMigrationsPendingInspector` + the `matview:doctrine-lane` command, else `NoMigrationsPendingInspector` (no lane command).
- **Messenger** — `interface_exists(Symfony\Component\Messenger\MessageBusInterface::class)`: real `MessengerInitialRefreshDispatcher` + `AsyncRefreshRequestHandler`, else `UnsupportedInitialRefreshDispatcher` (throws on dispatch).
- **ORM** — `interface_exists(Doctrine\Persistence\ConnectionRegistry::class) && class_exists(MaterializedViewMetadataReader::class) && class_exists(Doctrine\ORM\Event\OnFlushEventArgs::class)`: the metadata reader, ORM readiness guard, write guard and post-load listener; otherwise none are registered.

## Scheduler (optional — periodic refresh)

- **Scheduler** — <https://symfony.com/doc/current/scheduler.html>
  - For recurring `matview:refresh --all` across your databases. The bundle provides the command/message, not an orchestrator.

## DoctrineBundle

- **DoctrineBundle** — <https://symfony.com/doc/current/bundles/DoctrineBundle/index.html>
  - Source of the DBAL connection(s) the bundle builds services from, and of `schema_filter` hygiene so Doctrine ignores your managed analytics tables (e.g. a `~^(?!reporting_)~` filter).

> Doctrine Migrations-specific contracts (the programmatic lane) are in [Doctrine Migrations references](doctrine-migrations-references.md). Core DBAL/ORM contracts are in the native library's [Doctrine references](../../materialized-view/docs/internals/doctrine-references.md).
