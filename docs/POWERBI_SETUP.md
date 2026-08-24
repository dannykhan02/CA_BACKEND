# Power BI Setup — Direct Postgres Reporting

CA Backend exposes Power BI data through two Postgres **views** (`power_bi_kpis`, `power_bi_chart_points`), not through a REST export API. Each client/workspace gets its own Postgres **LOGIN role** scoped by Row-Level Security (RLS).

## Architecture

```
Power BI Desktop / Gateway
        │
        ▼ (Postgres connection, per-workspace LOGIN role)
   power_bi_kpis / power_bi_chart_points  (security_invoker views)
        │
        ▼ RLS filters by powerbi_credentials.workspace_id
   documents, document_kpis, document_charts, document_chart_points
```

- The group role `powerbi_reader` holds base SELECT grants; per-workspace roles inherit it via `GRANT powerbi_reader TO powerbi_reader_<slug>`.
- RLS ensures each role sees only rows for its mapped `workspace_id`.
- Restricted documents and non-Ready documents are excluded at the view layer regardless of RLS.

## Prerequisites

1. All migrations applied (`php artisan migrate --force`) using a **direct** Postgres endpoint (not a pooled proxy). See [`day-10-remaining-work.md`](day-10-remaining-work.md).
2. Base group role exists:

   ```sql
   CREATE ROLE powerbi_reader NOLOGIN;
   ```

   On fresh installs this should be created by migration before grant/RLS migrations run. If missing, create it manually once as a superuser.

3. Application data pipeline has populated KPI/chart rows (`GenerateInsightsJob` on Ready documents).

## Provision a workspace credential

From the Laravel app root, with `.env` pointing at the **direct** DB endpoint:

```bash
php artisan powerbi:create-reader {workspace-uuid} --label="CA main dashboard"
```

The command:

1. Creates a Postgres LOGIN role `powerbi_reader_<slug>`.
2. Grants membership in `powerbi_reader`.
3. Inserts a row in `powerbi_credentials` linking the role to the workspace.
4. Prints the password **once** — store it in your secrets manager; it is never persisted in the app DB.

Give this role/password to the Power BI operator for **that client only**.

## Revoke or rotate a credential

If a password is leaked or an operator leaves:

```bash
php artisan powerbi:revoke-reader powerbi_reader_ca_main_dashboard
```

This sets `revoked_at` (RLS denies immediately) and `DROP ROLE`s the login. Create a replacement:

```bash
php artisan powerbi:create-reader {workspace-uuid} --label="CA main dashboard (rotated)"
```

## Connect Power BI Desktop

1. **Get Data → PostgreSQL database**
2. Server: your Postgres host (direct or read replica — not the Neon pooler for long-lived Desktop sessions if you hit connection limits)
3. Database: same as `DB_DATABASE`
4. Username: `powerbi_reader_<slug>` from the create command
5. Password: the one-time value from the create command
6. SSL: required for Neon/managed Postgres (`sslmode=require`)

Select only:

- `power_bi_kpis`
- `power_bi_chart_points`

Do **not** import raw app tables.

## Connect via On-premises Data Gateway

Use the same Postgres connector settings as Desktop. Store the credential in the gateway’s credential manager. One gateway data source per workspace role is recommended so RLS boundaries stay obvious in audits.

## Verify isolation

After connecting, confirm row counts match the target workspace only. Optionally run from psql as the new role:

```sql
SET ROLE powerbi_reader_ca_main_dashboard;
SELECT COUNT(*) FROM power_bi_kpis;
RESET ROLE;
```

Automated coverage lives in `tests/Feature/PowerBiRlsTest.php`.

## Troubleshooting

| Symptom | Likely cause |
|---------|----------------|
| Migration fails on Neon | Using pooled endpoint — switch to direct host |
| Role sees zero rows | Missing/expired `powerbi_credentials` row, or `revoked_at` set |
| Role sees all workspaces | Connected as app owner / superuser instead of reader role |
| Chart view empty | Documents not Ready, or `document_chart_points` not populated yet |
| `role "powerbi_reader" does not exist` | Base group role not created — run base-role migration or manual `CREATE ROLE` |

## Security notes

- Never grant `powerbi_reader` or child roles to human app users.
- Never commit passwords or connection strings to git.
- Restricted classification never appears in reporting views by design.
- Revoke promptly; rotation is revoke + create, not password reset (passwords are not stored server-side).
