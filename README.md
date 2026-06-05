# th3mouk/materialized-view-bundle

> Symfony integration for [`th3mouk/materialized-view`](../materialized-view) — manage PostgreSQL **materialized views** declaratively, with first-class support for booting the same views across many databases/connections.

The bundle adds, on top of the framework-agnostic core: **autoconfiguration** of your view definitions, **console commands** (`matview:*`), the **locked deploy lane** (`drop --if-pending → migrate → sync` in one process), **async refresh** dispatching, and **read-only ORM guards**.

## Why a separate bundle

The core is intentionally framework-free (DBAL only). Everything Symfony-specific — DI, attributes, commands, Messenger, the Doctrine Migrations lane — lives here so the core can be reused and tested in isolation, and so neither package strands the other on a framework upgrade.

## Installation

```bash
composer require th3mouk/materialized-view-bundle
```

Requirements: **PHP ≥ 8.4**, **Symfony ≥ 8.0**, **Doctrine DBAL ≥ 4.4**, **DoctrineBundle**, **PostgreSQL** (12+). For the deploy lane, **DoctrineMigrationsBundle**; for async refresh, **Symfony Messenger** with a shared transport.

## 60-second setup (Symfony)

1. Register the bundle (Flex usually does this) in `config/bundles.php`:

```php
return [
    // ...
    Th3Mouk\MaterializedViewBundle\Th3MoukMaterializedViewBundle::class => ['all' => true],
];
```

2. Declare a view — a `.sql` file plus an attributed PHP class (auto-discovered, no interface):

```php
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedViewBundle\Attribute\AsMaterializedViewProvider;

#[AsMaterializedViewProvider]
final class SalesByCategoryView
{
    public function definitions(): iterable
    {
        yield MaterializedViewDefinition::create('public.sales_by_category')
            ->fromSql(/* db/matviews/sales_by_category.sql */);
    }
}
```

3. Wire the deploy lane (per database) — see [boot lane](docs/guide/boot-lane.md):

```bash
php -d memory_limit=512M bin/console matview:doctrine-lane --no-interaction
```

## Documentation

| Tier | Audience | Start here |
|---|---|---|
| **Getting started** | Users — wire it into Symfony fast | [`docs/getting-started.md`](docs/getting-started.md) |
| **Guide** | Users — commands, config, boot lane, async, templates | [`docs/guide/`](docs/guide/) |
| **Internals** | Maintainers — design & Symfony/Doctrine references | [`docs/internals/`](docs/internals/) |

Core concepts (definitions, rebuilds, refresh, locking, hashing, ORM) are documented in the [native library docs](../materialized-view/docs/). This bundle's docs cover the Symfony surface and link back.

## Compatibility

| This bundle | PHP | Symfony | DoctrineBundle | Migrations (optional) | Core |
|---|---|---|---|---|---|
| `^1.0` | ≥ 8.4 | ^8.0 | ^2.13 | ^4.0 | `th3mouk/materialized-view:^1.0` |

## License

[Apache-2.0](LICENSE) — Copyright © 2026 Jérémy Marodon (th3mouk). See [`NOTICE`](NOTICE).

If you use or redistribute this package, keep the [`NOTICE`](NOTICE) attribution —
crediting **Jérémy Marodon (th3mouk)** and naming this library in your product's
documentation or credits. Please [contribute](CONTRIBUTING.md) upstream rather than
maintaining a public fork.
