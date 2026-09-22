# Subscription implementation audit and plan

## Existing system (audit before changes)
- `PaystackClient` initializes and verifies transactions using the backend secret, bounded timeouts, and the existing `/#/billing/return` callback.
- `CreditPurchaseController` owns initialization, server verification and `/api/paystack/webhook`. It authenticates exact webhook bytes with SHA-512 HMAC and locks pending purchases before completion.
- `credit_purchases` / `CreditPurchase` are the financial history, with unique Paystack reference, purchaser, workspace, amount, currency and provider response. Customer details were only present inside provider responses.
- `config/credits.php` offers 100 documents for KES 2,000. No subscription or permanent-access flag exists. UI promises a one-time credit pack, no subscription; this is not evidence of promises made outside the application.
- `workspace_credits` stores remaining credits and lifetime purchased credits. Trial grants are deduplicated by email/IP/fingerprint; new eligible personal workspaces received 10. Referral rewards also use this balance.
- `GenerateInsightsJob` calls `WorkspaceCreditService::accountForReadyDocument` at Ready: one credit once per document, protected by `credit_accounted_at`. Failed documents do not consume credits. Upload checked balance, but concurrent queued work could overspend; reprocessing and other jobs lacked billing checks.
- `LandingPricing`, `WorkspaceCreditBalance`, `documents.ts`, `billing.ts`, `CreditPurchaseReturnPage` implement public pricing, checkout and callback. There was no full billing history/account page.
- Billing is workspace-owned. Existing document policies, workspace membership and purchaser checks control visibility. Comparisons have deterministic structured and optional paid-AI paths. Document AI runs already track provider tokens/cost metadata.
- Existing tests: CreditPurchaseTest, PaymentExceptionLoggingTest, PaymentLifecycleInvestigationTest, WorkspaceCreditsTest, WorkspaceCreditConcurrencyTest, ReferralTest, PersonalWorkspaceJourneyTest plus processing/intelligence/Matter/deadline/frontend suites.
- No production database or Paystack account was queried. Real purchaser count and any off-platform promises remain unknown. Preserve all balances and historical purchase completion. Review production counts/terms before rollout; do not infer that records are test payments.

## Implementation plan
1. Centralize new commercial settings in config/billing.php; change only future eligible grants to 5.
2. Add workspace subscriptions, immutable monthly usage periods, metering reservations, and authenticated webhook receipts; extend credit_purchases with nullable subscription metadata.
3. Extend the existing checkout/client/verification/webhook. Automatic card plans and explicit manual (including M-PESA) payments share the same subscription/entitlements.
4. Centralize state, allowance, storage, and expensive-operation guards. Reserve capacity atomically; settle document credit only at Ready. Keep original balances separate and use paid allowance first, with no paid rollover.
5. Replace pack pricing with backend-fed plans; add billing, cancellation, renewal and history using existing UI conventions.
6. Test lifecycle, security, concurrency and existing product suites against the isolated test database only. Document manual Paystack setup and rollout limitations.

## Delivered behavior and schemas

`config/billing.php` is the commercial source of truth. The public `/api/billing/plans` endpoint removes provider codes and exposes plan prices (minor units), capacities, free allowance, currency and automatic-checkout availability. `config/credits.php` retains the historical package definition and referral reward setting for historical tests/reconciliation; it no longer controls new checkout or free grants.

| Plan / checkout | Price | Paid access | Monthly processing / AI comparisons | Storage |
| --- | --- | --- | --- | --- |
| Free | No charge | Initial allowance, never refreshed | 5 total processing credits; structured comparisons remain usable | Approx. 1 GiB |
| Starter monthly | KSh 1,500 | One calendar month per payment | 20 / 5 | Approx. 1 GiB |
| Starter annual | KSh 15,000 | One calendar year per payment | 20 / 5, separately each month | Approx. 1 GiB |
| Professional monthly | KSh 3,500 | One calendar month per payment | 100 / 30 | Approx. 5 GiB |
| Professional annual | KSh 35,000 | One calendar year per payment | 100 / 30, separately each month | Approx. 5 GiB |

