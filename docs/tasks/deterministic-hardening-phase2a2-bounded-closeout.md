# DocIntel Phase 2A.2 bounded runtime closeout

Date: 2026-10-11. Base ancestry: `dc259c0`. The accepted green baseline repair and strict OperationQuote scope binding are preserved. No live provider request, deployment, push or merge was made. Phase 2A.2 remains partial; this report covers only the bounded runtime slice requested before Phase 2B.

## Request maximum and quote-cap invariant

Every Anthropic generation attempt passes `ProviderRequestAdmission` before a reservation or HTTP. The admitted model must match its task role and approved list, have all pricing entries, and satisfy configured output, context, per-call and document limits. The maximum hold in micro-USD is:

`ceil(input_bound_tokens × applicable_max_input_rate + configured_max_output_tokens × output_rate)`

Rates are USD per million tokens. `input_bound_tokens` is the UTF-8 byte count of the serialized bounded request messages, system content and output schema, plus 512 envelope tokens and the configured visual-token reserve per image. Base64 image bytes are omitted from the text count; their visual tokens are counted separately. Image count is capped at one by default, and the configured visual reserve must be at least the approved model's configured image-token ceiling. Cache creation/read rates participate only when a request structurally contains `cache_control`; the highest applicable input rate applies to all input tokens. The request must also fit the configured context window. The default visual reserve is 6,000 tokens; the per-model image ceiling is 1,568 or 4,784 tokens under the model's tier (see [Anthropic vision limits](https://platform.claude.com/docs/en/build-with-claude/vision)). These values are configuration, not document facts.

Before this slice, the admission estimate already used configured maximum output tokens and a cache-write rate, but counted only content text plus a flat allowance and did not bound the number of images. It also lacked a cumulative hold for unquoted calls. The new bound includes serialized request structure, explicit image count/ceiling and applicable cache pricing. It may be lower for uncached requests because cache-write pricing is then inapplicable. The reserve is based on the application's bounded request, not historical average output or the full model context window.

`OperationSpend::reserveAttempt` locks the quote row, then admits only if `historical unsettled/known spend + active reserved maxima + new maximum <= floor(immutable quote cap in micro-USD)`. A measured response settles its actual token cost and implicitly releases the unused part. An ambiguous dispatched attempt stays `uncertain` with the full maximum. A pre-HTTP failure releases the hold. State transitions are idempotent and one-way. Each retry re-enters admission with a new attempt and hold. The provider's network execution is not claimed exactly once; the application guarantees one local dispatch transition per attempt.

Focused test example: with a 400-output-token Haiku fixture, the calculated maximum is reserved in full; a measured half-maximum settles at that lower amount and frees the difference. A second measured response at the full maximum settles exactly at the hold. The quoted cumulative cap test and competing-worker quote-lock tests remain green. The application relies on its validated token ceilings and billed usage conforming to the provider request contract. An unexpected provider billing dimension beyond those request controls would require a new admission rule; this slice does not claim protection against undocumented provider charges.

## Unquoted Anthropic paths

The existing `provider_call_attempts` table now also owns internal provider-cost attempts. An additive migration gives it optional workspace, scope key and snapshotted cap columns, makes the quote FK nullable, and adds a check requiring exactly one owner: customer quote or internal cost scope. The workspace row is locked before every internal sum/insert; simultaneous workers therefore cannot spend the same remaining cap. Historical unquoted Anthropic `document_ai_runs` without attempt IDs count toward the scope. Internal scopes never create an `OperationQuote`, `billing_operation` or customer `credit_ledger` entry.

| Production path | Disposition | Internal scope when no quote |
|---|---|---|
| Incremental and legacy/off-mode document intelligence | Bounded | `document:{document_id}` lifetime |
| Reanalysis | Bounded | Same document lifetime scope |
| OCR and chart vision | Bounded | Same document lifetime scope |
| Legacy/off-mode AI comparison | Bounded | `comparison:{comparison_id}`; job supplies comparison identity |
| Zero-credit/free Q&A generation | Bounded without customer debit | `qa:{workspace_id}:{day}`, capped by `ai_credits.qa.max_cost_usd_per_workspace_per_day` |
| Metered Q&A and modern paid document/comparison/OCR | Existing strict quote boundary | Matching customer OperationQuote |
| Direct generation with no document/workspace identity | Disabled before HTTP | `provider_scope_unavailable` |
| Anthropic token counting and model access checks | No generation charge in current inventory | Outside message-attempt ledger |

The document lifetime scope deliberately aggregates OCR, analysis and reanalysis under one internal cap. A repeat reanalysis can be refused once that cap is exhausted; this conservative false refusal should be reviewed if product policy later requires separate reanalysis budgets. The Q&A day boundary uses application time; long-running work at midnight follows the day on which admission occurred. The existing attempt ID is unique per HTTP attempt, but universal durable logical request identity and stale-attempt reconciliation remain deferred.

Customer-credit separation is tested: zero-credit Q&A and unquoted competing workers produce internal attempts while creating no customer quote or credit-ledger debit. Existing credit settlement/release services were not changed. Strict quote kind/resource/workspace validation remains before HTTP, so comparison cannot borrow a document quote. Existing exactly-once credit tests still pass.

## Voyage privacy

Failed Voyage embedding calls log provider, operation, allowlisted model string, HTTP status, typed failure code, sanitized request ID and duration. They no longer log the upstream response body. The public `EmbeddingProviderException` carries no response text. A fake-response regression includes query/document text and a fake API key in the upstream body and confirms they are absent from the log context. Voyage cost reservation, including query/search embeddings, is deferred.

## Verification and metrics

- Maximum reservation, concurrent quote/internal holds, credit finalization, provider admission and Voyage privacy: **25 passed, 122 assertions**.
- Existing provider gate, KPI, Q&A, billing, synthesis fallback, incremental pipeline, B2 narrative/verifier and baseline invariant suites: **297 passed, 1,843 assertions**.
- Two direct Anthropic unit fixtures initially failed in the full suite because they invoked a paid path without a document. They now supply normal document/workspace context. Their focused rerun: **13 passed, 45 assertions**. The production boundary was not relaxed.
- Full backend suite after fixture repair: **1,296 tests; 1,295 passed; 9,429 assertions; zero failures/errors; one skipped**. The first full run found the seven fixture failures above; the complete rerun was green.
- Pint on changed PHP files: passed; three files were formatted. `git diff --check`: passed.

Safety observed in tests: **0** quote-cap overshoots admitted, **0** ordinary unquoted Anthropic HTTP sends without a bounded scope, **0** wrong-quote HTTP sends, **0** double settlements, **0** double customer debits, and **0** sensitive Voyage response bodies logged. Usefulness observed: valid quoted calls and free Q&A still proceed; measured settlement releases excess maximum; two concurrent unquoted calls both proceed when the cap permits; no common supported file type was disabled. Direct scope-free generation is intentionally disabled. These are test observations, not production traffic measurements.

## Migration and rollout

New migration: `database/migrations/2026_10_11_000002_allow_internal_provider_spend_scopes.php`. Run it **before** deploying the new code. Its nullable additions let old quote-backed workers write during migration, but old unquoted workers do not enforce the new cap and should be drained before enabling the new code. The reverse migration refuses to discard existing internal attempt records. No production migration was run in this session. Full test migration setup and existing isolated PostgreSQL migration-chain tests passed in the full suite.

## Remaining Phase 2A.2 debt and Phase 2B decision

Deferred by scope: universal durable `logical_request_id`, stale uncertain-attempt reconciliation, full Voyage model/pricing/cumulative admission, parser subprocess isolation, remaining hostile-file adversarial fixture expansion, and broader provider-spend-scope architecture. Voyage remains a paid path outside cumulative provider-cost accounting; that risk is explicit and should have its own bounded admission before scaling embedding/search traffic. Neither parser isolation nor adversarial fixture completion is represented as done.

**Recommendation:** Phase 2B semantic work can begin in an isolated slice while the runtime debt is tracked, because ordinary Anthropic generation now has bounded cumulative admission and the full backend suite is green. Do not declare Phase 2A.2 complete; prioritize Voyage cost accounting and stale-attempt reconciliation before increasing provider traffic or concurrency.
