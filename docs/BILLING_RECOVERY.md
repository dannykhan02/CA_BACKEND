# Billing recovery and credit history

Paystack's signed webhook is the primary near-real-time billing signal. Return verification can also complete a pending purchase. A signed event that arrives before its purchase or subscription can be linked is retained in `billing_webhook_events` with `status=deferred` and a safe `deferral_reason`. `RetryDeferredBillingEvent` runs on the existing Horizon queue with delays of 1, 5, 15, 60, and 180 minutes. Purchase completion and subscription linking also dispatch immediate targeted retries. A final unresolved event is retained as `requires_review`; no credits are granted merely because a retry ran.

The scheduler is not required for billing correctness. The hourly expiry sweep is optional reporting maintenance because entitlement checks expire access at request time. Pulse pruning, verification-code cleanup, and failed-job pruning are housekeeping. Email deadline reminder scheduling was removed because deadline visibility is currently in-app.

Operators can run `php artisan billing:reconcile-events --limit=100` after investigating a provider or queue incident. The command retries unresolved authenticated events and reports unresolved counts and oldest timestamps. Its output and the `status`, `retry_count`, `deferral_reason`, and `requires_review_at` columns support alerts for deferred and review-required events. Never replay an unsigned payload.

The `credit_ledger` is append-only evidence for new grants, reservations, releases, usage, and payment activation. Existing `workspace_credits` and `subscription_usage_periods` remain the authoritative balance counters. The migration does not infer or backfill historical ledger rows. Entries before this migration may therefore be absent; keep payment, trial, referral, document, and billing-operation records for historical disputes.

Old pending purchases are retained. A pending subscription checkout blocks another checkout only while it is less than one day old. Older records do not grant credits or block a new checkout. Investigate them through payment history and provider verification; do not delete them as part of recovery.