The four paid selections all follow the SAME existing initialize → Paystack checkout → server verification/signed webhook → credit_purchases completion flow. The browser submits only plan/interval/renewal; price and arbitrary plan-code input is rejected or ignored, never used. The callback remains `/#/billing/return` and refetches verified usage; reaching it does not grant access.

Migration: `database/migrations/2026_09_21_000001_add_subscription_billing.php`. It is additive; no balances, old transaction amounts, documents or intelligence are rewritten. Only the isolated test database was migrated by the test suite.

- `subscriptions`: one current subscription per workspace; purchaser user FK, plan/interval/provider, customer/subscription/plan codes, internal status, `auto_renews`, `grandfathered`, current start/end, next payment date, cancellation flags/timestamps, ended timestamp and metadata. Provider subscription code is unique. Reuses workspace ownership rather than introducing team billing.
- `credit_purchases`: remains the ONLY financial ledger. Adds nullable subscription FK, plan/interval, renewal type, configured provider plan, unique provider transaction ID, paid time and allowance snapshot. Subscription purchases have zero `documents_purchased`; their payment buys a period, not permanent balance credits. Historical pack completion still credits its original purchased amount and first-purchase referral rewards remain idempotent for either kind of purchase.
- `subscription_usage_periods`: subscription FK, unique subscription/start, end, processing allowance/used, AI-comparison allowance/used, storage allowance snapshot and timestamps. Annual payment creates twelve monthly records; early manual renewal creates future records without making their allowance available early. Previous usage stays available.
- `billing_operations`: workspace and optional usage-period FK, resource UUID/type, reserved/completed/released status, unique resource/type and timestamps. Serializes reservations under the workspace lock. This is metering history, not another payment ledger.
- `billing_webhook_events`: unique payload hash, event type, authenticated provider payload, processing time and timestamps. Defers events whose subscription is not yet linked and retries them through the existing scheduler. Financial reference/provider transaction uniqueness and usage-period uniqueness also protect against semantically duplicate events with different JSON formatting.
- `trial_grants.initial_credits`: nullable audit snapshot for FUTURE grants only. Old grant rows/balances remain untouched. New eligible registration grants exactly the configured five once. Existing email/IP/fingerprint abuse checks and referral-credit rules are preserved.
- New billing timestamps use the same UTC timestamp convention as existing application tables. Provider offset timestamps are normalized to UTC before storage.

## States and Paystack mapping

| Event / condition | Internal behavior |
| --- | --- |
| Verified first `charge.success` | Existing purchase becomes completed, paid subscription becomes active, monthly usage records are created once. |
| `subscription.create` | Links an actual provider subscription after a verified purchase/customer/plan match; confirms automatic renewal. Does not independently grant access. Can arrive before the payment and be replayed after payment verification. |
| Recurring `charge.success` | Adds a transaction in credit_purchases, advances paid dates and creates the next allowance once. Older already-covered automatic payments cannot extend again. |
| `invoice.create` | Recorded; no access or allowance granted. |
| Successful `invoice.update` | Reconciles a missed charge via the existing server verification API. Reference/amount/currency must match. Verification occurs outside database locks. |
| `invoice.payment_failed`, failed/attention `invoice.update` | `past_due`; older dated failures do not undo a newer renewal. |
| `subscription.not_renew` | `non_renewing`, no automatic renewal, cancel at paid-period end. |
| `subscription.disable` | Keeps already-paid access as non_renewing; cancelled when the paid period ends. |
| Paid end reached | `expired` or `cancelled`, enforced on requests and the hourly scheduler. Data is retained. |

Grace is configurable and defaults to zero days. A past-due subscription in grace uses the last period's remaining allowance, with no free reset. Billing shows payment attention. The existing email reminder service remains intact; this change does not send a new billing-email campaign.

## Automatic, manual and cancellation behavior

Automatic checkout includes the configured Paystack plan and restricts the channel to card. It is presented as confirmed automatic renewal only once a real subscription code is linked. The management button generates Paystack's hosted payment-method link through the existing backend client. Cancellation calls Paystack fetch/disable using its email token, then sets cancel-at-period-end; an unavailable provider does not produce a false cancellation success.

Manual checkout omits the Paystack plan and preserves the normal payment channels, including M-PESA where enabled on the existing Paystack account. Verified payment grants one month/year, extending from the paid-through date when renewing early. `auto_renews` stays false. No automatic recurring M-PESA capability is claimed or implemented. Manual cancellation stops the internal renewal intention without shortening the paid period.

