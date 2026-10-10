# DocIntel Phase 2A.1 runtime completion — partial

Date: 2026-10-11. Base repository HEAD: `3082b4b`. Phase 2A changes were already present as working-tree changes and remain intact. This continuation makes no paid provider call, deployment, push, merge, grounding change or verifier-version change.

## Spend reservation design and transaction semantics

An additive `provider_call_attempts` table is tied to the existing `operation_quotes` row. It is an in-flight cost record for a quote, not another customer-credit ledger. A unique UUID identifies each local provider dispatch attempt. Reserved and settled amounts are integer micro-USD. `OperationSpend::reserveAttempt` locks the quote row, counts historical run spend plus active/settled attempts, and inserts only when `spent + new estimate <= immutable quote cap`. Two workers therefore serialize on the same quote. A duplicate UUID is rejected. The existing workspace credit ledger remains the sole source for customer credit debit/release.

The state path is `reserved → dispatched → settled` when usage is measurable, or `reserved → dispatched → uncertain` when the request may have executed but usage is unavailable. A pre-dispatch failure changes `reserved → released`. Invalid negative or non-finite reported usage stays uncertain, never free. `finishAttempt` and `releaseAttempt` are idempotent and cannot reverse a finalized state. `markDispatched` locks the quote again and refuses a released quote. The common Anthropic client reserves before its provider gate and marks dispatch immediately before the HTTP call. Every transport retry re-enters model, budget, quote, document-state and attempt admission with a new UUID. A second document-state read occurs immediately before dispatch.

Historical `document_ai_runs` continue to count toward quote spend. New run rows carry `provider_attempt_id` and are excluded from the historical sum so their cost is counted once through the attempt record. A dispatched request with no measurable usage retains its full estimated hold. A successful response with reported usage settles the local attempt to the provider-priced usage; a late duplicate finalizer cannot rewrite it.

## Request identity and exactly-once scope

The local attempt UUID authorizes at most one intentional HTTP dispatch from that attempt. Existing document, quote, chunk dispatch token, synthesis checkpoint and run identities still guard their respective job paths. A transport retry is a distinct possibly billable attempt, with a distinct reservation. No unsupported provider idempotency header was invented. The application can guarantee atomic local admission and idempotent ledger effects; it cannot guarantee exactly-once execution across a network failure. An ambiguous outcome is recorded as uncertain and keeps its hold.

## Parser and hostile-file status

The Phase 2A ZIP/PDF/workbook/image preflights remain in place and their focused tests pass. A new synthetic malformed XLSX fixture exposed a PhpSpreadsheet `RuntimeException` containing an internal ZIP path; `SpreadsheetTextExtractor` now preserves only its own three exact resource-limit messages and maps parser exceptions to `Invalid workbook.`. DOCX XML, Smalot PDF and PhpSpreadsheet XLSX still parse in the long-lived PHP worker. No portable, deployment-verified isolated parser subprocess with hard memory, time and output ceilings exists yet, so process isolation was **not** claimed or improvised. Existing tests cover malformed archives/workbooks, entry/expansion/ratio ceilings, malformed PDF, page limit, DOCX external entities, XLSX cell limit and formula non-evaluation. Controlled external-link non-fetching, encrypted PDF, extreme worksheet dimensions, huge image dimensions and parser crash recovery still need dedicated fixtures.

## Terminal and ledger race results

Independent PostgreSQL worker processes competed for one quote cap: only one 70%-cap reservation succeeded; both 30%-cap reservations succeeded. Settlement racing with release on a dispatched attempt produced one settled charge. Direct tests show duplicate settle/release, settle after release, release after settle and stale quote dispatch all leave one authoritative result. A fake 503 response after dispatch leaves an uncertain provider-cost hold. The existing Phase 2A terminal-state test continues to block late scan, extraction, OCR, B2 and provider work for Failed and Needs Review documents. Existing credit ledger concurrency tests still pass.

## Safety and usefulness results

| Measure | Local result | Limit |
|---|---|---|
| Two calls oversubscribing one quote | 0 admitted in two-worker test | Tests cover one PostgreSQL quote; no live traffic sample |
| Duplicate local attempt/ledger effects | 0 in direct lifecycle and worker-race tests | Provider-side network execution is not exactly once |
| Paid call after terminal state | 0 in focused fake-HTTP tests | A status change between final check and socket send remains a narrow race |
| Hostile parser escaping resource isolation | Not measured | Parser isolation is still open |
| External resource fetch | Not measured | Controlled hostile XLSX fixture remains open |
| Affordable concurrent calls falsely refused | 0 in two-worker 30%-cap case | Larger legitimate workload distribution unmeasured |
| Valid files falsely refused | No new file policy; prior normal fixtures pass | No large-file corpus or isolated parser compatibility study |
| Resumable jobs terminalized | 0 in focused Intelligence/Incremental tests | No production-state replay performed |
| Valid transport retries suppressed | Existing retry/admission suites pass | Network ambiguity can consume reserved capacity by policy |

## Verification

- New `ProviderSpendReservationTest`: 7 tests, 28 assertions, passed.
- PostgreSQL `WorkspaceAiCreditsConcurrencyTest`: 7 tests, 53 assertions, passed; it includes three new provider-attempt races.
- Combined focused provider, state, Intelligence, Incremental, resource and AI-credit suites: **191 tests, 1,165 assertions, passed**.
- Malformed workbook and Phase 2A resource suite: 4 tests, 12 assertions, passed after the error-sanitization fix.
- Pint on changed Phase 2A.1 PHP files: passed, including the workbook-sanitization edit.
- Full backend suite: not yet run for this continuation.
- `git diff --check`: passed.

## Remaining limitations and next actions

1. Quote-backed calls have atomic cumulative admission. Legacy or otherwise unquoted calls still use the Phase 2A per-call/document checks and existing job budgets, so the universal cumulative-spend contract remains incomplete.
2. An actual provider usage total may exceed a conservative local estimate; the local quote can then exceed its cap by the variance of that one call. Image-token reserve accuracy and worst-case pricing need a measured upper-bound policy.
3. A duplicate job with a new local UUID can still intentionally create another attempt unless its existing stage/chunk checkpoint blocks it. A durable logical-request identity shared by every caller is not yet universal.
4. A process killed after `markDispatched` leaves a held attempt without a final run. A stale-attempt reconciliation policy must treat it as uncertain, never automatically free; no scheduled reconciler was added here.
5. Parser process isolation and the remaining adversarial file fixtures are open. Implement the isolation with a deployment-tested CLI/container contract and bounded IPC before asserting worker-crash containment.

These limits keep Phase 2A.1 **partial**. The next slice should finish attempt reconciliation and unquoted-call policy, then parser isolation and controlled hostile-file fixtures, followed by a full backend run.
