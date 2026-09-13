# Successful payment but credits not visible — investigation

Source inspected locally on 2026-09-13: backend `df377cd` (including logging commit `f8a5c8f`) and frontend `ef0af9e`. No application/payment logic was edited. New tests characterize current behavior; local test success is not live incident verification. Payment reliability and the document upload-to-final-output workflow are the two current functional priorities; lower-tier audit work was not resumed.

**Confirmed locally:** a frontend refresh race can show a completed payment's new balance in the return card while the banner still displays the old balance. **Not established:** which real users were affected, whether their Paystack payments succeeded, or whether any production credits were omitted. Affected references and owner-run evidence are still pending. The recent exception-logging changes did not alter crediting behavior.

## 1. Actual payment flow

| Stage | Source and actual behavior |
| --- | --- |
| 1. Start checkout | `WorkspaceCreditBalance.buy()` reads current credits, checks session storage, and POSTs the package ID. Authentication/email verification and workspace membership are required server-side. |
| 2. Create purchase | `CreditPurchaseController::store()` persists `pending`, purchaser ID, current workspace ID, configured document count, amount in minor units and currency **before** contacting Paystack. The request does not supply those authoritative values. |
| 3. Reference / checkout | The app generates `credits-<UUID>` under a DB unique constraint and sends it to Paystack initialize. `PaystackClient` has connection/request timeouts and no automatic POST retry. It requires HTTPS checkout URL and matching returned reference. The browser stores reference/user/workspace and redirects. |
| 4. Provider completion | Paystack completion occurs outside the application's DB transaction. Browser redirection is not payment proof. Merchant account, mode, actual provider status and delivery must be supplied by the owner. |
| 5. Webhook receipt | Public POST `/api/paystack/webhook` runs synchronously in the **web** process; it is outside Sanctum/email-verification groups. No Horizon job is dispatched for crediting. |
| 6. Signature | The configured secret signs exact request bytes with HMAC-SHA512 and `hash_equals`; missing/wrong signature returns 401, missing key 503. No queue or Redis call is in this signature path. |
| 7. Parse | JSON is parsed only after signature validation. Invalid JSON returns 400; non-charge-success events are acknowledged. Missing charge/reference fields return 422. |
| 8. Lookup | The webhook opens a DB transaction and locks the purchase by reference. Unknown references and any non-pending status are acknowledged with 200 without granting credits. |
| 9. Alternative verification | Return page POSTs `/workspace/credits/purchases/{reference}/verify` every two seconds for up to 30 seconds. The API checks current workspace, membership and purchaser; completed/failed rows return immediately. For pending rows it calls Paystack GET verify outside the DB lock, then opens a transaction and locks/rechecks the purchase. |
| 10. Validate completion | Both paths compare reference/amount/currency with the persisted snapshot and require successful provider status. PHP amount/currency comparisons are strict. Mismatch returns 422; non-success verification remains pending. |
| 11. Grant | Both call `WorkspaceCreditService::completePurchase()` within their transaction. It serializes identified buyers with a user-row lock, then locks the purchase workspace's credit row. |
| 12. Balance | `addCredits()` increments `documents_remaining` by the purchase's snapshot count. |
| 13. Lifetime counter | The same locked credit-row save increments `documents_purchased_total`; consumption/trials/referrals do not increment that counter. |
| 14. Completion / referral | The purchase is saved as completed. Only the buyer's first completed purchase may grant an eligible pending referral. The referrer, referral and reward workspace balances are locked/updated in the same transaction. Legacy purchases without a buyer ID do not award a referral. |
| 15. Commit | Response data may be constructed inside the callback, but the response is returned after `DB::transaction(..., 3)` commits. An exception, including a referral exception, rolls back the purchase, payload and balance changes. The three-attempt transaction retry behavior is unchanged. |
| 16. Display | Verification returns balances in snake_case; `documents.ts` maps camelCase. Return UI confirms only this reference's `completed` status and emits `workspace-credits-changed`. The banner GETs current-workspace credits on mount, focus and that event; it has component state, no shared query cache or timer. |

