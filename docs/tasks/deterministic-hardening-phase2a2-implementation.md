# DocIntel Phase 2A.2 implementation status — bounded runtime closeout

Date: 2026-10-11. Base HEAD `dc259c0`; the accepted Phase 2A.2 baseline repair remains in the working tree. No live provider calls, deployment, push or merge occurred. The accepted [paid-call inventory](deterministic-hardening-phase2a2-paid-call-inventory.md) is the starting trace.

## A. Implemented: strict quote scope binding

`OperationSpend::assertCallAllowed` now resolves and returns the quote actually authorized for the request. An explicit quote must be reserved or settled and match all of:

- task kind: OCR → `ocr`, comparison → `comparison`, Q&A → `qa`, document intelligence → `document` or `reanalysis`;
- operation resource: document ID, comparison ID, or Q&A request resource ID;
- workspace: document workspace or the authenticated Q&A workspace passed by the controller.

An invalid scope fails locally as `quote_scope_mismatch` before any HTTP attempt. In credit-mode comparison, a missing comparison quote fails as `credits_not_reserved`; it cannot fall back to the base document's quote. Metered Q&A also requires its own quote, including when a direct caller omits workspace context. An existing legacy comparison reservation is still eligible when its comparison resource and workspace match; the preserved unknown-legacy-access case is also retained. Free Q&A remains usable, but its run is no longer misattributed to a cited document's quote. Automatic document-quote selection filters by workspace as well as resource and kind. `AnthropicClient` uses the one resolved quote ID for reservation and run attribution, so a second `activeQuote` lookup cannot select a different owner between admission and recording.

The controller supplies the authenticated workspace and Q&A quote resource ID to the client. The existing customer-credit ledger, quote pricing, B1/B2 grounding, verifier versions and prompt versions are unchanged. An old test intentionally passed a comparison quote to an OCR request; it now sends a genuine comparison request while preserving the separate OCR/comparison spend assertions.

## B. Provider spend-scope design: proof before a new table

The existing tables **cannot directly represent** an unquoted paid in-flight cost:

| Existing structure | Why it cannot be the unquoted cost owner as currently defined |
|---|---|
| `operation_quotes` | A customer-facing, immutable credit price with `credits`, funding bucket and quote status. Making a zero-credit fake quote for legacy/free work would conflate provider cost with customer entitlement and could change billing behavior. Rejected by the review decision. |
| `provider_call_attempts` | Correct reserve/dispatch/settle lifecycle, but `operation_quote_id` is a non-null FK and the quote row supplies its lock and cap. It cannot insert an unquoted attempt today. |
| `document_ai_runs` | Written after a call/response, nullable cost on unknown usage, and no atomic pre-HTTP reservation or scope cap. It can contribute historical spend, but cannot prevent two concurrent calls from both passing. |
| `document_chunks.reserved_cost` | Covers incremental pipeline units only. Legacy document stages, OCR, comparison, free Q&A and Voyage query/search do not all own chunks. Reusing it would distort the extraction/synthesis budget. |
| `billing_operations` and `credit_ledger` | Customer allowance/debit state; they have no general provider-attempt hold and are intentionally absent for free Q&A. Reusing them would corrupt entitlement semantics. |

**A new provider-spend table is not yet justified.** The narrower candidate is to extend the existing `provider_call_attempts` table with an optional internal scope key, workspace ID, and snapshotted cap while making its quote FK nullable for unquoted attempts. Lock the existing workspace row before summing/inserting attempts for an internal scope; quote-backed attempts retain the quote-row lock. That uses the established attempt lifecycle and does not manufacture customer quotes or create another ledger.

The bounded closeout implements this narrow extension in additive migration `2026_10_11_000002_allow_internal_provider_spend_scopes.php`. The existing attempt table remains the provider-cost ledger. Workspace-row locking serializes internal reservations; quote-row locking still serializes quoted reservations. Internal scope keys cover a document lifetime (including OCR/reanalysis), a comparison ID, or one workspace/day for free Q&A. Historical unquoted Anthropic run costs without attempt IDs count toward the cap. These scopes create no customer quote, billing operation or credit-ledger entry. The conservative document-lifetime scope can eventually refuse a legitimate reanalysis; that limitation is recorded in the [bounded closeout](deterministic-hardening-phase2a2-bounded-closeout.md).

## C. Bounded closeout

`ProviderRequestAdmission` now reserves a maximum derived from request bytes, configured output tokens, model-specific visual-token allowance, applicable cache-write pricing and the selected model's approved rates. The quote or internal scope atomically holds that maximum before HTTP. Measured usage settles at actual cost and frees the difference; ambiguous dispatched attempts retain the full maximum. Every production Anthropic generation request in the current client passes through this boundary, including retries. Voyage error logging no longer records upstream response bodies.

The new migration adds nullable internal-scope columns to `provider_call_attempts` and relaxes its quote FK only for internal ownership; a database check requires exactly one cost owner. It must run before new code. Old workers can still write quoted attempts after migration, but should be drained before enabling the new code so old unquoted calls cannot bypass the cap during rollout. The migration is additive and has a guarded reverse path that refuses to discard live internal attempts.

## D–G. Still open

1. Durable logical request identity across duplicate jobs, bounded retries and process death; stale dispatched-attempt reconciliation must retain uncertain spend.
2. Minimal bounded Voyage price/model/size/cost admission, including query/search. Its response-body logging is fixed.
3. Parser process isolation and remaining hostile-file fixtures from Phase 2A.1.

Phase 2A.2 remains **partial** because Voyage cost accounting, stale-attempt reconciliation, parser isolation and hostile-file fixture expansion are deferred. Ordinary production Anthropic generation has cumulative admission for quoted and unquoted paths; direct calls without a stable cost scope fail before HTTP.

## Verification

The [bounded closeout report](deterministic-hardening-phase2a2-bounded-closeout.md) records focused results and the full backend result: **1,296 tests, 1,295 passed, 9,429 assertions, zero failures/errors and one skip**. Pint and `git diff --check` passed. B1/B2 grounding, verifier and prompt versions remain unchanged. Phase 2A.2 is still partial because the explicitly deferred runtime items remain.