References: [Paystack subscriptions](https://paystack.com/docs/payments/subscriptions/), [subscription management API](https://paystack.com/docs/api/subscription/), [webhook verification](https://paystack.com/docs/payments/webhooks/), [payment channels](https://paystack.com/docs/payments/payment-channels/). Paystack applies its plan amount when a plan is supplied; Dashboard plans MUST match the server configuration. Month-end automatic billing uses the provider's confirmed next payment date.

## Entitlements and credit priority

`EntitlementService` guards upload, reprocessing, Q&A, semantic search, optional AI comparisons, extraction/OCR jobs, all intelligence jobs, embeddings and visual analysis. Existing policies still control tenant/document access.

1. While paid/grandfathered access is active, processing uses that month's allowance, never the saved free/legacy balance. Exhausting the paid allowance does not silently consume saved credits.
2. Without paid access, existing saved credits (initial free, historical purchases and referral rewards) remain usable for processing. They never receive a monthly reset.
3. Upload reserves a slot and storage capacity under the workspace lock; Ready settles exactly one processing credit per document. Retries do not debit a successfully completed document again. Failed/deleted documents release reservations; stale reservations expire after the configured pipeline window.
4. An already-authorized pipeline can finish for up to `BILLING_PIPELINE_HOURS` (default 24); new API retries must recheck entitlement. This prevents the final credit's downstream jobs from being blocked by their own debit, without granting unlimited future processing.
5. AI comparisons consume their separate monthly allowance when queued, once per saved comparison in that usage period. Worker redelivery does not double charge. Same-period failed retries reuse the reservation; a retry in another usage period requires that period's allowance. Deterministic structured comparisons do not call AI and stay available.
6. Q&A and semantic search preserve their existing unmetered consumption rule but require active access or usable saved credits. They do not become extra document debits.
7. Exhausted/expired users keep login, documents, intelligence, Matters, relationships, tracked deadlines/reminders, saved comparisons, Word export and billing. Storage usage approximates stored original uploads using `documents.size_kb`; it does not include derived images, exports or provider backups.

## Existing purchaser check and approved grandfathering

On 2026-09-21 a read-only transaction against the configured database returned:

| Status | Paystack mode | Payments | Distinct purchasers | Workspaces |
| --- | --- | --- | --- | --- |
| completed | test | 4 | 4 | 4 |
| pending | unknown | 4 | 4 | 4 |

No completed live payment was recorded in that database. The configured application environment identifies itself as `local`, using a remote database. This is not proof that a separate production database has no customers. No records were changed by the audit. Re-run `php artisan billing:audit-payments` against the intended environment to obtain the same aggregate report; it explicitly uses a READ ONLY transaction, prints no customer details, and requires no new billing schema.

The user explicitly approved **ongoing Starter access with no recurring charge** for verified existing one-time purchasers. A completed historical pack with positive purchased credits/amount and a stored successful **live** Paystack response qualifies. Entitlement evaluation creates/maintains the same subscription/period model with `provider=legacy`, `grandfathered=true`, Starter allowances and no Paystack subscription/charge. Existing saved credits remain untouched. Test or pending payments do not qualify. Successful legacy purchases with missing provider mode retain their old credit-based functionality pending verification; do not classify these as real or test without checking Paystack.

Grandfathered users may buy Professional; ongoing Starter returns after that paid subscription ends. An upgrade in the exact same usage-period instant preserves already-used counters while increasing capacity. No Business checkout or new team permissions were added.

## Frontend changes

- LandingPricing delegates to shared PlanCards using the public backend catalog: Free, Starter and highlighted Professional, monthly/annual choice and computed annual savings (KSh 3,000 / 7,000 at defaults).
- The existing balance banner and exhausted-upload prompts lead to billing; no repeated upgrade modal.
- New `/#/billing` uses the current AppShell/design tokens and shows plan, interval, internal state, renewal type, paid-through/renewal date, documents/comparisons, storage, saved credits, cancellation, payment-method management, renewal checkout and paginated existing payment history. Pending transactions can be verified there.
- Grandfathered access is explicitly labeled as ongoing with no recurring charge. Cancelled, past-due and expired messages preserve visibility of old work.
- The return page continues existing verified polling and triggers balance refresh, then links to billing. Draft legal/product copy no longer advertises the old credit pack as the current offer.

## Manual configuration and rollout (NOT performed)

Create four **test** Paystack Dashboard plans, currency KES, with these recurring amounts:

| Dashboard name | Paystack interval | Amount | Environment variable |
| --- | --- | --- | --- |
| Starter Monthly | monthly | KSh 1,500 | PAYSTACK_STARTER_MONTHLY_PLAN_CODE |
| Starter Annual | annually | KSh 15,000 | PAYSTACK_STARTER_ANNUAL_PLAN_CODE |
| Professional Monthly | monthly | KSh 3,500 | PAYSTACK_PROFESSIONAL_MONTHLY_PLAN_CODE |
| Professional Annual | annually | KSh 35,000 | PAYSTACK_PROFESSIONAL_ANNUAL_PLAN_CODE |

Copy test codes into the BACKEND environment, retain the existing `PAYSTACK_SECRET_KEY`, and confirm the existing frontend URL/callback and `/api/paystack/webhook`. Subscribe to the mapped events above; test duplicate, delayed and out-of-order delivery. No new provider, alternate webhook, public secret key or live plan creation endpoint was added.

`.env.example` lists all optional commercial overrides: `BILLING_CURRENCY`, `BILLING_FREE_INITIAL_CREDITS`, `BILLING_FREE_STORAGE_BYTES`, `BILLING_{STARTER,PROFESSIONAL}_{MONTHLY,ANNUAL}_AMOUNT`, `BILLING_{STARTER,PROFESSIONAL}_{DOCUMENTS,COMPARISONS,STORAGE_BYTES}`, `BILLING_GRACE_DAYS`, `BILLING_PIPELINE_HOURS`. Amounts are minor units; limits/codes are server-controlled. Defaults live only in billing config, with environment examples documenting them.

Before a separately approved production rollout: back up and review the additive migration, verify any live historical purchases/refunds and off-platform promises, manually create matching live Dashboard plans, set live codes/secret, and ensure the existing queue and Laravel scheduler run. This task did not deploy, run production migrations, create plans, enable production billing, or issue real charges.

Manual test checklist:
- All four plan/interval combinations through Paystack test checkout and backend verification.
- First card payment followed by subscription.create, automatic renewal, invoice failure, management link and cancel-at-period-end.
- M-PESA/manual monthly and annual payments with auto_renews false; duplicate payment and early renewal.
- Month-end and leap-year period boundaries; annual monthly refresh without rollover.
- Exhausted free/paid usage via UI AND direct APIs; concurrent uploads/comparisons, failed processing and preserved old data.
- Tenant membership/purchaser checks, workspace switching during checkout, delayed callback without session storage.
- Verified-live grandfathering and no grandfathering for test/pending/unknown payments.

## Limits and analytics

No prorated paid-plan changes or new Business/team billing are included. For an existing paid subscription, switch plan/interval after its paid period ends; cancel automatic renewal before starting another checkout. A pending checkout blocks another for 24 hours so ambiguous initialization failures can be reconciled rather than double-charged. Provider codes must be configured for automatic checkout; manual checkout can function without them.

Recurring events without a subscription code are matched only when customer+plan identifies exactly one subscription. Ambiguous workspace ownership is deferred; signed invoice.update with a subscription code can disambiguate and verify the charge. Monitor unprocessed webhook events. Billing email notifications beyond existing infrastructure are not added. Native Paystack/M-PESA/browser behavior still requires Dashboard sandbox testing, and the six environment-dependent tests listed below remain skipped.

Subscribers by plan/interval/renewal type/status are available from subscriptions (`grandfathered` separately identifies non-revenue access). Period rows support monthly documents/comparisons and utilization; payment plan/interval/amount/currency snapshots support revenue from credit_purchases, without interpreting legacy documents_purchased as subscription allowance. Cancellation timestamps and authenticated invoice failure events support cancellation/failed-renewal counts. Existing document_ai_runs token/cost records can be joined to workspace/document and date windows for expensive-user and approximate processing-cost analysis. No analytics dependency was introduced.
