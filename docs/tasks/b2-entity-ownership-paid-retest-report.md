# B2 Entity Ownership V2 fresh paid retest

Date: 2026-10-10. This supersedes the stale-local-ref preflight stop previously at this path. That attempt made **0/6** paid calls. B2 remains off in production.

## Checkout, merge proof, and protocol

- Worktree `/home/collins/boys/CA_BACKEND`; branch `main`; starting HEAD, local `main`, and local `origin/main`: `533e76a9b2c9d1964af641cd505f019e63e1353c`. Starting status: only the untracked, stale preflight report. PR #42 is the merge commit; `98cd7f5` and `1e3bf59` are in its history.
- Main contains `RecordOwnedMentionProjector.php`, ownership adversarial and replay tests, and B1/B2 verifier version `2`. Ownership/verifier tests passed **20/20, 102 assertions**. Pre-paid safety gates passed **96/96, 510 assertions**: Core Resources, Swachh Bharat, Jal Jeevan, F19-equivalent, wrong program, cross-record entity, injected/capitalized text, partial/residual claims, wrong currency/scale/year/entity, GET zero provider/dispatch, B1 direct/API equality, one Stage A projection per GET, rejected-text isolation, and in-progress/timeout/failed fallback.
- Frozen from the [prior report](b2-post-grounding-rerun-report.md): prompt version `1`, prompt SHA-256 `b970235062f3f7dec8f923dc59c341d1a0da4b80e8a6438afe2842b2de71ce84`, contract version `1`, model `claude-sonnet-5-5`, effort `medium`, max output `1500`, HTTP timeout 60 seconds, connect timeout 10 seconds, `temperature`/`top_p`/`top_k` unset/provider default, `max_rejected_claims=0`. V2 changed only the B1/B2 verifier versions to `2`; B2 input hash includes that version. Per-call harness stop $0.034; per-document budget $0.05; total cap $0.25.
- Six fresh local Document rows used the same two C1 accepted extraction sets through production `EvidenceMerger`: three `india_wash` (30 canonical records, 15 supplied, one key figure each) and three `unicef_reduced` (41 canonical, 18 supplied, six key figures each). All identities and hashes were distinct, had no prior B2 unit or AI run, and quoted below the per-call limits. The [seed](b2-entity-ownership-paid-retest-evidence/seed.json) and [preflight](b2-entity-ownership-paid-retest-evidence/preflight.json) preserve document IDs, hashes, supplied IDs, key-figure IDs, and quotes.
- The old isolated PostgreSQL cluster could not restart due to stale shared memory. A new isolated PostgreSQL 18 cluster on localhost port 55434 and database `docintel_entity_retest` was used. Every PHP command overrode the repo `.env` remote DB settings. No remote or production DB was accessed.

## Six fresh calls and cost

Each response was saved on arrival, then its verdict and cost audit were saved before the next call. Every synthesis identity has one provider request, one B2 unit, one AI run, and settled usage. No old paid call was repeated.

| Call | Set | Result | Input tokens | Output tokens | Quote USD | Actual USD | Quote/actual |
| --- | --- | --- | ---: | ---: | ---: | ---: | ---: |
| 1 | india_wash | rejected | 2,114 | 357 | 0.022658 | 0.010166 | 2.229 |
| 2 | india_wash | verified | 2,116 | 433 | 0.022658 | 0.008751 | 2.589 |
| 3 | india_wash | rejected | 2,114 | 471 | 0.022658 | 0.009127 | 2.483 |
| 4 | unicef_reduced | rejected | 2,897 | 416 | 0.023995 | 0.010143 | 2.366 |
| 5 | unicef_reduced | rejected | 2,898 | 387 | 0.023995 | 0.009855 | 2.435 |
| 6 | unicef_reduced | rejected | 2,900 | 394 | 0.023995 | 0.009929 | 2.417 |

Total actual spend **$0.057971**, with 15,039 input and 2,458 output tokens; total reserved **$0.139959**. Actual cost min $0.008751, max $0.010166, mean $0.009662, median $0.009892. Quote/actual ratio min 2.229, max 2.589, mean 2.420, median 2.426. All ceilings held. The [call audit](b2-entity-ownership-paid-retest-evidence/calls.json) contains all document, synthesis, unit, verifier, usage, and cost fields. Raw responses: [1](b2-entity-ownership-paid-retest-evidence/raw-response-0.json), [2](b2-entity-ownership-paid-retest-evidence/raw-response-1.json), [3](b2-entity-ownership-paid-retest-evidence/raw-response-2.json), [4](b2-entity-ownership-paid-retest-evidence/raw-response-3.json), [5](b2-entity-ownership-paid-retest-evidence/raw-response-4.json), [6](b2-entity-ownership-paid-retest-evidence/raw-response-5.json).

