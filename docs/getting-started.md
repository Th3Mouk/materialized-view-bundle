# Getting started (Symfony)

This is the fast path for a Symfony application. It assumes PostgreSQL and DoctrineBundle are already configured.

## Installation

```bash
composer require th3mouk/materialized-view-bundle
```

## 1. Register the bundle

Symfony Flex registers it automatically. Otherwise, in `config/bundles.php`:

```php
return [
    // ...
    Th3Mouk\MaterializedViewBundle\Th3MoukMaterializedViewBundle::class => ['all' => true],
];
```

## 2. Minimal configuration

`config/packages/th3mouk_materialized_view.yaml`

```yaml
th3mouk_materialized_view:
    connection: default
    default_schema: public
    sql_paths:
        - '%kernel.project_dir%/db/matviews'
```

The full annotated reference is in [Configuration reference](guide/configuration-reference.md).

## 3. Declare a view

Given a base table `orders (id bigint, category text, amount numeric, created_at timestamptz)`, put the query in `db/matviews/sales_by_category.sql`:

```sql
SELECT category, count(*) AS order_count, sum(amount) AS total_amount
FROM orders
GROUP BY category
```

Then declare a provider — **auto-discovered** via the attribute, no interface to implement:

```php
namespace App\Analytics\View;

use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewIndex;
use Th3Mouk\MaterializedView\Core\Definition\PopulationPolicy;
use Th3Mouk\MaterializedView\Core\Definition\SqlFileSource;
use Th3Mouk\MaterializedViewBundle\Attribute\AsMaterializedViewProvider;

#[AsMaterializedViewProvider]
final class SalesByCategoryView
{
    public function definitions(): iterable
    {
        yield MaterializedViewDefinition::create('public.sales_by_category')
            ->fromSql(SqlFileSource::fromProjectPath('db/matviews/sales_by_category.sql'))
            ->withPopulationPolicy(PopulationPolicy::Synchronous) // small view, read immediately
            ->withIndex(MaterializedViewIndex::unique(
                name: 'ux_sales_by_category_identity',
                columns: ['category'],
            ));
    }
}
```

> Prefer a different discovery method name? `#[AsMaterializedViewProvider(method: 'provide')]`.

## 4. Try it locally

```bash
php bin/console matview:list        # declared vs real state
php bin/console matview:validate    # SQL, indexes, hash, CONCURRENTLY preconditions
php bin/console matview:sync        # create / rebuild on drift
php bin/console matview:refresh public.sales_by_category
```

All commands: [Console commands](guide/commands.md).

## 5. Wire the deploy lane

For deployments — especially when migrations run at boot across many databases/connections — replace the bare `doctrine:migrations:migrate` with the **locked lane**, which runs `drop --if-pending → migrate → sync` in a single, advisory-locked process:

```bash
php -d memory_limit=512M bin/console matview:doctrine-lane --no-interaction
```

This is the piece that makes table DDL and materialized views stop fighting at boot. Read [Boot lane](guide/boot-lane.md) before enabling it in production.

## 6. Read a view from the ORM (optional)

Map a read-only entity onto the view — see the core's [Doctrine ORM integration](../../materialized-view/docs/guide/doctrine-orm-integration.md). Pair it with a population policy so reads never hit an unpopulated view.
