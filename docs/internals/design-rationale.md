# Design rationale (maintainers) — bundle

The product-wide rationale (the twelve decisions, the competitive landscape, the risk register, the scope of the conflict-avoidance guarantee) lives in the core:
[`../../materialized-view/docs/internals/design-rationale.md`](../../materialized-view/docs/internals/design-rationale.md).

This page records only the **Symfony-specific** decisions.

## Why a separate bundle (not one package)

- The core must stay framework-free so it can be tested in isolation and reused, and so a Symfony major never strands the DBAL logic (and vice versa). Splitting the Symfony surface into its own package is the cleanest seam.
- Trade-off: two packages to version. We keep their versions in lockstep and the bundle depends on a caret range of the core.

## Why attribute discovery (not a filesystem scan)

`#[AsMaterializedViewProvider]` + `registerAttributeForAutoconfiguration()` is idiomatic Symfony and avoids the brittle "scan a directory + reverse-resolve PSR-4 from composer.json" approach seen in other libraries. No application interface is required — only an attribute.

## Why a single locked lane command (not three console steps)

A `pg_advisory_lock` is bound to its connection and released when the process ends. Three separate console processes cannot share one lock, so a rolling deploy would be unprotected. `matview:doctrine-lane` holds one session-level lock across `drop → migrate → sync` in a single process. See [Boot lane](../guide/boot-lane.md).

## Why the bundle prefers invoking `MigrateCommand` in-process

Re-implementing the migrate glue (config assembly, console output, events) risks subtly diverging from `doctrine:migrations:migrate`. Invoking the existing command object is lower risk; the direct `Migrator` path remains available with an explicit `MigratorConfiguration`. See [Doctrine Migrations references](doctrine-migrations-references.md).

## Why `Async` is gated on a shared transport

`sync` runs per database; a per-connection Doctrine transport would fragment the queue across N databases. The bundle refuses `Async` without a shared transport rather than silently producing undrainable messages. See [Async refresh](../guide/async-refresh.md).