Relevant source: [controller](../app/Http/Controllers/Api/CreditPurchaseController.php), [Paystack client](../app/Services/PaystackClient.php), [credit service](../app/Services/WorkspaceCreditService.php), [balance endpoint](../app/Http/Controllers/Api/WorkspaceCreditController.php), [routes](../routes/api.php). Frontend files are in the separate `CA` repository: `src/components/WorkspaceCreditBalance.tsx`, `src/pages/CreditPurchaseReturnPage.tsx`, `src/billing.ts`, `src/documents.ts`, `src/auth.tsx`, `src/router.ts`.

## 2. Intended invariant and atomicity

For an authenticated, matching successful purchase handled through either current entry point, one committed completion contributes the configured snapshot count to **both** counters of **the purchase's workspace**, exactly once. Purchase status, stored provider response, buyer counters, referral marker and referrer credits share the default database connection and the caller's transaction. Models do not override that connection. The balance update precedes the completed marker, and any subsequent failure rolls it back.

The service itself relies on its caller to hold the transaction and purchase lock; the only application callers found are the two transactional controller paths. This is an application invariant, not a cross-table DB constraint. Direct SQL/manual edits could create an inconsistent completed marker. A characterization test deliberately constructs that state locally: replay/verification does **not** repair it, because non-pending rows exit early. Do not reset such a marker to pending or blindly replay it.

Historical source `679609b` also persisted before initialization and granted both counters plus completion inside one transaction. It lacked the verification fallback later added in `d05fdc1`. Which version was deployed at each incident remains unknown; local git is not deployment evidence.

## 3–4. Failure locations and classification

| Condition | What current source/tests establish | Classification / evidence still needed |
| --- | --- | --- |
| Paid at provider, pending locally | No automatic reconciliation command/job/schedule was found. Missing webhook plus abandoned/expired callback can leave a purchase pending indefinitely. | Delivery/reconciliation candidate; provider success and deployed fallback required. |
| Completed with no purchase-counter increment | Not produced by the tested transactional paths, including failures injected after the completed UPDATE. | Historical/manual corruption, deleted/reset balance rows, different database or different deployed code must be investigated; no affected row yet identified. |
| Credits granted, stale banner | **Reproduced:** initial banner GET is in flight, verification confirms 110 and emits event, `inFlight` drops event, old GET resolves with 10. Return card says 110; banner says 10 until a later refresh. | Confirmed frontend defect; regression test intentionally records the still-unfixed race. |
| Webhook never arrives | Destination/key mode/network/retry window cannot be learned from source. A bad destination may be another environment's web service. | Owner Paystack delivery history and Railway requests required. |
| Webhook before persistence | Normal route commits purchase INSERT before external initialization. Existing early-webhook test covers receipt during initialization. | Not a demonstrated race in inspected source; different DB destinations or historical code could look similar. |
| Legitimate signature rejected | Algorithm/raw-body order matches Paystack's contract and whitespace-signature tests pass. Different key/account/mode, rotation or altered bytes would reject. | Configuration/delivery evidence needed; do not weaken verification. |
| Reference lookup misses | Handler returns 200 without a grant or durable webhook inbox. A wrong database destination can therefore acknowledge an event that never completes the intended purchase. | Confirmed behavior, conditional environment failure mode; references/deployment/branch comparison required. |
| Successful payment amount/currency rejected | Strict integer/string comparison rejects differing value, currency, or some JSON type differences. Snapshot values are authoritative. Re-encoded stored JSON may lose original numeric-type distinctions. | Obtain safe provider amount/type/currency; no demonstrated reason to loosen validation. |
| Verification succeeds, transaction fails | Missing credit rows, DB errors or a referral failure can roll back all fulfillment after provider success. Failure after referral UPDATE also rolls back both workspaces. | Backend failure candidate; new local tests prove rollback, not which live account experienced it. |
| Crediting error after completion | Current order credits before completed; a later exception rolls back both. | Partial normal-path commit not reproduced. |
| Idempotency suppresses first valid grant | Pending matched rows grant once. A pre-existing failed/completed marker skips, even if a later event reports success. Failed rows are not verified against Paystack. | Need evidence that a definitive initialization rejection was contradicted, or a marker was corrupted; no state reset proposed. |
| Concurrent purchases/completion | User and credit locks protect separate purchases; same-purchase webhook versus verification is tested with independent local processes. | Exactly-once local behavior confirmed; repeated exhausted deadlocks/timeouts could still delay fulfillment, not partially commit it. |
| Current workspace changes | Verification returns 403 when purchase workspace differs from current workspace, even if buyer retains membership. Webhook still credits the original workspace; GET displays current workspace. | Reproduced workspace-attribution behavior; a zero balance in the new workspace does not mean the original purchase was missed. |
| UI workspace metadata is stale | Auth restores cached user/workspace; balance endpoint uses server-side current workspace and returns no workspace ID. Auth refresh can update metadata, but banner itself cannot identify a mismatch. | Source-level display/attribution risk; needs affected session's workspace/API evidence. |
| Ambiguous initialization later succeeds | Network/server failures remain pending; a later correct webhook/verification can complete. UI clears checkout metadata on initialization error, and no background sweep exists. | Covered recovery when a later signal occurs; no guarantee a signal will occur. |
| Callback auth/network failure | All verification errors become a generic pending UI after 30 seconds. A sign-in redirect goes to dashboard, with no automatic return-reference resumption. Checkout fallback is session/workspace scoped and expires after 24 hours. | Can hide 401/403/404/422/429/502 causes or lose foreground reconciliation; delivery evidence required. |
| Worker/runtime/environment confusion | Payment grant is not queued. The known staging-worker production connection could affect document debits; web/API/webhook DB routing can independently misroute payments. | ENV-2 is not automatically the cause of pending payments. Preserve its historical contamination flag and inspect actual web routes/branches. |

