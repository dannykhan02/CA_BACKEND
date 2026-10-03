---
description: Record the Neon -> Railway Postgres migration in this repo and investigate the open Power BI questions (docs and comments only)
---

# Task: record the Neon → Railway migration and prepare the deferred Power BI plan

Read `AGENTS.md` first and follow it. This task changes **documentation and comments only**. It must not change application behavior.

## Context (facts, as of 2026-10-03)

- On 2026-10-02 the production database moved from Neon to **Railway Postgres**: service `Postgres`, project `superb-emotion`, environment `production`, image `pgvector/pgvector:pg18`, database `railway`, volume mounted at `/var/lib/postgresql`.
- `CA_BACKEND` and `ca-horizon-worker` now connect through Railway reference variables with `DB_SSLMODE=prefer` (the image has no SSL). The app's database role changed from `neondb_owner` to `postgres`.
- The data was copied with `pg_dump --no-owner --no-acl`, so **roles and grants did not migrate**.
- On 2026-10-03 the developer's local checkout was cleaned up: local `.env` points at `127.0.0.1/ca_dev` (no local Postgres installed yet), the local supervisor `pulse-work` program is disabled, and the cron heartbeat jobs are commented out.
- **Power BI is deferred: there is no Power BI license yet.** The intended design is in `docs/POWERBI_DEFERRED_PLAN.md`. A hand-made `powerbi_reader` LOGIN role was created on Railway during the migration by mistake; the human locked it down on 2026-10-03 (NOLOGIN, no table grants).
- Unknown and to be investigated: whether a `staging` Railway environment still exists, and whether any migration or test depends on the role name `neondb_owner`.

## Hard rules

- Do not run `railway`, `neonctl`, `pg_dump`, `pg_restore`, or any `php artisan` command that connects to a database, unless a **local** Postgres is running and `.env` points at `127.0.0.1`. Never change `.env` to a remote host.
- Do not read, print or copy secrets (`.env`, `railway variables` output, connection strings). Do not put credentials in any file.
- Do not edit dated audit or evidence documents: `docs/AUDIT_*`, `docs/ENV_2_WORKER_ISOLATION_*`, `docs/PAYMENT_LIVE_EVIDENCE_*`, `docs/PHASE_2_VERIFICATION_REPORT.md`, `docs/audit-evidence/**`.
- Do not change the executable code of any migration that has already run. Comment-only edits in migrations are not part of this task either: list suggested edits in the findings instead.
- Do not commit, push or open a PR unless the human asks.
- Do not create or enable any Power BI access (no roles, no `powerbi:create-reader`, no `powerbi_enabled` changes).

## Steps

### 1. Check the files that should already exist
Confirm these exist, and **stop and tell the human** if any is missing (they must be copied in first):
- `AGENTS.md` containing a section "Database and deployment"
- `docs/migrations/2026-10-02-neon-to-railway-migration.md`
- `docs/POWERBI_DEFERRED_PLAN.md`
- `bin/post-migration-local-cleanup.sh`

If `AGENTS.md` contains the "Database and deployment" section more than once, report it. Do not merge or rewrite it.

### 2. Add "superseded" banners
At the very top of `docs/POWERBI_SETUP.md` and `docs/day-10-remaining-work.md`, add one short note (a blockquote) saying the Neon-specific parts are superseded by `docs/migrations/2026-10-02-neon-to-railway-migration.md`, and for Power BI by `docs/POWERBI_DEFERRED_PLAN.md`. Change nothing else in those files.

### 3. Fix one stale comment
In `tests/Feature/PowerBiIsolationTest.php`, the docblock says the table owner bypasses RLS "like neondb_owner does in production". Rewrite only that sentence to say the table owner bypasses RLS (the app's owner role is `postgres` on Railway; it was `neondb_owner` on the former Neon host). Do not touch test code.

### 4. Investigate (read-only) and write the findings
Append a section `## Findings (YYYY-MM-DD)` to `docs/POWERBI_DEFERRED_PLAN.md`. Do not rewrite the rest of the file. Answer each item with file and line references, and say "not found" when something is not found:

1. Every reference to `neondb_owner` in `app/`, `database/`, `tests/`, `config/`, `bin/` and `routes/`: file:line, comment or code, and whether a fresh `php artisan migrate` on a database that has no `neondb_owner` role would fail.
2. What `workspace_settings.powerbi_enabled` gates (list the code paths).
3. Where the host, port and database name shown to Power BI users are built, if anywhere (config, env, hard-coded, or nowhere).
4. The final state defined by migrations `2026_08_06_090317_create_powerbi_reader_base_role`, `2026_08_06_090318_restrict_powerbi_reader_grants`, `2026_08_19_081447_add_rls_to_powerbi_views` and any later migration touching `powerbi` or row-level security: role `powerbi_reader` (LOGIN or NOLOGIN), its exact grants, and every RLS policy. Compare with this list of grants seen on the old Neon database (all `SELECT`): `document_chart_points`, `document_charts`, `document_kpis`, `documents`, `power_bi_chart_points`, `power_bi_kpis`, `powerbi_credentials`. State which extra grants the migrations do not explain.
5. Every place that creates or alters database roles (commands, migrations, seeders, tests) and the privilege each needs (`CREATEROLE`, superuser, and so on).
6. Any use of `DB_HOST_POOLED`, `sslmode`, or `ATTR_EMULATE_PREPARES` in code and config, and whether the comment in `config/database.php` matches what the code does.
7. Any other file that still assumes Neon (outside the audit documents listed above).

### 5. Report
Reply with: the files you changed, the findings in brief, and a short list of decisions the human has to make. Do not make those decisions yourself.
