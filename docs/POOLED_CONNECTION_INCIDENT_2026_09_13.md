# Pooled connection incident investigation

Status: original production error not yet recovered. No production fix or pooling rollout is claimed. `config/database.php` remains unchanged on `DB_HOST`.

**Owner update, 2026-09-14:** Railway incident-window logs are confirmed unrecoverable; the earlier export request below is superseded. Do not repeat the completed job review. Remaining evidence paths are [Horizon Redis failure retrieval](HORIZON_INCIDENT_RETRIEVAL_2026_09_13.md) and the owner's separate confirmation of pooling enablement/history and the actual worker PHP/PDO runtime. Hold further pooling conclusions until that confirmation. [Durable log capture](DURABLE_LOG_CAPTURE_PLAN.md) is now the top remaining Tier 1 priority, with planning only authorized so far.

The owner's direct-versus-pooled production observations are accepted. The incident window is **2026-09-13 13:45:06–13:45:19 UTC**, equivalent to **16:45:06–16:45:19 Africa/Nairobi**.

## Findings in this checkout

| Path | Transaction and exception behavior |
| --- | --- |
| `DetectDocumentDeadlinesJob` | Delete and inserts run in one `DB::transaction`; no catch inside that closure. |
| `DetectDocumentRisksJob` | Same transaction structure as deadlines. |
| `ExtractDocumentEntitiesJob` | Same transaction structure as deadlines. |
| `ClassifyDocumentTypeJob` | `updateOrCreate`, no explicit job transaction. Laravel can use a savepoint for create-if-missing when already inside a transaction. |
| `GenerateInsightsJob` | Result replacement, credit accounting, Ready transition and its observer writes share one transaction. The unchanged-document Ready path also has a transaction. No internal catch swallows its persistence errors. |

The installed Laravel `ManagesTransactions::transaction()` catches `Throwable` from these closures and rolls back before rethrowing. No manual `beginTransaction`/`commit` pair was found in application code. Inspection of the recorder, AI client, credit service and document observer did not identify a transaction leak in these job paths.

All five jobs catch errors from `AnthropicClient`, which also performs database reads/writes. Their catch blocks attempt to record failure before calling `$this->fail($e)`. If that recording also fails, it can mask the caught error. This is a real diagnostic weakness, but there is no evidence that it initiated this incident; it is not presented as the root cause. Persistence failures outside those catches propagate to the worker. `GenerateInsightsJob::failed()` also writes to the database before logging.

`DispatchesIntelligenceChain` dispatches classification, entities, risks and deadlines as a Redis-backed batch with `allowFailures()`. Its final callback dispatches summary. Insights is a separate member of the upload chain after extraction. Horizon config permits five extraction workers. Batch dispatch and batch-counter updates use short database transactions; the queued job bodies are not collectively enclosed in one transaction. Being on the same queue does not establish that these jobs used one worker or one database session.

## Concrete driver compatibility lead — not incident confirmation

`Dockerfile:1` pins `dunglas/frankenphp:php8.3.33-trixie`. Laravel's installed connector defaults `PDO::ATTR_EMULATE_PREPARES` to false; the pgsql configuration has no override.

PgBouncer's [PDO compatibility guidance](https://www.pgbouncer.org/faq.html#how-to-use-prepared-statements-with-transaction-pooling) requires PHP 8.4+ with libpq 17 for native prepared-statement support. The deployment runtime must be checked rather than assuming that the current Dockerfile was used for the incident release.

