---
description: Power BI go-live. Run ONLY after the Power BI license is bought and the human confirms the connection route.
argument-hint: ROUTE=<ssl-proxy|gateway|replica>
---

# Task: implement the Power BI go-live (run only after the license is bought)

Read `AGENTS.md` and `docs/POWERBI_DEFERRED_PLAN.md` (including its Findings section) first, and follow them.

## Do not start until the human has confirmed

- The Power BI license has been bought.
- The connection route: an SSL-enabled Postgres behind the TCP proxy, a gateway, or a read replica (`$ROUTE`). If `$ROUTE` is empty, ask which one and stop.
- Whether SSL is already enabled on the Railway Postgres service.

If any of these is missing, ask the human and stop.

## Hard rules

- Work on a new branch. Do not push, open a PR or merge unless asked.
- You only change code, tests and docs. **Do not run anything against Railway or Neon.** Production steps are written as a runbook for the human to run.
- Do not run `php artisan` against a remote database. Run tests only against a local Postgres 18 with pgvector.
- No secrets in files, logs or output. Credentials are shown once by the artisan command and stored in a password manager, never in the repo.
- Never grant `powerbi_reader` or any `powerbi_reader_*` role access to base tables. The base role stays NOLOGIN. Only the reporting views are readable, through RLS.
- Do not edit dated audit documents (see `AGENTS.md`).
- Do not change the executable code of migrations that already ran. Add new migrations instead.

## Work to do (adapt each item to what the Findings section says; skip an item that is already true and say so)

1. **Connection details.** If host, port and database name shown to Power BI users are built in code or hard-coded, move them to configuration (for example `config/powerbi.php` with env variables for host, port, database and SSL mode) and make `powerbi:create-reader` print them. Make the output state that encryption must stay on.
2. **Preflight checks in `powerbi:create-reader`.** Before creating a role, verify that `powerbi_reader` exists and is NOLOGIN, that it has no grants on base tables, that the required views exist, and that RLS is enabled where the migrations expect it. Fail with a clear message otherwise.
3. **Read-only audit command `powerbi:audit`.** Report: the base role state; every `powerbi_reader_*` role compared with the non-revoked rows in `powerbi_credentials` (roles without rows, rows without roles); grants on the views; RLS status and policies; any LOGIN on the base role; any grant on base tables. Exit non-zero on a problem. This is the check to run after every restore or deploy, because dumps made with `--no-acl` lose roles and grants.
4. **Keep grants safe across migrations.** Add a test that fails if a migration leaves `powerbi_reader` without its view grants, and use `CREATE OR REPLACE VIEW` in any new view migration (`DROP VIEW` followed by `CREATE VIEW` removes grants).
5. **App database role.** Write a documented SQL script, not executed by you, that creates a non-superuser application role with the privileges the app needs, including `CREATEROLE` if `powerbi:create-reader` requires it, and explain how to switch `DB_USERNAME` over. Remove any code or test assumption that the owner is `neondb_owner`.
6. **Tests.** Extend `tests/Feature/PowerBiIsolationTest.php` or add tests so that a workspace role sees only its own rows through the views, cannot read base tables, and stops working after `powerbi:revoke-reader`. Run the suite locally and report the result.
7. **Docs.** Update `docs/POWERBI_SETUP.md` for Railway: the new host, SSL setup, the chosen route, how to create, rotate and revoke readers, and how to run `powerbi:audit`. Replace the Neon pooler advice. Update `docs/POWERBI_DEFERRED_PLAN.md`, ticking what is done.

## Runbook for the human (write it into `docs/POWERBI_GO_LIVE_RUNBOOK.md`, do not execute it)

Order: enable SSL and decide on the proxy → lock the base role (NOLOGIN, no table grants) and run `powerbi:audit` → switch the app to the non-superuser role on staging first → create a reader for a pilot workspace → connect from Power BI Desktop with encryption on → check that only that workspace's rows are visible and that `SELECT 1 FROM users LIMIT 1` is denied → set `powerbi_enabled` for the pilot workspace and watch `documents.power_bi_status` → turn the TCP proxy off when nobody is connecting → schedule credential rotation.

## Done when

- The tests pass on a local database.
- `powerbi:audit` exists and its tests pass.
- The runbook and docs are written.
- You have reported what changed, what you skipped and why, and the decisions the human still has to make.
