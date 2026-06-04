<!-- Thanks for contributing to th3mouk/materialized-view-bundle! -->

## What & why

<!-- What does this change and why? Link any issue. -->

## Checklist

- [ ] `composer cs:fix && composer rector && composer stan` pass
- [ ] `composer test` passes (kernel + integration against PostgreSQL)
- [ ] Domain logic stays in the core (`th3mouk/materialized-view`); the bundle only wires it
- [ ] New Symfony / Doctrine Migrations behaviour is referenced in `docs/internals/*-references.md`
- [ ] `CHANGELOG.md` updated under `[Unreleased]`
