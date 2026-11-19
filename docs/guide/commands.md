# Console commands

All commands are registered by the bundle. DDL/refresh operations always route through `PrimaryConnectionGuard` (no `--primary` flag needed).

| Command | Purpose |
|---|---|
| `matview:generate <name>` | Scaffold the `.sql` file and an attributed provider class |
| `matview:list` | List declared definitions and their real state |
| `matview:validate` | Check SQL, indexes, hash, existence, `CONCURRENTLY` preconditions, unmanaged dependents |
| `matview:diff` | Show the creations, changes, deletions and refreshes that would happen |
| `matview:drop --if-pending` | Free the way before pending migrations (drop-all of managed views); does **not** handle declarative drift |
| `matview:sync` | Create/rebuild missing or drifted managed views |
| `matview:prune` | Explicitly drop managed-but-undeclared views (destructive) |
| `matview:doctrine-lane` | Hold a lock and run `drop → migrate → sync` in one process |
| `matview:refresh <name>` | Refresh one view |
| `matview:refresh --all` | Refresh in catalog-derived dependency order |
| `matview:refresh --all --only-unpopulated` | Refresh only the unpopulated views, in dependency order |
| `matview:dump-sql` | Print `up`/`down` SQL for the migration-owned mode |

## Important options

- `--concurrently` — force `REFRESH MATERIALIZED VIEW CONCURRENTLY`.
- `--no-data` — create without data.
- `--if-populated` — skip a concurrent refresh on an unpopulated view.
- `--only-unpopulated` — `matview:refresh` only: refresh the declared views that exist and are unpopulated (`relispopulated = false`) and skip the others, reporting each skipped view and exiting `0` when nothing needs refreshing. With a view name, that view is refreshed only if it is unpopulated. Meant for a run right after a deployment, for views declared `WITH NO DATA` and populated manually. It is refused together with `--concurrently` (a concurrent refresh is impossible on an unpopulated view) and with `--if-populated` (the two select opposite views). See the core's [Refresh runtime & locking](../../../materialized-view/docs/guide/refresh-and-locking.md).
- `--refresh-initial` — allow `sync` to do a synchronous first refresh.
- `--enqueue-refresh` — ask the bundle to schedule initial refreshes asynchronously.
- `--timeout=30s` — apply `lock_timeout` and `statement_timeout`.
- `--strategy=drop_create|side_by_side` — force the rebuild strategy.
- `--all-managed` — drop all managed views (explicit drop commands only).
- `--prune` — allow `sync` to delete managed-but-undeclared views; **never** at boot.
- `--dry-run` — print actions without executing.

## Command responsibilities (don't blur them)

- **`drop --if-pending`** has one job: free the way for table DDL before migrations. It drops managed views when migrations are pending (or on `--all-managed`). It does **not** handle hash drift, obsolete views, or views missing from the registry — those belong to `sync`/`prune`. See [Boot lane](boot-lane.md).
- **`sync`** reconciles declared definitions with reality (create, rebuild on drift, re-index, re-grant, re-hash, apply population). It never deletes orphans without `--prune`.
- **`prune`** is the only destructive-by-default-free deletion path, and refuses if an orphan has an unmanaged dependent.

## Generation & versioning

`matview:generate` honours `generation.file_naming` (`stable` | `versioned`). With `versioned`, it provides a bump workflow (`_v002.sql` + definition update). See the core [Defining views](../../materialized-view/docs/guide/defining-views.md#file-naming--versioning).
