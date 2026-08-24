# Day 10 — Remaining Work & Lessons Learned

This document is referenced from [`.env.example`](../.env.example) and tracks the Power BI workspace RLS milestone (migrations dated 2026-08-19) plus operational follow-ups.

## Day 10 checklist

### Database / Power BI RLS

- [x] Reporting views `power_bi_kpis` and `power_bi_chart_points` (exclude Restricted + non-Ready)
- [x] `powerbi_credentials` table mapping Postgres roles → workspace
- [x] RLS policies on base tables + `security_invoker` views
- [x] `powerbi_reader` grant lockdown (views only, plus table grants required by invoker views)
- [x] `php artisan powerbi:create-reader {workspace}` provision command
- [x] `php artisan powerbi:revoke-reader {role}` revoke command
- [x] `GenerateInsightsJob` writes `document_chart_points` rows
- [x] Feature tests: `PowerBiRlsTest`, `HealthCheckTest`, `DocumentAuthorizationTest`
- [ ] Base-role migration `CREATE ROLE powerbi_reader NOLOGIN` on fresh installs (if not yet in migrations/)
- [ ] Frontend Power BI sync UI (separate `CA` repo — out of scope here)

### Operations / deployment

- [x] `bin/provision.sh` — fresh-server bootstrap
- [x] `bin/deploy.sh` — standard deploy sequence
- [x] `bin/health-check.sh` — post-deploy verification
- [x] `bin/smoke-test.sh` — authenticated API smoke checks
- [x] `docs/POWERBI_SETUP.md` — operator guide
- [ ] CI pipeline running `php artisan test`
- [ ] Production Horizon supervisor + backup strategy (see `DEPLOYMENT_RUNBOOK.md`)

## Neon pooled-endpoint lesson

**Symptom:** `php artisan migrate` fails against a Neon *pooled* connection with opaque DDL errors (e.g. cannot create roles, policies, or views reliably).

**Cause:** Neon’s pooler (PgBouncer, transaction mode) does not support all session-level DDL semantics migrations rely on.

**Fix:**

1. Use the **direct (non-pooled)** hostname for migrations and one-off admin commands (`powerbi:create-reader`, `powerbi:revoke-reader`).
2. Optionally use the pooled endpoint only for the Laravel app’s runtime queries (`DB_HOST` / `DB_HOST_POOLED` split in `.env.example`).

Example `.env` layout:

```env
# Direct endpoint — migrations, artisan DDL, Power BI role provisioning
DB_HOST=ep-xxx.region.aws.neon.tech

# Pooled endpoint — optional runtime override (not used by default Laravel config)
DB_HOST_POOLED=ep-xxx-pooler.region.aws.neon.tech
```

Always run `php artisan migrate --force` against the direct endpoint in production deploy scripts.

## Related docs

- [`POWERBI_SETUP.md`](POWERBI_SETUP.md) — connect Power BI Desktop/Gateway to Postgres
- [`DEPLOYMENT_RUNBOOK.md`](DEPLOYMENT_RUNBOOK.md) — full production runbook
