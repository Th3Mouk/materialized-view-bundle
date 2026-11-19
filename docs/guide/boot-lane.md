# Boot lane

This is the heart of the design for any app where migrations run **at boot, across one or many databases/connections**.

## The problem

A materialized view that depends on a column blocks DDL on its source table:

```text
cannot drop column ... because materialized view ... depends on it
```

Asking developers to hand-write `DROP MATERIALIZED VIEW` in every affected migration is a cognitive burden and an easy thing to forget — especially with frequent schema changes.

## The lane

Conceptually, per database:

```text
matview:drop --if-pending   ->   doctrine:migrations:migrate   ->   matview:sync
```

Dropping managed views *before* the DDL phase and recreating them *after* means a developer never has to guess which migration must drop which view.

**In production, do not run these as three separate processes** — a `pg_advisory_lock` is bound to its connection and would be released between console processes, leaving a rolling deploy unprotected. Use the single locked command instead:

```bash
php -d memory_limit=512M bin/console matview:doctrine-lane --no-interaction
```

If you run the same managed views across multiple databases/connections, invoke this **once per database**, with that database's `DATABASE_URL` — loop over your connections at boot. Advisory locks are per-database, so each run is serialised independently.

### Correctness constraints (implemented by `doctrine-lane`)

- Call `ensureConnectedToPrimary()` **before** taking the lock.
- Take a **session-level** lock, not transaction-level: `pg_advisory_lock(lane.lock_namespace)`. A single constant key suffices because advisory locks are **per-database** (per-connection).
- Take the lock on the **same DBAL connection** used by Doctrine Migrations.
- Run migrations in the same process — preferably by invoking the existing `doctrine:migrations:migrate` command (preserves config, console output, events). If using `DependencyFactory::getMigrator()` directly, build an explicit `MigratorConfiguration`.
- Release the lock in a `finally`.
- Keep the same `memory_limit` as today's `migrate` (≥ 512M) — the lane does more.

`DependencyFactory` has no autowiring alias; inject it with `#[Autowire(service: 'doctrine.migrations.dependency_factory')]`. See [Doctrine Migrations references](../internals/doctrine-migrations-references.md).

## `drop --if-pending` semantics (conservative by design)

Doctrine Migrations exposes **no reliable API** for the tables a pending migration will touch — migrations can contain raw, dynamic, or runtime-dependent SQL. So the MVP does **not** promise a targeted drop by static analysis.

- Determine pending migrations via Doctrine Migrations.
- If none are pending → drop nothing.
- If at least one is pending → drop the managed views in reverse dependency order, migrate, then `sync` recreates declared views.
- Targeted drop is available as the opt-in `reactive_retry` strategy (`catch + drop closure + retry`), described below; an app-supplied `MigrationImpactResolver` (`custom_impact`) remains a future extension.

### `reactive_retry` and non-transactional migrations

`reactive_retry` migrates first and, on a dependency-conflict SQLSTATE (`2BP01`/`0A000`), drops only the blocking managed closure and retries. A **non-transactional** migration cannot be retried after failing half-way, so a pending one normally falls back to dropping every managed view up front.

That fallback is kept **only when a pending non-transactional migration can raise a dependency conflict**. When every statement of every pending non-transactional migration is one of the following, the lane skips the fallback, logs why (`info`), and takes the normal reactive path:

- `CREATE [UNIQUE] INDEX [CONCURRENTLY] [IF NOT EXISTS] …`
- `DROP INDEX [CONCURRENTLY] [IF EXISTS] …`
- `REINDEX …`
- `ALTER TABLE [IF EXISTS] [ONLY] <table> VALIDATE CONSTRAINT <name>` (that action alone)
- `ANALYZE …` and `VACUUM …`

Anything else — another `ALTER TABLE` action, several statements in one string, text that cannot be read with certainty — keeps the fallback and logs a warning naming the migration versions. The statements are the ones Doctrine Migrations plans in a dry run of the pending non-transactional migrations (see [Doctrine Migrations references](../internals/doctrine-migrations-references.md)); if they cannot be obtained, the fallback is kept. Views declared `WITH NO DATA` and populated manually therefore stay populated across index-only migrations.

Before any drop, the lane runs `ExternalDependencyGuard`: if a managed view has an **unmanaged** dependent, the drop **refuses** with a clear message. `CASCADE` is never implicit.

## Degradation window (be explicit)

The lane serialises concurrent boots on a database, but it does **not** serialise traffic already served by an older version during a rolling deploy. While managed views are dropped, analytics reads may fail or fall back until `sync` and the initial refresh complete.

Decisions:

- User-facing critical views → `PopulationPolicy::Synchronous` or an explicit fallback.
- Non-critical views → `Async` + `ReadinessChecker`.
- The MVP assumes drop-all when migrations are pending, so critical views **must** declare an explicit read policy.
- If the migration **fails** after the drop, the lane must **not** run `sync` against an unmigrated schema; it logs the degraded state and leaves an explicit recovery (`matview:sync --after-failed-migration`, or re-run the lane after fixing).

## Wiring it into your boot script

If your boot script today runs, per database:

```text
php -d memory_limit=512M bin/console doctrine:migrations:migrate --no-interaction
```

the change is to call `matview:doctrine-lane` instead. Validate with `--dry-run` first across all your databases before switching production boot.
