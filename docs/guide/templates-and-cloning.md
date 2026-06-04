# Templates & cloning

Some deployments create databases by cloning a PostgreSQL template (`CREATE DATABASE … TEMPLATE …`) — a maintained template database is provisioned once and fresh databases are cloned from it. Materialized views interact with this in subtle ways.

## What a clone copies

A `TEMPLATE` clone is a physical copy. It **copies**:

- materialized views and **their data**,
- their indexes,
- their comments (so the **management hash** is inherited),
- **object-level** GRANTs on those views.

It does **not** copy **database-level** GRANTs — those must be applied by provisioning, per database.

Reference: [PostgreSQL `CREATE DATABASE`](https://www.postgresql.org/docs/17/sql-createdatabase.html). See also the core [PostgreSQL references](../../materialized-view/docs/internals/postgresql-references.md).

## The trap

A freshly cloned database can start with materialized views that **look up-to-date** by hash/comment, but whose **data is a snapshot of the template**. Don't assume cloned matview data is fresh.

## `template.policy`

```yaml
th3mouk_materialized_view:
    template:
        policy: empty   # empty | cloned_stale | maintained_template
```

| Policy | Behaviour |
|---|---|
| `empty` | The template must contain **no managed matviews**; they are born on the cloned database's first `sync`. |
| `cloned_stale` | Cloned matviews are accepted structurally but flagged **to refresh**. |
| `maintained_template` | An external process keeps the template synced and refreshed; the library only checks hash/comment. |

When you clone databases from a template, choose deliberately between `empty` and `maintained_template`. Leaving populated matviews in the template **without** a documented refresh process creates silently stale state.

## Operational constraint on `maintained_template`

PostgreSQL **refuses to clone a template if another session is connected to it** when `CREATE DATABASE` starts. So `maintained_template` requires a **provisioning lock or strict connection discipline**: the maintainer must disconnect from the template before any clone. Without that coordination, **`empty` is the most robust policy** — and the recommended default.
