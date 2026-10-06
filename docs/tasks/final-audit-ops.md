# DocIntel final audit: release, operations and support

Companion to `final-audit.md`. Nothing here has been run against production.

## 1. Ordered deployment checklist

Facts that shape it: Railway does not run migrations on deploy, `bin/start-production.sh` does not migrate, the start script runs `config:cache`, `route:cache`, `view:cache`, `config:check-production-safety`, then validates ClamAV signatures. The audit changes need **no new migration** (the credit migrations are already applied), so this release is code only. The ordering below is also the template for the migration-bearing release that preceded it.

1. **Preconditions (human):** the HUMAN CHECKS in `final-audit.md` H1 to H6 answered; review and commit the two repos on `chore/final-audit` (not done here).
2. **Backup.** In the Postgres container with the PostgreSQL 18 client tools (`/usr/lib/postgresql/18/bin/pg_dump`), dump database `railway`; keep the file off the repo; verify with `pg_restore --list`. Never print credentials.
3. **Confirm the target:** `railway status` shows project `superb-emotion`, environment `production`, correct linked service. One `railway ssh` command at a time.
4. **Migrations first (only when a release contains one):** `railway ssh --service CA_BACKEND --environment production -- php artisan migrate:status`, then `... -- php artisan migrate --force`. Recommended (not applied): set a Railway **pre-deploy command** of `php artisan migrate --force` on `CA_BACKEND` so the schema is always ahead of the new code; the worker deploy must not run it too. Migrations must be additive and run before new code serves traffic because even flag-off code reads `billing_operations.amount_reserved`.
5. **Deploy `CA_BACKEND` and `ca-horizon-worker` together** from the same commit, flag still unset (off). A web/worker version skew is the real risk: job classes and `QueueTopology` must match.
6. **Smoke tests (no AI spend needed):** `GET /api/health` 200; `php artisan config:check-production-safety`; `php artisan queue:status`; `php artisan schedule:list` shows `billing:release-stale-reservations`, `docintel:resume`, `queue:prune-failed`, `horizon:snapshot`; `GET /api/workspace/credits` returns `ai_credits.enabled=false` and unchanged legacy fields; Horizon shows three supervisors; upload one small document and confirm one legacy reserve and debit, no `operation_quotes` row; request a password reset and verify the mail arrives (and no new `failed_jobs`).
7. **Frontend deploy** (Netlify builds `npm run build` to `dist/`; SPA redirect in `netlify.toml`). Deploy after the backend because the new frontend calls `confirm_credits` only when the backend returns 409. The older frontend against the new backend is safe (it simply cannot confirm a large re-analysis: it shows the error message).
8. **Watch** 30 minutes: Horizon failed jobs, `docintel:ai-usage-report`, Pulse, `queue:status`.
9. **Rollback:** redeploy the previous commit on web and worker together. Schema stays (additive). Flag stays off. If the flag was on, set it to false, redeploy; open AI reservations now settle at Ready or release (fixed in this audit; before it, they did not).

## 2. Config and queue verification (code)

- `config:cache`: the only remaining `env()` outside `config/` is `APP_DEMO_MODE` (demo only). `HORIZON_AUTHORIZED_EMAILS` now goes through config.
- Every job is in `QueueTopology::ROUTES`; three Horizon supervisors (default, extraction, synthesis). Redis `retry_after` 390 s exceeds every worker timeout.
- Env names used in `config/` (for a human diff against `.env.example`, which this audit was not allowed to read): from `config/ai_credits.php` (30 names, listed in `ai-credits-implementation.md` section 6), `config/document_intelligence.php` (`ANTHROPIC_MAX_INFLIGHT`, `ANTHROPIC_EXTRACTION_MAX_TOKENS`, `DOCINTEL_MAX_DOCUMENT_COST_USD`, provider gate names), `config/services.php` (`ANTHROPIC_*`), `config/horizon.php` (`HORIZON_DEFAULT_MAX_PROCESSES`, `HORIZON_EXTRACTION_MAX_PROCESSES`, `HORIZON_SYNTHESIS_MAX_PROCESSES`, `HORIZON_AUTHORIZED_EMAILS`), `config/queue.php` (`REDIS_QUEUE_RETRY_AFTER`), `config/document_processing.php` (`CLAMAV_ENABLED`, `CLAMAV_DRIVER`, `CLAMAV_BINARY`, `DOC_MAX_EXTRACTION_CHARS`), `config/mail.php` (`MAIL_MAILER`, default `log`!).

## 3. Upload scan path (ScanUploadedFileJob, ClamAV)

- Job: `tries=2`, `timeout=60`, queue `default`. Driver `cli` by default (`clamscan --no-summary` on a temp copy, 45 s limit); `socket` (clamd) still available.
- `CLAMAV_ENABLED` defaults to **false**: scanning is skipped with a warning and the stage recorded as skipped. If production does not set it, **uploads are not scanned** (HUMAN CHECK; the landing page says every file is virus-scanned).
- When enabled and the scanner cannot run, the job fails closed (`SCANNER_UNAVAILABLE`, critical log "uploads are effectively blocked", document Failed). Infected files are deleted and the document marked Failed.
- `bin/start-production.sh` refuses to start without signatures and does a real scan of harmless data; `bin/monitor.sh` probes the scanner. The September failures were from the earlier socket driver; the CLI migration is already on main. The unmerged `clamav-cli-migration` branch adds nothing now.
- Test gap: `ScanUploadedFileJobCliTest` needs a real `clamscan` (fails locally without it, runs in CI image).