## Independent claim review and whole narratives

All **36/36** claims were reviewed using a [blinded packet](b2-entity-ownership-paid-retest-evidence/review-packet.json) containing claim text, cited canonical fields, source passages, and Stage A context, without verifier verdicts or reasons. [Judgments](b2-entity-ownership-paid-retest-evidence/review-judgments.json) were [frozen](b2-entity-ownership-paid-retest-evidence/review-freeze.json) at SHA-256 `9e9eb3e9b91e8a41cfe9c913b950cefe416da5df1757104c41071f5f0608d5e6` before comparison with the [sealed key](b2-entity-ownership-paid-retest-evidence/review-key-sealed.json). The [unblinding](b2-entity-ownership-paid-retest-evidence/unblinded.json) records all 36 independent labels and verifier results.

| Verifier | Supported | Partial | Unsupported | Ambiguous | Total |
| --- | ---: | ---: | ---: | ---: | ---: |
| Accepted | 25 | 0 | 0 | 0 | 25 |
| Rejected | 5 | 6 | 0 | 0 | 11 |

Accepted supported IDs: F01, F02, F04–F06, F09–F14, F17, F18, F20–F23, F25, F26, F30, F33, F37, F41, F42, F46. Rejected partial IDs: F27, F29, F34, F36, F43, F45. Rejected supported IDs: F03, F19, F28, F35, F44. No partial, unsupported, or ambiguous claim was accepted. Three partial claims conflated the 46% private-sector share of Core Resources with its share of total income; three asserted an unquoted 5% Other Resources Emergency figure.

Current whole-narrative admission: **1/6 verified, 5/6 rejected**, versus **0/6** in the prior fresh paid sample. The zero-cost 2/6 replay was an in-sample estimate, not a target. The threshold stayed `max_rejected_claims=0`. This n=6 sample is not a stable population rate.

### Supported rejections

| Claim | Cause | Concrete reason |
| --- | --- | --- |
| F03 | ENTITY_OWNERSHIP_RECALL_GAP | The toilet metric label names Swachh Bharat Mission, but its quote only says “110 million toilets constructed”; V2 correctly requires same-record quoted program relation. |
| F19 | ENTITY_OWNERSHIP_RECALL_GAP | The same metric has Government of India as subject and Swachh Bharat in its label, but the quote lacks the program relation. The F19-equivalent remains rejected. |
| F28 | UNSUPPORTED_KEY_FIGURE | The cited Core Resources partner figures are grounded metrics but outside the frozen six selected key figures. |
| F35 | UNSUPPORTED_KEY_FIGURE | The private/public/other partner breakdown is source-grounded but outside the key-figure selection. |
| F44 | UNSUPPORTED_KEY_FIGURE | The two cited partner figures are source-grounded but not selected key figures. |

F03/F19 were independently judged supported by the canonical metric label and subject. Their quotes do not satisfy the stricter V2 ownership rule. This is the intended conservative verifier boundary, not a proposed ownership change.

## Read safety, tests, and disposition

All five rejected narratives returned B1 blocks; rejected narrative text did not leak. Sixty repeated reads created **zero** additional AI runs or dispatches and constructed **zero** provider clients; see the [read gate](b2-entity-ownership-paid-retest-evidence/postpaid-read-gate.json). Re-synthesizing all six stored identities reused the outcomes with **zero** new provider calls; see the [idempotency gate](b2-entity-ownership-paid-retest-evidence/idempotency-gate.json).

Pre-paid targeted tests: **96 passed, 510 assertions**. Final targeted tests: **96 passed, 510 assertions**. The single final full backend suite passed **1,252 tests, 1 skipped, 9,113 assertions**. `git diff --check`: clean. Only this report and evaluation evidence were added. No production code, tests, extraction, prompts, pricing, thresholds, key-figure rules, flags, or Railway config changed. No deploy, push, or merge occurred.

Recommendation: continue B2 evaluation with production flag off. This small fresh sample found no unsafe accepted claims and one useful admitted narrative; five still fell back to B1. Investigate key-figure exclusions and quote-limited program attribution separately under provenance review. Do not tune thresholds or broaden ownership from this result.

B2_RETEST_PASSED
