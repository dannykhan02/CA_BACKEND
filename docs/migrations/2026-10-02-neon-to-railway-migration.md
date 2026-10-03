# Neon → Railway Postgres migration (2026-10-02)

**Status:** production cut over and verified. Neon is still running, untouched, as a rollback.
**No secrets in this file.** Credentials live in Railway variables and in `~/migration-backups` and `~/neon-vars-*.backup` on the developer's WSL machine (mode 600, outside the repo).

## Before / after

| | Before | After |
|---|---|---|
| Database | Neon project `ca-document-intelligence` (`fragrant-cherry-99998400`), db `neondb`, Postgres 18.6, pgvector 0.8.1 | Railway service `Postgres`, db `railway`, Postgres 18.6, pgvector 0.8.7 |
| Image / storage | Neon managed | `pgvector/pgvector:pg18`, volume `postgres-volume` mounted at `/var/lib/postgresql` |
| App connection | `DB_HOST` = Neon endpoint (`ep-shy-paper-axsw5ecr…`), `DB_SSLMODE=require` | `DB_HOST` = `${{Postgres.RAILWAY_PRIVATE_DOMAIN}}`, `DB_SSLMODE=prefer` |
| App database role | `neondb_owner` | `postgres` (superuser) |
| Services repointed | | `CA_BACKEND`, `ca-horizon-worker` (both set `DB_HOST`, `DB_HOST_POOLED`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SSLMODE`) |

Size: about 35 MB, 54 tables. One vector column, `document_embeddings.embedding` (80 rows), index `document_embeddings_embedding_idx` (ivfflat, `vector_cosine_ops`, `lists=100`).

Not migrated: the Neon database `ca_fresh_proof` (empty test copy, 0 users), Neon roles (see Power BI), and the other two Neon projects (`orange-wave-25351332`, `dry-truth-62388859`), which were never used by the app.

## How the data was moved

1. `php artisan down` on `CA_BACKEND`, `php artisan horizon:pause` on `ca-horizon-worker`.
2. `pg_dump -Fc --no-owner --no-acl` from Neon (direct endpoint, not the pooler), using the PostgreSQL 18 client.
3. Dump uploaded with `railway volume files --volume postgres-volume upload ...`.
4. `pg_restore --no-owner --no-acl --single-transaction --exit-on-error` **inside the Postgres container** (`railway ssh --service Postgres`), about 0.4 s.
5. Row counts compared table by table. All matched except `pulse_*`, which keep recording (expected).
6. `DB_*` variables switched on both services (reference variables, private network).

Verified afterwards: both services report the Railway Postgres version, Horizon running, app returns 200, `users.last_active_at` updated on Railway after a login, Neon row counts (outside Pulse) unchanged since the cutover.

## Problems hit (and what to do next time)

- **Restore over the Railway TCP proxy from WSL was extremely slow and stalled.** Restore inside the container instead.
- **`pg_restore: unsupported version (1.16) in file header`.** WSL had the PostgreSQL 14 client first on PATH. Put `/usr/lib/postgresql/18/bin` first (`pg_dump`, `pg_restore`, `psql` must be 18.x).
- **`railway volume files upload` prompts for a volume and defaults to the first.** One upload went to `ca_backend-volume` (ClamAV) and was deleted. Always pass `--volume postgres-volume`.
- **The local `.env` pointed at the production Neon endpoint**, and local supervisor programs (`horizon`, `pulse-work`) kept running against it after the cutover. They crash-looped on Redis (no local Redis) and Pulse wrote exception rows to Neon. Programs stopped, configs disabled, cron heartbeats commented out.
- **`railway run` executes on the local machine.** Internal hostnames (`*.railway.internal`) are not reachable from it. Use `railway ssh` for checks.
- **Pulse tables always differ between a dump and a live database.** Ignore `pulse_*` in comparisons.
- **Placeholders pasted literally** (`<password>`, `<proxy-host>`, `<DB>`) caused several failed commands. Replace them before running.
- **A flaky connection from WSL** truncated one dump (about 1.0 MB instead of 1.2 MB) and hung `neonctl`. Check the file size before uploading and time-limit dumps (`timeout 120 pg_dump ...`).
- **`railway ssh ... -- <command>` forwards your terminal input.** A second command pasted while the first was running was swallowed by the SSH session. Run such commands one at a time.

## Power BI: deferred, with one correction

There is no Power BI license yet, so nothing Power BI related should be active. The intended design and the checklist for later are in `docs/POWERBI_DEFERRED_PLAN.md`.

**Correction to what was done during the migration.** After the restore, a role named `powerbi_reader` was created by hand on Railway as a **LOGIN role with a password** and `SELECT` on six objects (four raw tables plus the two views). That was based only on the grant list seen on Neon. The code shows the app's design is different: `powerbi_reader` is a **NOLOGIN base role** (migration `2026_08_06_090317_create_powerbi_reader_base_role.php`), and the artisan command `powerbi:create-reader` creates one LOGIN role per workspace (`powerbi_reader_<slug>`) that inherits from it. The hand-made role also had its password printed in a terminal and pasted into a chat. It was locked down on 2026-10-03:

```sql
ALTER ROLE powerbi_reader NOLOGIN PASSWORD NULL;
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM powerbi_reader;
REVOKE ALL ON SCHEMA public FROM powerbi_reader;
GRANT USAGE ON SCHEMA public TO powerbi_reader;
```

That leaves a harmless base role with no login and no table access. Re-grant only what the migrations define when the license is bought.

Also: keep the Railway TCP proxy off when it is not needed. While it is enabled, the database is reachable from the internet.

## Staging is unverified

Older documents (`docs/ENV_2_WORKER_ISOLATION_2026_09_12.md`, `docs/PAYMENT_LIVE_EVIDENCE_2026_09_13.md`, `docs/AUDIT_EVIDENCE_2026_09_12.md`) describe a Railway `staging` environment whose services used a separate Neon endpoint (`ep-cold-cake-axtm6s8c`). The Neon projects visible on 2026-10-02 did not include that endpoint, and only the `production` environment was inspected during the migration. Check whether `staging` still exists and where it points before deleting anything on Neon.

## Local development after the migration

- `bin/post-migration-local-cleanup.sh` (dry run by default, `--apply` to change things, `--local-postgres` to install a local Postgres 18 + pgvector) backs up `.env` outside the repo, points it at `127.0.0.1`, stops the local supervisor programs and comments out the cron heartbeats. It was applied on 2026-10-03.
- **Open decision:** `config/database.php` still defaults `DB_SSLMODE` to `require` with a comment about Neon. Local and Railway private connections have no SSL, so they rely on `DB_SSLMODE=prefer` in the environment. Consider changing the default and the comment.
- `config/database.php` also explains emulated prepares as the fix for the 2026-09-13 PgBouncer incident, but its `options` line is `[]` in both branches, so the setting is not applied. Railway connects without a pooler, so production is not affected. Fix or remove the comment.
- Check whether any migration or test names the role `neondb_owner` (for example `grep -rn neondb_owner database app tests`). It does not exist on Railway, and a fresh `php artisan migrate` on a new database could fail on it.
- Healthchecks: the two heartbeat jobs ran from the local machine. Pause those checks in the Healthchecks dashboard, or move the monitoring into Railway.
- Older docs that mention Neon (`docs/day-10-remaining-work.md`, `docs/POWERBI_SETUP.md`) need a short "superseded by the Railway migration" line. Dated audit evidence should stay unchanged.

## Rollback

Point `CA_BACKEND` and `ca-horizon-worker` back at Neon by restoring the saved `DB_*` values (`~/neon-vars-<service>.backup`, outside the repo). Anything written after the cutover exists only on Railway, so roll back soon or not at all.

## Open items

- [x] Lock down the `powerbi_reader` role (done 2026-10-03)
- [ ] Turn the Railway TCP proxy off when idle
- [x] Run `bin/post-migration-local-cleanup.sh --apply` (done 2026-10-03)
- [ ] Pause the Healthchecks checks
- [ ] Check the `staging` environment
- [ ] Check for `neondb_owner` references in migrations and tests
- [ ] Enable Railway volume backups if the plan supports them, and schedule a regular `pg_dump` (Neon's point-in-time history disappears once Neon is deleted)
- [ ] Give the app its own non-superuser database role instead of `postgres`
- [ ] Delete `/neon-final.dump` from `postgres-volume` and `~/neon-final.dump` after a day or two of normal use
- [ ] Delete the Neon project and the `~/neon-vars-*.backup` files after a week or two without problems
- [ ] Power BI: see `docs/POWERBI_DEFERRED_PLAN.md` (blocked on the license)
