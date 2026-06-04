# Documentation — th3mouk/materialized-view-bundle

Symfony integration for [`th3mouk/materialized-view`](../../materialized-view). Organised in **three tiers**.

> Core concepts — defining views, rebuild strategies, refresh/locking, hashing, ORM mapping — live in the [native library docs](../../materialized-view/docs/). This bundle's docs cover the **Symfony surface** and link back where relevant.

## 1. Getting started (users)

- [Getting started](getting-started.md) — register the bundle, declare a view, run the lane.

## 2. Guide (users — advanced concepts)

- [Console commands](guide/commands.md) — `matview:generate|list|validate|diff|drop|sync|prune|refresh|dump-sql|doctrine-lane`
- [Configuration reference](guide/configuration-reference.md) — every option, annotated
- [Boot lane](guide/boot-lane.md) — the locked `drop → migrate → sync` deploy lane
- [Async refresh](guide/async-refresh.md) — Messenger dispatch, refresh target resolution, transport topology
- [Templates & cloning](guide/templates-and-cloning.md) — `CREATE DATABASE … TEMPLATE …` and `template.policy`

## 3. Internals (maintainers)

- [Architecture](internals/architecture.md) — bundle layout, service wiring, command map
- [Design rationale](internals/design-rationale.md) — Symfony-specific decisions (links to the core rationale)
- [Symfony references](internals/symfony-references.md) — bundle/DI/Messenger/Scheduler contracts, with official links
- [Doctrine Migrations references](internals/doctrine-migrations-references.md) — the programmatic lane, with official links
- [Validation plan](internals/validation-plan.md) — bundle & multi-database boot scenarios