## 4. Support runbook

Read-only first, always. Never paste credentials or document text into tickets.

**"Why was I charged?"** (flag on)
1. Find the workspace id. Ledger: `select created_at, unit, direction, amount, reason, related_type, related_id from credit_ledger where workspace_id = :ws order by id desc limit 50;`
2. For a document: `select kind, status, amount_reserved, amount_settled, funding_bucket, release_reason, quote_id from billing_operations where resource_id = :doc;` and `select band, credits, status, release_reason from operation_quotes where resource_id = :doc order by created_at;`
3. A debit is exactly the quoted credits at Ready. Failed, removed, expired and (by default) Needs Review releases are credited back. Re-analysis is its own operation, shown with its price before it starts.
4. Flag off: one document unit is debited when evidence is merged, so a Needs Review document can still show a charge (decision D-1).

**Release a stuck reservation.** Open ones: `select * from billing_operations where status = 'reserved' and workspace_id = :ws;`. Normal path: the hourly `billing:release-stale-reservations` (or any read of the customer's credits) releases expired (24 h), failed, Needs Review and removed-document reservations. To force now, in `railway ssh`: `php artisan billing:release-stale-reservations`. Do not edit rows by hand; a manual change must keep ledger `reserve = debit + release + open`.

**Manual adjustment.** There is no admin tool. Use a tinker session once approved by the owner: `app(CreditAccountant::class)->grantSaved($workspaceId, $credits, 'support:<ticket id>', 'support_adjustment', null, null, $staffUserId);` (unique reference makes the ledger row idempotent, but the counter bump is not: run it once). Record the ticket id. Never raise a monthly period's `ai_credits_allowed`.

**Stuck document.**
1. Status and message: `select status, progress, error_message, ai_pipeline->>'route', ai_pipeline->>'awaiting_credit_confirmation' from documents where id = :doc;`
2. Waiting for confirmation: the customer sees a banner "An analysis is waiting for your confirmation"; if they confirmed and nothing moves, `php artisan docintel:resume` re-dispatches after 10 minutes (runs every 5).
3. Processing for long: `php artisan queue:status` (gate permits, backlog per queue), Horizon, `php artisan docintel:ai-usage-report`; check `document_chunks` statuses for the document. `docintel:resume {document}` recovers lost scheduling without replaying ambiguous provider calls.
4. Needs Review: see the cause table in `final-audit.md`; customer can re-analyse (large documents show the price first).
5. Never run `horizon`, `queue:work` or `pulse:work` locally against production.

**Owner commands after a soak period (read-only, via `railway ssh --service CA_BACKEND --environment production`, one at a time):**
- `php artisan docintel:ai-usage-report` (per-document cost, cache tokens, truncation counts).
- `php artisan docintel:credit-economics-report --days=30 --json` and `--what-if` (see memo).
- Exposure for decision D-1 (read-only SQL in a read-only transaction): `select status, count(*) from documents where credit_accounted_at is not null and status <> 'Ready' group by 1;`
- Incomplete-cost share: `select count(*) filter (where estimated_cost_usd is null) as unknown, count(*) as total from document_ai_runs where created_at > now() - interval '30 days';`
- Q&A spend now recorded: `select date_trunc('day', created_at) d, count(*), sum(estimated_cost_usd) from document_ai_runs where purpose='document_qa' group by 1 order by 1 desc limit 14;`
- Failed-job mix: `select left(exception, 80), count(*) from failed_jobs group by 1 order by 2 desc limit 10;`
- Worker ceiling: confirm `DOCINTEL_MAX_DOCUMENT_COST_USD` on `ca-horizon-worker` (variable name only, never print other values).

## 5. Customer announcement (DRAFT, not sent)

**Subject: Your DocIntel plan now uses AI credits**

Hello,

From [DATE], DocIntel measures your plan in **AI credits** instead of a count of documents. This makes pricing fairer: a one-page memo no longer uses the same allowance as a 200-page report.

**What you get each month**
- Starter (KES 1,500 a month): 100 AI credits.
- Professional (KES 3,500 a month): 250 AI credits.
- Free trial: 20 credits, one time.
- Credits you have already saved are kept and converted at 10 credits for each document you had left.

**What an analysis costs**
- Short document: 4 credits. Standard: 10. Large: 30. Very large: 80.
- Scanned documents add 20 credits for text recognition.
- Comparing two documents: 12 credits.
- Asking questions about your documents does not use credits for now.

**You stay in control.** We show the exact number of credits before a large analysis starts and wait for your confirmation. The price does not change once you confirm. If an analysis fails, the credits are returned. Credits for the month do not roll over, and your plan price does not change.

Questions? Write to [SUPPORT EMAIL].

*Review with counsel before sending (see decision D-9). The figures must match the final decision on bands (memo D-7).*

## 6. Regions (report only, no change)

Web and worker run in us-west2; Postgres and Redis run in sfo (reported). Every queue operation and database round trip therefore crosses regions, which adds latency to each credit read (about 22 queries per `/workspace/credits`) and job claim; moving web/worker next to the data, or the reverse, is a future latency item. The privacy notice must state processing outside Kenya (it does) and should drop the stale Neon sentence.
