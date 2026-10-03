
## Database and deployment (updated 2026-10-02)

Full story: `docs/migrations/2026-10-02-neon-to-railway-migration.md`.

### Where things run
- **Production database is Railway Postgres**: service `Postgres`, project `superb-emotion`, environment `production`, image `pgvector/pgvector:pg18`, database `railway`. Neon is the previous host. It is legacy and kept only as a short-term rollback.
- `CA_BACKEND` and `ca-horizon-worker` connect through Railway reference variables (`${{Postgres.RAILWAY_PRIVATE_DOMAIN}}` and friends) over the private network. `DB_SSLMODE=prefer`, because the image has no SSL.
- Redis is a separate Railway service. Queues use Redis.
- **Staging is unverified.** Older docs mention a `staging` Railway environment that used its own Neon endpoint. Check it before assuming it was migrated.

### Rules
1. **Local development uses a local database.** The local `.env` must never contain a Neon or Railway host. `bin/post-migration-local-cleanup.sh` (dry run by default) fixes a checkout.
2. **Never run `horizon`, `queue:work` or `pulse:work` locally** against a remote database. A local Horizon kept writing Pulse rows to the old database after the cutover.
3. `railway run` executes on the local machine. Use `railway ssh --service <name> --environment production` for anything that needs the private network. Run one `railway ssh -- <command>` at a time, because it forwards terminal input.
4. Always check the linked service (`railway status`) and pass `--volume <name>` to `railway volume files ...`. The CLI defaults to the first volume.
5. Use the PostgreSQL **18** client tools (`/usr/lib/postgresql/18/bin`) for `pg_dump` / `pg_restore`. Restore from inside the Postgres container, not over the public TCP proxy.
6. Dumps made with `--no-owner --no-acl` do not carry roles or grants. Re-create them after a restore.
7. Compare row counts between databases while ignoring `pulse_*`, which keep recording.
8. Never print or paste credentials (including `railway variables` output) into chat, logs, issues or commits.
9. Leave dated audit and evidence documents under `docs/` as they are. They are historical records and legitimately name Neon endpoints.
10. Before relying on a migration or test that names the role `neondb_owner`, check it. That role does not exist on Railway. The app connects as `postgres` there.

### Power BI is deferred (no license yet)
- Do **not** enable, expose or provision Power BI access. In particular: do not create LOGIN roles named `powerbi_reader*`, do not run `php artisan powerbi:create-reader` in production, and do not enable the Railway TCP proxy for Power BI.
- The intended design and the checklist for when the license is bought are in `docs/POWERBI_DEFERRED_PLAN.md`. Read it before touching any Power BI code.

### Codex prompts kept in this repo
- `docs/codex/prompts/01-record-railway-migration.md`: records the migration in the docs and investigates the open Power BI questions (docs and comments only).
- `docs/codex/prompts/02-powerbi-go-live.md`: the Power BI implementation. **Run only after the license is bought** and the human confirms the connection route.