The [PHP 8.3 PDO source](https://github.com/php/php-src/blob/PHP-8.3/ext/pdo_pgsql/pgsql_statement.c) sends SQL `DEALLOCATE <statement-name>` in `pgsql_stmt_dtor` and discards the result without raising its error. PgBouncer [issue #991](https://github.com/pgbouncer/pgbouncer/issues/991) records the corresponding server error: a PDO statement name does not exist after PgBouncer remaps it. If that occurs inside a transaction, a later query can surface `25P02` while the original error was never thrown to Laravel. This explains how an application log could contain only the follow-on error even with correct closure rollback.

This is a candidate **26000 / invalid SQL statement name** trigger, not a recovered production exception. Multiple workers could independently encounter it. PgBouncer's [transaction-pooling semantics](https://www.pgbouncer.org/config#pool_mode) release a server after the transaction finishes; the observed errors alone do not prove that an open aborted transaction crossed from one client to another.

If confirmed, the targeted correction is PDO prepared-statement compatibility (a compatible runtime or disabling named prepares), followed by a reproduction on the incident runtime and an isolated pool. Adding blanket rollbacks around otherwise correct transaction closures would not prevent a driver destructor from poisoning each new transaction.

## Verification completed

Added `tests/Feature/DocumentJobTransactionRecoveryTest.php`. It uses real PostgreSQL and committed fixtures, without `RefreshDatabase`'s enclosing test transaction. For each of the four transactional jobs it:

1. Executes a successful result insert, then injects real SQL division by zero inside the job's transaction.
2. Asserts the original `22012` escapes, the insert is rolled back, Laravel transaction depth is zero and PDO reports no open transaction.
3. Runs all five jobs immediately for the same document on the exact same PDO object, without disconnecting or resetting it.
4. Verifies all five persisted results, completed stages and final Ready status, checking clean transaction state after every job.

Run against a disposable PostgreSQL test database:

```sh
php artisan test --compact --filter='DocumentJobTransactionRecoveryTest|GenerateInsightsJobTest'
```

Result on local PHP 8.5.4 / libpq 18.4 / PostgreSQL: **6 tests passed, 147 assertions**. Pint passed for the new test. These tests pass with application code unchanged: they establish the ordinary SQL-error rollback baseline, not a fix for the production incident. PgBouncer and the PHP 8.3 production runtime were not available locally. Real queue scheduling, batch bookkeeping under the pool, and driver-destructor failures remain unverified. The tests truncate/migrate their configured database; use only a disposable test database.

## Evidence still needed

No `25P02` or matching incident-window entries were found in `storage/logs/laravel.log`. The local September 13 entries inspected are testing logs. No original exception was found in the repository's incident/audit documents. This session has not accessed production PostgreSQL, Redis, Railway or Sentry.

1. Export Railway logs from **all web and Horizon replicas**, **2026-09-13 13:43:00–13:47:00 UTC** (16:43–16:47 Nairobi). Include full stacks, replica/process identifiers and deployment identity; retain the unfiltered export. This checkout's application timezone is UTC. Search the export with:

   ```sh
   rg -n -B 100 -A 60 'SQLSTATE|25P02|26000|42P05|DEALLOCATE|pdo_stmt_|DetectDocumentDeadlinesJob|DetectDocumentRisksJob|ClassifyDocumentTypeJob|ExtractDocumentEntitiesJob|GenerateInsightsJob' railway-incident.log
   ```

2. Supply the affected document ID. Check **Horizon → Failed Jobs** for the window. `config/horizon.php` retains failed/recent-failed records for **10080 minutes (7 days)**. `RedisJobRepository::failed()` stores full exception strings independently of PostgreSQL's `failed_jobs` table. Deleting only the SQL rows does not delete those Redis records; a Horizon forget/clear operation or expiry may have removed them.
3. Check Sentry events over the same window, including exception chains and breadcrumbs. If the driver's silent cleanup is responsible, seek the first `DEALLOCATE`/`26000` in PostgreSQL logs through available Neon support/log access; it may never appear in Railway application logs.
4. On the actual Railway worker, capture runtime versions without printing environment secrets:

   ```sh
   php -v
   php --ri pdo_pgsql
   ```

Correlate the earliest non-25P02 database error with the same worker/session or reproduce it on the incident image before selecting a root-cause fix. Keep the direct host in production. The owner must confirm any later production pooling attempt after the targeted fix passes the isolated pooling test.
