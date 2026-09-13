# Payment incident: owner-run read-only evidence

Prepared 2026-09-13 from backend `df377cd` and frontend `ef0af9e`. No live environment was accessed by the agent. This procedure does not replay webhooks, reconcile purchases, grant credits, seed data, or deploy anything.

**Do not invoke the application's POST `/api/workspace/credits/purchases/{reference}/verify`, reopen the payment return page, or resend a webhook during this read-only phase. Those operations can grant credits.** Paystack's own GET verification endpoint is read-only; the application's similarly named endpoint is not.

## 1. Identify affected examples and deployments

Supply one or two application purchase references, approximate payment time in UTC, expected workspace ID, and Paystack test/live mode. Do not share receipts or screenshots containing customer data. Record the frontend origin and the API origin used by the affected session; existing browser Network entries can establish the latter. Do not export a HAR or copy request headers/tokens. Capture the Netlify deployed commit SHA separately from its dashboard.

For Railway, select `CA_BACKEND` in **production**, open the running deployment's shell, and run the following from the application root. Repeat for **staging / CA_BACKEND**. Capture the dashboard-selected environment, service, deployment ID/SHA and timestamp alongside the output; a fresh PHP process does not establish a historical process's identity.

```sh
php artisan tinker --execute='
$db = \Illuminate\Support\Facades\DB::connection();
$host = static fn ($value) => is_string($value) && preg_match("/^[a-zA-Z0-9.-]+$/", $value) ? $value : "[unset or non-host value]";
$key = (string) config("services.paystack.secret_key");
echo json_encode([
    "captured_at" => gmdate("c"),
    "environment" => getenv("RAILWAY_ENVIRONMENT_NAME") ?: null,
    "service" => getenv("RAILWAY_SERVICE_NAME") ?: null,
    "deployment_id" => getenv("RAILWAY_DEPLOYMENT_ID") ?: null,
    "deployed_sha" => getenv("RAILWAY_GIT_COMMIT_SHA") ?: null,
    "DB_HOST" => $host(getenv("DB_HOST")),
    "DB_HOST_POOLED" => $host(getenv("DB_HOST_POOLED")),
    "effective_db_host" => $host($db->getConfig("host")),
    "effective_db_name" => $db->getConfig("database"),
    "paystack_key_mode" => str_starts_with($key, "sk_live_") ? "live" : (str_starts_with($key, "sk_test_") ? "test" : "missing_or_unrecognized"),
    "callback_frontend_host" => parse_url((string) config("app.frontend_url"), PHP_URL_HOST),
], JSON_PRETTY_PRINT), PHP_EOL;
try {
    echo json_encode(["actual_database" => $db->selectOne("SELECT current_database() AS name")->name]), PHP_EOL;
} catch (\Throwable $e) {
    echo json_encode(["database_check_error_class" => $e::class]), PHP_EOL;
}
'
```

Expected routing from the owner's earlier branch evidence:

| Web environment | Endpoint | Neon branch |
| --- | --- | --- |
| Production | `ep-shy-paper-axsw5ecr.c-4.us-east-2.aws.neon.tech` | `br-divine-lab-axjoi7y0` |
| Staging | `ep-cold-cake-axtm6s8c.c-4.us-east-2.aws.neon.tech` | `br-hidden-wildflower-axoalt7m` |

Both databases can be named `neondb`; that is not branch identity. Retain the actual service/endpoint evidence. The known staging-worker misrouting remains unresolved at runtime, but payment completion is synchronous in the **web** process. Its historical effect on document-credit consumption needs separate worker/document evidence.

## 2. Provider-side facts, without payment/customer data

In the relevant Paystack merchant account, select the incident's Test or Live mode, locate the transaction by the application's `credits-...` reference, and provide only:

| Field | Purpose |
| --- | --- |
| Transaction ID and reference | Match the exact purchase, not another payment by the same user |
| `data.domain` (`test`/`live`) | Distinguish simulated success from real payment and key-mode mismatch |
| `data.status` | Require transaction `success`; top-level API `status: true` alone is not payment success |
| `data.amount`, its JSON type if using the API, and `data.currency` | Compare amount in minor units and currency against the purchase snapshot |
| `data.paid_at` and `data.created_at` | Establish chronology and distinguish pre-fork inherited rows |
| Whether reversed/refunded/disputed, without instrument or customer details | Avoid proposing delivery based on a superseded successful payment |

