# Pre-B2 extraction stabilization

## Repo hygiene

Initial branch `feat/intelligence-v2-stage-a`, HEAD `19e31f9cb6b496b2d60fe35894813debe8f06abe`, clean worktree. No uncommitted files or experiment-flag changes in production files. Snapshot: `pre-b2/repo-hygiene.json`.

## Candidate Inventory V1

Standalone offline detector and matcher: `pre-b2/candidate_inventory.py`; tests: `pre-b2/test_candidate_inventory.py`. The 16 directly quantitative frozen gold items eligible for headline detection were found (16/16, 100%). Standalone years, bare counts, a ratio stated as “9 in 10,” and the rupee amount written without a symbol or code are outside headline V1. An AI coding agent reviewed 30 stratified headline detections against saved source context: 30/30 correct, 0 false positives. This small sample yields a rough precision estimate with wide uncertainty. Flattened chart regions are tagged and excluded from the headline denominator. Gold false negatives: 0; sample false-positive classes: none. Detector validation passes both specified thresholds.

The metric is **EXPLICIT QUANTITY REPRESENTATION**, calculated by class and saved accepted call. It is source-side numeric ownership; it says nothing about semantic completeness, importance, or user-facing quality. See `pre-b2/candidate-representation-summary.json` for numerators, denominators, occurrence ambiguity, and candidate-level diagnostics. Saved accepted replay records are used; rejected records cannot represent a candidate.

No provider generation calls, production changes, DB writes, push, merge, or deploy.

## Compact format token gate

The gate was fixed at **25% output-token reduction** before measuring. All 16 saved responses (912 record instances) were measured with `claude-haiku-4-5-20251001` through Anthropic's free count-only endpoint. Thirty-one count requests were made in total, including one rejected empty-content probe; the cap was 40. Four saved outputs calibrated against recorded `output_tokens` with adjusted errors from −0.06% to −0.02% after subtracting seven framing tokens. This is a reserialization estimate, not a paid output measurement. Anthropic describes count results as estimates. [Token-count documentation](https://platform.claude.com/docs/en/build-with-claude/token-counting).

| Encoding | Tokens | Tokens/record | Savings |
| --- | ---: | ---: | ---: |
| Saved canonical JSON | 118,778 | 130.24 | — |
| Readable keys, optional nulls omitted | 89,460 | 98.09 | 24.7% |
| Short keys, optional nulls omitted | 83,743 | 91.82 | **29.5%** |

Short keys pass the gate; `COMPACT_CODEC_WORTH_IMPLEMENTING=true`. Savings by chunk are 30.2% for the earlier UNICEF quantitative chunk, 35.0% for India, and 24.9% for reduced UNICEF. Estimated current reference text accounts for 14.4% of reserialized tokens. Empty-value schema scaffolding accounts for about 60.8%; omission of nulls saves about 29,318 tokens, while shortening keys beyond readable compact saves about 5,717. Field subtraction is non-additive. Evidence-kind and field-family details are in `pre-b2/compact-format-token-gate.json`.

The offline strict expander passed exact object round trips for all 912 records, malformed/fuzz cases, unicode/order/count, fresh-process determinism, and truncated compact-response salvage. The existing downstream PHP replay yielded identical acceptance, rejection diagnostics, typed projector output, provenance, identity, and key-figure eligibility on all 12 later-study cells after compact expansion. `EvidenceSchema::salvage()` currently looks for `"records"`; an eventual format switch must integrate the compact salvage path. The proposed schema has 10 optional properties and no unions, within Anthropic's documented 24/16 explicit limits; provider compilation was not attempted. [Structured-output limits](https://platform.claude.com/docs/en/build-with-claude/structured-outputs). Future storage must hash both raw compact and expanded canonical responses. No production format switch was made.

## Planner pressure and overflow

Across 16 comparable calls, returned/planner-pressure ratio: median **3.38×**, minimum 2.25×, maximum 7.98× (reduced UNICEF K3). Four calls exceeded the prompted `max_records`; returned/max_records ranged 0.30–1.37. Output/max_tokens ranged 0.18–0.85. None stopped for max-token truncation. The saved pressure estimate is a heuristic, not an enforced ceiling. `pre-b2/planner-pressure-calibration.json` records every call and freezes the future policy: unchanged max_tokens; max_records observational; preserve and log over-max returns; truncation, parser failure, or malformed structured output fails a cell; no replacement or retry without a new protocol; preserve failed artifacts and both capacity ratios. Removing materiality selection may increase volume, which alone is not failure. No planner change was made.

