# Power BI: deferred plan

**Status (2026-10-03): deferred. No Power BI license yet.** Nothing Power BI related should be active, exposed or provisioned in production until the license is bought and the checklist below is done.

The code for the integration exists. This file records what it is, what the Neon → Railway migration changed for it, and what to do when we are ready.

> The "what exists" section below comes from a grep of the repo (file names and matching lines), not from reading every file. Confirm details in the code before relying on them.

## What exists in the codebase

- **Reporting views:** `power_bi_kpis` and `power_bi_chart_points` (migration `2026_08_04_000013_create_power_bi_reporting_views.php`).
- **Base role:** `powerbi_reader` is a **NOLOGIN** role (`2026_08_06_090317_create_powerbi_reader_base_role.php`). Login roles inherit from it with `GRANT powerbi_reader TO powerbi_reader_<slug>`.
- **Restricted grants:** `2026_08_06_090318_restrict_powerbi_reader_grants.php` revokes broad access (the docblock says the role had been granted `SELECT` on all 27 tables) and grants only the reporting views plus schema `USAGE`.
- **Row-level security:** `2026_08_19_081447_add_rls_to_powerbi_views.php`. The docblock and `tests/Feature/PowerBiIsolationTest.php` say the table owner bypasses RLS, "like `neondb_owner` does in production".
- **Per-workspace credentials:** `php artisan powerbi:create-reader {workspace-uuid} --label="..."` creates a LOGIN role `powerbi_reader_<slug>` and records it in `powerbi_credentials` (`db_role`, `workspace_id`, `revoked_at`). `php artisan powerbi:revoke-reader powerbi_reader_<slug>` revokes it. To rotate: revoke, then create again.
- **Flags and status:** `workspace_settings.powerbi_enabled` (default false), `documents.power_bi_status` (`synced` / `not-synced` / `failed`, kept in step by `DocumentObserver`), the user notification preference `power_bi_sync`, and a `power_bi` stage in `processing_jobs`.
- **Docs:** `docs/POWERBI_SETUP.md` (written for Neon; needs updating, see below).

## Target design (what we are aiming for)

Each customer workspace gets its own read-only database login, created with `powerbi:create-reader`. That login can only read the two reporting views, and row-level security limits it to its own workspace's rows. It has no access to the base tables. Power BI connects with host, port, database, user and password. The password is shown once when the role is created and stored in a password manager, never in the repo. Credentials are revoked or rotated with the artisan commands. A workspace only syncs when `powerbi_enabled` is true.

## What the Railway migration changed for Power BI

1. **Roles and grants did not migrate.** The dump used `--no-owner --no-acl`. After any restore, re-provision roles and grants. `powerbi_credentials` had 0 rows at migration time, so nothing was lost. If it ever has rows, the roles they name must be re-created and new passwords issued, because the database has no way to restore the old ones.
2. **The app's database role changed** from `neondb_owner` to `postgres` (superuser). Owners and superusers bypass RLS, so the isolation model still holds for the app itself. But `powerbi:create-reader` needs `CREATE ROLE`, so a future non-superuser app role must have `CREATEROLE`.
3. **A hand-made `powerbi_reader` LOGIN role was created during the migration** (with raw-table grants and a password that was shared in a chat). It contradicted the design above and was locked down on 2026-10-03: `ALTER ROLE powerbi_reader NOLOGIN PASSWORD NULL;` plus the revokes in the migration note.
4. **Neon's `powerbi_reader` had broader grants than the migrations describe.** On 2026-10-02 it had `SELECT` on `document_chart_points`, `document_charts`, `document_kpis`, `documents`, `power_bi_chart_points`, `power_bi_kpis` and `powerbi_credentials`. Migration `090318` intends views only. Find out whether the extra grants were intentional, left over from testing, or needed by the RLS migration.
5. **Two Neon roles `powerbi_reader_test_*` (created 2026-09-13)** look like leftovers from `PowerBiIsolationTest` runs. They were not migrated.
6. **Transport:** the Railway Postgres image has no SSL and the TCP proxy is plain text and public.
7. **Docs and comments still say Neon:** `docs/POWERBI_SETUP.md` (advises against the Neon pooler), the `PowerBiIsolationTest` comment (production owner is now `postgres`), and the docblock in the RLS migration.

## Before buying the license

- [ ] Choose the connection route for Power BI: SSL-enabled Postgres behind the TCP proxy, a gateway, or a read replica. A plain-text public proxy is not acceptable for customer data.
- [ ] Enable SSL on the Railway Postgres service and confirm Power BI's PostgreSQL connector accepts the connection with encryption on.
- [ ] Give the app a non-superuser role with `CREATEROLE` and test `powerbi:create-reader` with it on staging or a local copy.
- [ ] Reconcile grants and RLS with the migrations (item 4 above). Re-run the migration logic on a fresh database and compare.
- [ ] Find where the host and port shown to users are built (config, env or hard-coded) and point them at the new route.
- [ ] Update `docs/POWERBI_SETUP.md`, the test comment and the migration docblock to say Railway.
- [ ] Find out exactly what `workspace_settings.powerbi_enabled` gates.
- [ ] Make view changes safe: use `CREATE OR REPLACE VIEW`. `DROP VIEW` + `CREATE VIEW` removes grants.

## When the license is bought

1. Do the checklist above.
2. Confirm the base role `powerbi_reader` exists as NOLOGIN with only the migration-defined grants (no raw tables).
3. On staging or a local copy, create a reader for a test workspace: `php artisan powerbi:create-reader <workspace-uuid> --label="test"`.
4. Connect from Power BI Desktop with the printed credentials. Check that you see only that workspace's rows and that direct table access (`SELECT 1 FROM users LIMIT 1`) is denied.
5. Repeat in production for a pilot workspace, set `powerbi_enabled` for it, and watch `documents.power_bi_status`.
6. Keep the TCP proxy off whenever nobody is connecting, and rotate credentials on a schedule (revoke, then create).

## Until then

- Do not run `powerbi:create-reader` against production, do not enable `powerbi_enabled`, and do not leave the TCP proxy on for Power BI.
- Keep the base role locked (NOLOGIN, no table access).
