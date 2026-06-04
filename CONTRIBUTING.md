# Contributing

Thanks for helping improve `th3mouk/materialized-view-bundle`.

## Ground rules

- The bundle is a **thin Symfony adapter**. Domain logic belongs in the core (`th3mouk/materialized-view`); the bundle wires it, exposes commands, and integrates Messenger/Migrations.
- **Optional integrations stay optional.** Messenger, Scheduler, DoctrineMigrationsBundle and ORM must degrade gracefully when absent.
- Every Symfony/Doctrine-Migrations contract the bundle relies on **must be referenced** in `docs/internals/` with an official link.

## Quality gate

```bash
composer cs:fix
composer rector
composer stan
composer test:unit
composer test:integration   # boots a test kernel; lane/Messenger scenarios use a PostgreSQL service
```

## Test taxonomy

| Suite | Location | Purpose |
|---|---|---|
| Unit | `tests/Unit` | bundle DI, autoconfiguration, command wiring, config tree |
| Integration | `tests/Integration` | kernel boot, `matview:*` commands, the lane + advisory lock, async dispatch, ORM guards |

The authoritative scenario matrix is in [`docs/internals/validation-plan.md`](docs/internals/validation-plan.md).

## Commits & releases

- [Conventional Commits](https://www.conventionalcommits.org/), [SemVer](https://semver.org/), update `CHANGELOG.md`.

## License & attribution

Licensed under [Apache-2.0](LICENSE). By submitting a contribution you agree it is
provided under those same terms (Apache-2.0 §5) — no separate CLA is required.

Please **contribute upstream rather than maintaining a public fork**: open an issue
or a pull request here. The [`NOTICE`](NOTICE) attribution to Jérémy Marodon (th3mouk)
and the copyright/trademark notices must be preserved in any redistribution or
derivative work (Apache-2.0 §4 and §6).
