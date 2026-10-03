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

## Findings (2026-10-03)

These are static repository findings, not a check of the live Railway database. Staging remains unverified.

1. **`neondb_owner` references.** In the requested `app/`, `database/`, `tests/`, `config/`, `bin/` and `routes/` scope, the only matches are comments: `database/migrations/2026_08_19_081447_add_rls_to_powerbi_views.php:29` (historical owner claim) and `tests/Feature/PowerBiIsolationTest.php:25-26` (now explains the former and current owner). Code references: **not found**. A fresh migration would not fail *because the `neondb_owner` role is absent*: no SQL names that role. This does not establish that a fresh migration succeeds for other reasons. The migration comment should be corrected in a later, explicitly authorized task; prompt 01 excludes migration comment edits.

2. **`workspace_settings.powerbi_enabled`.** The column is created with default `false` at `database/migrations/2026_08_04_130002_create_workspace_settings_table.php:23`, filled as `false` by `database/migrations/2026_08_04_130007_backfill_workspace_settings_and_ai_configs.php:19`, `app/Observers/WorkspaceObserver.php:33`, and `app/Console/Commands/BackfillWorkspaceSettings.php:40`, and declared fillable/cast at `app/Models/WorkspaceSetting.php:19,27`. A runtime read or conditional gate using the flag: **not found** in `app/`, `routes/`, `config/`, `database/`, or `tests/`. In particular, `app/Observers/DocumentObserver.php:77-92` sets `power_bi_status` from document status and classification without reading the flag; the reporting views filter documents at `database/migrations/2026_08_04_000013_create_power_bi_reporting_views.php:18-54` without reading it. The existing target-design sentence above saying a workspace only syncs when enabled is therefore unverified by this code.

3. **Connection details shown to Power BI users.** An application path constructing or displaying a Power BI host, port, or database name: **not found** in `app/`, `routes/`, or `config/`. `app/Console/Commands/CreatePowerBiReader.php:64-69` prints the role, password, and usage advice, but no endpoint. `config/database.php:99-103` builds the app's own PostgreSQL connection from `DB_HOST`, `DB_PORT`, and `DB_DATABASE`; `tests/Feature/PowerBiIsolationTest.php:157-163` constructs a test PDO DSN from connection config. `docs/POWERBI_SETUP.md:70-74` leaves the host to the operator and contains old Neon advice. A Power BI specific host/port/database setting: **not found**.

4. **Migration-defined role, grants, and RLS.** `database/migrations/2026_08_06_090317_create_powerbi_reader_base_role.php:15-27` creates `powerbi_reader NOLOGIN` if absent; its duplicate-role handler does not correct an existing LOGIN role. `database/migrations/2026_08_06_090318_restrict_powerbi_reader_grants.php:20-31` revokes all current table and schema privileges, then grants schema `public` `USAGE` and `SELECT` on `power_bi_kpis` and `power_bi_chart_points`, and revokes default `SELECT` on future tables. `database/migrations/2026_08_19_081447_add_rls_to_powerbi_views.php:37-97` then grants `SELECT` on `documents`, `document_kpis`, `document_charts`, `document_chart_points`, and `powerbi_credentials`, and sets both views to `security_invoker=true`. It enables RLS on the four base tables with `powerbi_workspace_scope FOR SELECT TO public`, allowing rows whose `workspace_id` equals the unrevoked credential row for `current_user` (`:46-76`); it enables RLS on `powerbi_credentials` with `powerbi_credentials_self FOR SELECT TO public`, allowing only rows with `db_role = current_user` (`:79-92`). The later `database/migrations/2026_08_24_000000_revoke_login_from_powerbi_reader_role.php:26-33` forces `NOLOGIN` and `PASSWORD NULL`; it adds no grants or policies. Later migrations changing these grants or RLS: **not found**. Thus the migration-defined final grants are schema `USAGE` and `SELECT` on seven objects: the two views, four base tables, and `powerbi_credentials`. Every grant in the old Neon seven-object list is explained by the migration chain; extra grants from that list: **none**. This corrects the earlier assumption above that base-table access was unexplained. The grants are required by security-invoker views, but also permit direct `SELECT` on those RLS-protected tables; the target-design claim above that login roles have no base-table access is inaccurate. The locked-down Railway role described above currently has fewer grants than this migration-defined state; do not re-grant while Power BI is deferred.

5. **Database role mutations.** `database/migrations/2026_08_06_090317_create_powerbi_reader_base_role.php:19-36` creates/drops the base role; `database/migrations/2026_08_24_000000_revoke_login_from_powerbi_reader_role.php:32-33` alters it. `app/Console/Commands/CreatePowerBiReader.php:55-56` creates a LOGIN role and grants it base-role membership; `app/Console/Commands/RevokePowerBiReader.php:48` drops that role. Tests do the same at `tests/Feature/PowerBiIsolationTest.php:86,100-101,227-228,236` and `tests/Feature/PowerBiRlsTest.php:57-64,135-136,160`. The local-only `bin/post-migration-local-cleanup.sh:154-164` creates/alters a local role using `sudo -u postgres`. Seeders with role DDL: **not found**. `CREATE ROLE`, `ALTER ROLE`, and `DROP ROLE` require a sufficiently privileged role (`CREATEROLE` for eligible non-superusers, or superuser); granting membership also needs authority to administer the target role. The RLS/grant migrations additionally require the relevant object ownership or grant authority. A future non-superuser app role needs its actual PostgreSQL 18 role membership and object privileges verified on a local copy or staging before use.

6. **Pooler and SSL settings.** `DB_HOST_POOLED` is read/set only by the local cleanup script (`bin/post-migration-local-cleanup.sh:112-127,203`) and is present in `.env.example:29`; `config/database.php:99` uses `DB_HOST`, so no runtime use of `DB_HOST_POOLED` was found. `config/database.php:108-111` uses `DB_SSLMODE` for `sslmode`, defaulting to `require`; `.env.example:35` also says `require`, while local cleanup sets `prefer` (`bin/post-migration-local-cleanup.sh:127`). The test DSN uses the configured `sslmode` with a `prefer` fallback (`tests/Feature/PowerBiIsolationTest.php:161-163`). `PDO::ATTR_EMULATE_PREPARES` use in app/config code: **not found**. The emulated-prepares explanation at `config/database.php:112-128` does not match `:129`, where both `options` branches are empty arrays. The `:108-110` Neon/local-development comment and `require` default are stale for the current local/Railway setup. Reported here; no config change made.

7. **Other stale Neon assumptions.** `docs/POWERBI_SETUP.md:70-74,103-107` and `docs/day-10-remaining-work.md:32-50` still give Neon operating instructions; both now have superseded banners. `config/database.php:90-98,108-129` retains Neon pooler and SSL/prepares commentary, including the stale claims above. `.env.example:29,35` retains the pooled-host placeholder and `require` SSL default. `setup-env-profiles.sh:6,25-31,65` assumes copying `.env` produces a Neon production profile and prints its DB variables; `switch-env.sh:66-71` labels production as Neon. Do not run those scripts with this local-only checkout. The migration docblock at `database/migrations/2026_08_19_081447_add_rls_to_powerbi_views.php:29-33` names the former owner. Dated audit and evidence documents were intentionally left untouched. The Railway staging environment cannot be verified from repository files alone.