The raw-byte HMAC and retry behavior above are checked against [Paystack's webhook documentation](https://paystack.com/docs/payments/webhooks/). Paystack retries non-200 responses, but an unknown-reference 200 is an acknowledgement. Provider verification must use transaction `data.status`, not the top-level API status. [Paystack verification documentation](https://paystack.com/docs/payments/verify-payments/)

## 5. Potentially affected historical users

**Unknown; no live purchase records or provider proof have been supplied.** Users fitting the race can see stale credits despite successful fulfillment; users with lost/misrouted webhooks, failed transaction/referral writes or workspace changes may have different symptoms. None can be counted or named from local tests.

The schema has `credit_purchases`, mutable aggregate `workspace_credits`, `trial_grants`, `referrals`, document consumption markers and general `audit_logs`. There is no credit movement ledger, per-purchase balance-before/after snapshot, webhook inbox, or independent fulfilled-credit marker. Source does not emit payment/credit/referral audit events through `AuditLogger`. The prepared queries inventory actual live audit actions before assuming none exist.

`documents_purchased_total` versus retained completed-purchase sums can flag aggregate inconsistencies independently of consumption. It cannot assign a deficit to one purchase. Current remaining balance cannot prove missed fulfillment: trials have no stored grant amount, zero-balance Ready completions and migration backfills are marked without equivalent debits, hard deletion may remove usage records, and referrer-account deletion can cascade referral history. A rewarded referral's current workspace balance likewise cannot independently prove whether its reward was ever granted.

Use [owner-run read-only evidence steps](PAYMENT_LIVE_EVIDENCE_2026_09_13.md) and [the SQL investigation](audit-evidence/2026-09-13/payment-read-only.sql). Queries are capped, enforce read-only transactions and omit sensitive provider/customer data. A pending row's stored success indication is a lead, not independent payment proof. Identical records on staging/production may be inherited from the 2026-09-11 fork; compare chronology and routing before attributing writes.

## 6. Smallest safe fix proposal — not applied

For the **demonstrated frontend race**, keep one pending-refresh flag when a refresh event arrives during an in-flight balance request, then issue one follow-up fetch when that request settles. Retain workspace/user scoping and abort handling; update the characterization test to require the banner's fresh 110 automatically. This requires no payment endpoint, crediting, referral, retry, schema or configuration change. It addresses stale display only, not a provider-paid pending purchase.

No backend business-logic fix is justified for the reported users until their failure category is established. First compare deployed SHAs: a deployment preceding `d05fdc1` lacks current return verification. If delivery is wrong, propose the exact destination/account/mode configuration correction separately. If a referral/DB exception blocked fulfillment, identify the concrete prerequisite failure; do not silently bypass rewards or create balances with guessed history. A durable reconciliation/inbox/credit-ledger design would be a separate reviewed change, not an inferred repair here.

## 7. Proposed historical reconciliation procedure — no repair script

**PRODUCTION CHANGE — explicit approval required.** No webhook replay, application verification POST, balance edit, status reset or bulk credit operation has been run or included in the read-only instructions.

1. Build a manifest for individually identified references: environment/branch, deployed revision, purchase ID, purchaser/original workspace, current workspace, amount/currency/document snapshot and independent Paystack transaction ID/mode/status/paid time. Exclude unrelated, reversed/refunded/disputed and simulated payments from a real-money cohort.
2. Establish whether value was already granted using retained events, lifetime purchase-counter reconciliation, known administrative adjustments and existing before/after database history. Record limits explicitly. If a per-purchase omission cannot be proved, keep it unresolved; current balance or a pending/completed label alone is insufficient.
3. Reconcile delivery/replays and fork inheritance. One provider reference must not become two blind repair candidates because it appears in both branches. Preserve all historical records.
4. Account for referrals: purchaser identity, prior completed purchases, eligibility, reward marker, actual destination and retained reward evidence. Legacy purchases with no reliable purchaser stay unattributed; do not invent a referrer entitlement or grant a second reward.
5. For a **proven unpaid-in-app pending purchase** on the correct deployed transactional code, prepare a per-reference operation that rechecks independent provider success and locks/rechecks state before using the existing completion service in a transaction. Simulate duplicate and concurrent execution locally first. Replays then exit after the committed completion. This route is not suitable for a corrupt completed marker or a known manual prior grant.
6. A proven inconsistent **completed** marker requires a separate narrowly scoped repair design with a durable unique repair record and reviewed exact missing mutation. Never set it back to pending or use present-day balance as the amount to add. The schema currently lacks an independent per-purchase repair marker; no idempotent completed-row repair is claimed ready.
7. Present the concrete manifest, before/after values, exact code/commands, idempotency proof and referral effects for explicit approval. Only the owner executes approved live changes. Collect post-commit read-only evidence and preserve the audit trail; no historical staging cleanup until branch attribution is proven.

## Local validation and limits

- Investigation tests are committed separately: backend `42414cd` on `audit/tier1-evidence-20260912`; frontend `8f59c53` on `audit/payment-lifecycle-20260913`. Both change tests only and preserve the application source baselines above. Neither was pushed or deployed by this session.
- Backend focused payment/referral suite: **69 tests, 502 assertions**, zero failures/errors/skips.
- Full backend: **270 tests, 1,301 assertions**, zero failures/errors/skips.
- Frontend focused return/banner suite: **7 tests passed**; full frontend: **14 tests passed**.
- New atomicity tests inject failures after purchase completion and after referral reward updates, verify complete rollback, then retry/replay successfully. Existing tests cover success through each route, both delivery orders, ambiguous initialization, early webhook, mismatches and duplicate delivery. Independent-process concurrency covers same purchase and distinct purchases/workspaces.
- The new frontend race test is explicitly a characterization of the **unfixed** stale-display defect. A passing characterization test is not proof of correct UI behavior.
- Tests and SQL validation use only disposable localhost PostgreSQL/Redis and mocked providers. No live Paystack transaction, request log, deployment, balance or environment check was performed. No production repair or application logic change was made.

Actual run totals and revision evidence are archived in [local investigation results](audit-evidence/2026-09-13/payment-lifecycle-test-results.txt). Return owner evidence before selecting any incident-specific backend change or repair.