## Unit and currency prevalence

The zero-provider audit covers 262 accepted quantitative record instances from the later 12-call study. Of these, 237 have non-null `unit`, and 40 have a typed non-null currency. Citations contain 136 bare `$` cases, classified `SYMBOL_AMBIGUOUS`, with zero explicit currency codes or unambiguous symbols in this saved sample. All 30 key-figure-eligible instances cite bare `$`; a model-supplied USD is not counted as citation-supported USD. The existing projector/provenance outputs were recorded, not changed.

Three records use `unit=percent` for source wording “9 in 10,” with no explicit percent notation. This is 3/237 = **1.27%** of populated accepted quantitative records. Their saved typed amount is null, provenance is `document`, and none is key-figure eligible. No contradicted unit or currency was found in this lexical audit. The preregistered review gate (any unsupported key figure, or at least 2% unsupported non-ambiguous populated fields) is **not crossed**; decision: **DEFER**. Population aliases such as `people` for `children` and explicit compound units were classified separately. This is a lexical audit with source-context review where needed, not a full semantic proof.

## Paid prompt experiment: prepared, not run

Frozen request templates and hashes are in `pre-b2/`; only the two specified materiality-selection sentences are removed from `CONTROL_MINUS_MATERIALITY`. Model, sampling, max_tokens, chunks, schema, metadata discipline, validation, provenance, and current canonical wire format match Control. Five fresh runs per cell per chunk are planned in A1/B1 through A5/B5 interleaved order: 20 future generation calls. Earlier Control calls are not reused. The available saved sources all come from the same UNICEF report; no independent third-document source and blind gold set is frozen. With the two existing chunks, conclusions are **DIRECTIONAL ONLY**. Adding a third chunk requires freezing its source and evaluation protocol before any paid calls.

Proposed founder-approved ceilings: `MAX_TOTAL_STUDY_COST_USD=$1.50`, `MAX_COST_PER_DOCUMENT_CHUNK_PAIR=$0.15`, `MAX_COST_PER_ADDITIONAL_REPRESENTED_CANDIDATE=$0.10`, and `MAX_COST_PER_ADDITIONAL_GOLD_ITEM=$0.15`. Saved calls cost $0.018473–$0.058467 each. These are proposals requiring product approval before execution. A useful gain requires either +5 percentage points in mean headline explicit quantity representation or at least one additional gold item per chunk on average, with improvement in a majority of paired runs for each chunk. If neither holds: keep Control, freeze extraction, proceed to B2. Safety gates are at most 5-point precision, negative false-positive, and rejection deterioration; at most 2-point grounding-failure deterioration; zero variant-attributable truncation; and zero unexplained systematic parser or structured-output deterioration. Absolute cost and cost per useful gain govern spending, rather than a relative 25% cost increase alone. Full metrics and failed-cell policy are in `pre-b2/minimal-prompt-experiment-plan.json`.

## Version freeze and B2 readiness

`pre-b2/version-freeze-plan.json` fixes the model, prompt versions and hashes, schema version/hash, canonical wire version, detector/matcher versions, planner/config hash, complete request-body hashes, source hashes, and span-set hashes for each planned cell. Future artifacts must carry this contract and must not pool incompatible hashes. The compact schema and codec have separate offline status; no production version semantics were redesigned.

**MUST_FIX_BEFORE_B2:** none outstanding from this task. Detector validation, reproducible representation, overflow policy, unit/currency decision, version identifiers, and paid-study preparation have been recorded. Founder approval is required to *run* the paid study, as specified, and is not a production change in this task.

**LATER_IMPROVEMENT:** compact production activation; adaptive routing; AI document classifier; field-role provenance; context citation; structure-aware segmentation; residual gap-fill; prompt caching; batch API; semantic deduplication; null-period identity. None is promoted to a B2 blocker by these measurements.

**Decision: READY_FOR_PAID_PROMPT_EXPERIMENT**, subject to founder approval of its absolute budget and with two-chunk conclusions classified as directional. Provider generation calls: **0**. Production behavior/prompt changes: **0**. Candidate Inventory DB writes: **0**. No push, merge, or deploy.