These fields can also be obtained privately via **GET `https://api.paystack.co/transaction/verify/{reference}`**, using the appropriate merchant secret on your own machine/server. Share only the allowlisted fields above, never the full response, request headers, `authorization`, `access_code`, `customer`, `metadata`, `log`, or payment instrument details. [Paystack verification documentation](https://paystack.com/docs/payments/verify-payments/) distinguishes API status from transaction status and describes this endpoint.

In Settings → API Keys & Webhooks (or the account's corresponding Developers screen), inspect the configured webhook URL for that account and mode. Provide only its origin/path, selected mode, and whether staging and production use the same merchant account/mode. Do not reveal keys or signature headers. The destination must resolve to the intended backend web service's `/api/paystack/webhook`, not the frontend, worker, another environment, or an older deployment.

Where delivery history is available, provide the event type (`charge.success`), attempted destination origin/path, attempt timestamps, HTTP status and duration/timeout only. Otherwise use Railway request logs for this route/time window. Relevant outcomes: 200 acknowledgement, 400 parse rejection, 401 signature rejection, 404/502 routing problems, 422 charge mismatch, 500 transaction failure, 503 missing payment configuration. A 200 can also mean an unknown reference or an already-failed purchase was ignored; it is not proof of credit delivery.

Paystack documents raw-payload HMAC-SHA512 validation, non-200 retries, and different retry windows: live retries extend to 72 hours; test retries occur hourly for 10 hours with a 30-second request timeout. A missing 200 may therefore outlast the frontend's 30-second poll. Do not infer an incident's delivery from those general rules; supply the actual attempt evidence. [Paystack webhooks documentation](https://paystack.com/docs/payments/webhooks/)

## 3. Database schema preflight and suspicious-purchase queries

Use the Neon SQL editor with the **explicit branch selector**, first production and then staging. Retain branch ID/endpoint and capture time with each output. Do not copy a database connection URL into the conversation.

First run this read-only catalog check; if required tables/columns are absent, paste its output and stop before the investigation script:

```sql
BEGIN TRANSACTION READ ONLY;
SET LOCAL statement_timeout = '15s';
SELECT now() AS captured_at, current_database() AS database_name;
SELECT table_name, column_name, data_type
FROM information_schema.columns
WHERE table_schema = 'public'
  AND table_name IN ('credit_purchases', 'workspace_credits', 'referrals',
                    'referral_codes', 'trial_grants', 'documents', 'audit_logs')
  AND column_name IN ('id', 'user_id', 'workspace_id', 'paystack_reference',
                      'documents_purchased', 'documents_remaining', 'documents_purchased_total',
                      'amount_kobo_or_cents', 'currency', 'status', 'paystack_response',
                      'referral_code_id', 'referred_user_id', 'reward_eligible', 'reward_documents',
                      'rewarded_workspace_id', 'rewarded_at', 'credit_accounted_at',
                      'action', 'auditable_type', 'auditable_id', 'created_at', 'updated_at')
ORDER BY table_name, ordinal_position;
SELECT tablename, indexname, indexdef
FROM pg_indexes
WHERE schemaname = 'public' AND tablename IN ('credit_purchases', 'workspace_credits', 'referrals');
SELECT migration, batch FROM migrations
WHERE migration LIKE '%create_credit_purchases%'
   OR migration LIKE '%create_workspace_credits%'
   OR migration LIKE '%create_trial_grants%'
   OR migration LIKE '%create_referrals_tables%'
ORDER BY migration;
COMMIT;
```

If the catalog matches the committed schema, paste and run [payment-read-only.sql](audit-evidence/2026-09-13/payment-read-only.sql) in each selected branch. It enforces a repeatable-read/read-only transaction with per-statement timeouts and caps lists at 200 rows. It covers non-completed purchases, stored success indicators, duplicate references, missing/mismatched lifetime purchase counters, workspace attribution, referral anomalies, existing audit-event inventory and retained usage/grant inputs. The first listing covers 30 days; use the incident's actual dates if older. Nothing in its outputs includes raw provider payloads or customer/payment instruments.

For one supplied reference, this additional lookup includes completed purchases older than the listing window. Replace only the literal reference; keep quoting intact (normal app references are `credits-` plus a UUID):

```sql
BEGIN TRANSACTION READ ONLY;
SET LOCAL statement_timeout = '15s';
SELECT cp.id AS purchase_id, cp.paystack_reference, cp.user_id,
       cp.workspace_id, u.current_workspace_id, cp.status,
       cp.documents_purchased, cp.amount_kobo_or_cents, cp.currency,
       cp.created_at, cp.updated_at, wc.documents_remaining, wc.documents_purchased_total
FROM credit_purchases cp
LEFT JOIN users u ON u.id = cp.user_id
LEFT JOIN workspace_credits wc ON wc.workspace_id = cp.workspace_id
WHERE cp.paystack_reference = 'REPLACE_WITH_AFFECTED_REFERENCE';
COMMIT;
```

Zero duplicate-reference and cumulative-purchase-counter mismatch rows are expected under the inspected code and schema. Pending purchases alone are not suspicious: abandoned/unpaid checkouts are normal. A stored success indication or mismatch is a lead requiring the independent provider/deployment checks above. None of these queries proves a per-purchase missed grant from the current spendable balance.

Staging was forked at `2026-09-11T12:03:03Z`. Rows inherited at that point can legitimately exist in both databases. Compare reference, creation/update chronology, actual payment mode, deployed API/webhook routing and retained history before attributing a write to the wrong branch. Do not delete either copy.

## 4. Display-only check

On an already-open dashboard, the **Refresh document balance** button issues GET `/api/workspace/credits` and does not grant credits. Capture only its HTTP status and the response's `documents_remaining` / `documents_purchased_total`, plus the currently displayed workspace ID and whether the visible number changes. Do not reopen checkout or the return route during this phase. If an earlier Network log exists, compare the verification response's numeric balance with the banner's GET response and their completion times; omit headers and full logs.

Return the actual outputs before any live conclusion or repair is made. **PRODUCTION CHANGE — explicit approval required** for any later reconciliation, webhook replay, verification POST that completes a purchase, configuration correction, deployment, or historical-data repair. No such operation is included here.
