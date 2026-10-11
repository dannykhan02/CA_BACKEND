# DocIntel Phase 2A.2 baseline regression repair

Base: `dc259c0` (clean `main` before this repair), 2026-10-11. Tests use an isolated local PostgreSQL 18 database and faked Anthropic HTTP. No paid provider call, deployment, push or merge occurred.

## Reproduced baseline

The first test invocation was invalid because the newly initialized local database lacked the `postgres` role expected by `.env.testing`. After correcting that local test harness, the full suite reproduced **1,284 tests; 1,265 passed; 9,283 assertions; 13 assertion failures and 5 errors**. One test was skipped. This is 18 failing cases, matching the reported pushed baseline; the reported pass/assertion counts differ by one, so the local figures below are the measured ones.

Classification: A = application regression from Phase 2A/2A.1; B = stale fixture/config; C = migration-order test harness; D = approved semantic change; E = unrelated pre-existing failure.

| Failing test | Class | Root cause and repair | Changed | Safety preserved |
|---|---|---|---|---|
| `FinalAuditInvariantsTest::test_ai_credit_migration_is_additive_defaulted_and_reversible` | C | Test called the older credit migration's `down()` while the newer provider-attempt FK still depended on `operation_quotes`. Test now rolls back attempts first, then credits, and reapplies credits before attempts, asserting both tables and columns. | Test | Real FK remains; no cascade or weakened constraint. |
| `KpiIdentityTest::test_learned_semantic_alias_avoids_repeated_ai_and_audits_token_usage` | A | Generic intelligence stage required extracted text although KPI adjudication uses bounded structured observations. Added a dedicated `kpi_identity` stage allowing Processing/Ready without raw text. | Code | Terminal states, model/price, per-call limits and quote admission still apply. |
| `KpiIdentityTest::test_provider_failure_keeps_observation_separate_and_does_not_retry` | A | Same incorrect generic stage gate prevented the intended one-attempt provider failure path. | Code | Failure still keeps KPI identities separate; no extra retries. |
| `ModelRoutingGuardTest::test_an_env_override_to_another_model_is_reported_not_silently_used` | A | Early hardened rejection removed the previous sanitized warning. Admission now logs only shaped task/model/document ID before throwing. | Code | No prompt, source, key or provider response logged; HTTP remains blocked. |
| `ProviderGateTest::test_provider_retry_reacquires_a_permit_per_http_attempt` | B | Private boundary fixture supplied no task. Test now declares the production `entities` task and Processing document. | Test | Admission still rejects taskless calls. |
| `ProviderGateTest::test_ocr_vision_calls_are_gated_and_a_busy_batch_defers_instead_of_failing` | B | Fixture was not an image, so image preflight correctly rejected before gate. Test now uses a controlled one-pixel PNG. | Test | Image preflight remains active. |
| `SynthesisFallbackAndThroughputTest::test_timeout_on_full_context_then_success_on_reduced_context` | B | Setup chose unapproved Sonnet 4.6. Uses approved Sonnet 5.5. | Test | Model admission remains strict. |
| `SynthesisFallbackAndThroughputTest::test_two_timeouts_then_success_with_evidence_excerpts_only` | B | Same stale synthesis model. | Test | Fallback semantics unchanged. |
| `SynthesisFallbackAndThroughputTest::test_evidence_only_fallback_succeeds_after_three_timeouts` | B | Same stale synthesis model. | Test | Bounded retries unchanged. |
| `SynthesisFallbackAndThroughputTest::test_all_fallbacks_fail_with_a_short_accurate_reason` | B | Same stale synthesis model masked timeout exhaustion. | Test | Error classification unchanged. |
| `SynthesisFallbackAndThroughputTest::test_deterministic_failures_never_descend_the_ladder` | B | Same stale synthesis model masked provider 401/404. | Test | Deterministic failures still do not retry. |
| `SynthesisFallbackAndThroughputTest::test_timeout_with_unknown_usage_keeps_synthesis_bound_but_not_unsent_repair` | B | Exact reserve expected the obsolete Sonnet price. Approved model's configured price now drives the same accounting invariant. | Test | Unknown usage remains conservatively charged. |
| `SynthesisFallbackAndThroughputTest::test_budget_degrades_before_spending_and_never_exceeds_the_cap` | B | Same stale model prevented budget path. | Test | Budget guard unchanged. |
| `SynthesisFallbackAndThroughputTest::test_timeout_then_insufficient_budget_names_both_causes` | B | Same stale model prevented timeout/budget path. | Test | Failure taxonomy unchanged. |
| `SynthesisFallbackAndThroughputTest::test_duplicate_synthesis_delivery_neither_calls_nor_bills_twice_and_debits_once` | B | Same stale model prevented first successful delivery. | Test | Idempotent synthesis and ledger checks unchanged. |
| `SynthesisFallbackAndThroughputTest::test_configured_synthesis_model_drives_request_pricing_and_diagnostics` | B | Fixture and explicit model/cost expectations named Sonnet 4.6. They now use approved Sonnet 5.5 and its configured rates. | Test | Pricing remains config-derived; no alternate model fallback. |
| `SynthesisFallbackAndThroughputTest::test_partial_extraction_with_failed_leaves_still_merges_and_synthesizes` | B | Same stale synthesis model prevented the synthesis leg. | Test | Partial extraction semantics unchanged. |
| `SynthesisFallbackAndThroughputTest::test_worker_interruption_then_reanalysis_restarts_the_ladder_cleanly` | B | Same stale synthesis model prevented the expected synthesis checkpoint. | Test | Retry and checkpoint behavior unchanged. |

## Focused proof

- Migration rollback/reapply test: 1 test, 22 assertions, passed.
- KPI identity suite: 18 tests, 86 assertions, passed after the code fix. Added a paid-path test: unquoted credit-mode adjudication sends zero HTTP; a reserved document quote permits one call and records one settled provider attempt (1 test, 5 assertions, passed).
- Model-routing guard: 3 tests, 41 assertions, passed.
- Provider gate: 20 tests, 101 assertions, passed.
- Synthesis fallback/throughput: 21 tests, 126 assertions, passed.

## Baseline after repair

The post-repair full backend suite passed: **1,285 tests, 1,284 passed, 9,367 assertions, zero failures/errors, one skipped**. The extra test is the quote-backed KPI paid-path regression above.

Pint on all changed PHP files passed; `git diff --check` passed. No verifier, prompt, extraction, grounding or provider budget semantics changed.

The migration-chain check used a separate single-database PostgreSQL 18 cluster. It exposed two older rollback-only defects after the Phase 2A.1 dependency had rolled back correctly: the Power BI group role still had narrow view/schema grants, and `enum()->change()` in the first document-type widening migration generated invalid PostgreSQL `ALTER COLUMN` SQL. Their `down()` methods now revoke only the remaining grants before dropping the role and restore the document type as `VARCHAR(255)` plus the existing explicit check. These are **E: unrelated historical rollback defects**; `up()` and production grants are unchanged. A fresh full-chain `migrate --force` followed by `migrate:reset --force` passed in that isolated cluster, without `DROP CASCADE`.

BASELINE_AFTER_REPAIR:
tests: 1285
assertions: 9367
failures: 0
