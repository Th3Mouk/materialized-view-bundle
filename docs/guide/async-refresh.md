# Async refresh

`PopulationPolicy::Async` lets `sync` create a view without a blocking initial refresh, dispatching the refresh to a worker instead. When you run the same views across multiple databases/connections this has **two** requirements that are easy to miss.

## 1. The job must carry the target database

`sync` runs **per database** (with that database's `DATABASE_URL`). The dispatched message must therefore carry the **target database identity** — it must not rely on the current HTTP request, the hostname, or an ambient connection.

```php
final readonly class AsyncRefreshRequest
{
    public function __construct(
        public string $connectionName,
        public string $databaseName,   // logical target; resolved by the bundle
        public string $viewName,
        public RefreshOptions $options,
    ) {
    }
}
```

The worker resolves the connection via a `RefreshTargetResolver` **before** calling `refresh()`. Transporting a full `DATABASE_URL` in the message is a last resort (it propagates secrets and freezes network config) and is not the recommended default.

## 2. The transport must be shared (not per-connection)

Because `sync` runs per database, a **per-connection Doctrine transport stored in each database** would write the message into N distinct `messenger_messages` tables; a standard worker cannot drain all of them. `Async` therefore **presupposes a shared transport** (AMQP, Redis, SQS, or a single technical database).

```yaml
th3mouk_materialized_view:
    async:
        require_shared_transport: true
        transport_scope: shared      # shared | per_connection_doctrine
```

With `require_shared_transport: true` (default), the bundle **refuses** `default_population_policy: async` when only a per-connection Doctrine transport is configured. In that topology, prefer `Manual` plus a scheduled refresh that iterates your databases.

> Check your application's actual Messenger configuration before enabling `Async` — this is the assumption most likely to break in practice.

## Scheduling periodic refreshes

For recurring refreshes (independent of population), pair `matview:refresh --all` with **Symfony Scheduler** or cron, iterating your databases. The bundle exposes the command and the message; it deliberately does not become a job orchestrator (see non-goals).

## Runtime guarantees

Whatever dispatches it, the actual refresh goes through the core runtime: primary connection, timeouts, per-view advisory lock, `CONCURRENTLY` precondition validation, `ANALYZE`. See the core [Refresh runtime & locking](../../materialized-view/docs/guide/refresh-and-locking.md).
