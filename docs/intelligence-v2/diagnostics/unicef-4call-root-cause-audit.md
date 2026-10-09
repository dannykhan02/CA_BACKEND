# UNICEF four-call extraction attribution audit

**Scope:** one diagnostic subchunk, E033–E186 (pages 3–12) of one UNICEF PDF. These results do not establish behavior for the complete document or other document types. Four Anthropic calls were made: C1, C2 with the exact saved Control body; T1, T2 with only `temperature: 0` appended. No provider retries, normal document jobs, or normal application-table writes occurred. All files remain uncommitted.

## Request, cost, and provider results

The saved Control body was independently rechecked at **34,178 bytes**, SHA-256 `143b3385379edaa0955625350eb69da3a59814ad23dffb0676bbf073ccfccd91`. It omitted `temperature`, `top_p`, `top_k`, and `stop_sequences` as keys. T0 bodies were 34,194 bytes, SHA-256 `d375b31fea55001b7f864ff257d1395a3f82b1489fe220605ece14711cd0099f`; decoding and removing `temperature` yields exactly the Control object. Model was `claude-haiku-4-5-20251001`, `max_tokens=16,000`, `max_records=79`, unchanged prompt/schema/span grounding and source payload.

**Pre-call cost gate printed:** `4 × (34,178 serialized bytes × $1.25/M cache-creation input + 16,000 output tokens × $5/M) = $0.490890`. Including the extra 16 serialized bytes in each T0 request gives a slightly more exact **$0.490930 conservative four-call ceiling**. Both are below the $1.00 stop threshold. This byte-per-token bound is deliberately conservative; no token-count API call was made.

| Call | HTTP | Stop reason / class | Returned / accepted | Input | Cache create / read | Output | Latency | Usage-based USD estimate | Provider request ID |
|---|---:|---|---:|---:|---:|---:|---:|---:|---|
| C1 | 200 | `end_turn` / NORMAL_COMPLETION | 80 / 79 | 9,815 | 0 / 0 | 10,058 | 84.003 s | $0.060105 | `req_011CfrSaWD4SbtaSeZqHoBPj` |
| C2 | 200 | `end_turn` / NORMAL_COMPLETION | 108 / 107 | 9,815 | 0 / 0 | 13,644 | 105.724 s | $0.078035 | `req_011CfrSou3cBCXBgHVynnwnJ` |
| T1 | 200 | `end_turn` / NORMAL_COMPLETION | 108 / 107 | 9,815 | 0 / 0 | 12,831 | 96.232 s | $0.073970 | `req_011CfrT49HkGcrtPEs5ND1pw` |
| T2 | 200 | `end_turn` / NORMAL_COMPLETION | 108 / 107 | 9,815 | 0 / 0 | 12,831 | 96.445 s | $0.073970 | `req_011CfrTH4Hj7qiQfYv2wh43g` |

Totals: **39,260 input, 49,364 output, zero cache-creation/read tokens; $0.286080 usage-based estimated cost** at the configured $1/M input and $5/M output rates. This is a calculation from reported usage, not an invoice. Every call exceeded the prompted `max_records=79` (by 1 or 29); **none** hit `max_tokens` or stopped for truncation. The 79 value was therefore a soft instruction in these calls, not a provider, schema, parser, or storage cap.

Exact serialized requests, exact raw response bodies, response SHA-256 values, usage, latency, and request IDs are in `docs/intelligence-v2/diagnostics/unicef-4call/{C1,C2,T1,T2}.{request,response,metadata}.json`.

## Free downstream replay and stage attribution

C1's exact saved raw response was replayed in **two fresh PHP CLI processes** with identical stored source spans, current validator/projectors, config, and fixed `asOf=2026-10-09T00:00:00+03:00`. Both complete replay artifacts have identical SHA-256 `c1722474362b724479d9525bdc45809e5a749eaf09288cbc3dfb409851c4c58a`. At each captured stage they decode 80, accept 79, reject the same one record, and mark the same 6 records key-figure eligible. **No hidden downstream nondeterminism was detected for this saved input.** This test does not prove every production path deterministic.

The replay uses `AnthropicClient::decodeJsonContent()` by reflection, current `EvidenceSchema::validate()` with the exact stored span set, `TypedEvidenceProjector`, `ProvenanceProjector` (including CR-015 numeric-equivalence grounding), `EvidenceMerger::identity()`, and the current `KeyFigureSelector` eligibility predicate. It saves raw, decoded, validation, grounding, accepted/rejected, typed, provenance, kind, premerge identity, and eligibility for each record. **Actual database merge/KPI alias resolution is NOT EVALUABLE** because this experiment intentionally avoided normal evidence persistence; premerge identity changes are not counted as proven merge loss. Source-side key-figure *eligibility* is evaluable; full-document top-six selection is not.

| Downstream check | C1 | C2 | T1 | T2 |
|---|---:|---:|---:|---:|
| Rejected / returned | 1/80 (1.25%) | 1/108 (0.93%) | 1/108 (0.93%) | 1/108 (0.93%) |
| Rejection reason | `invalid_deadline_date_type` | same | same | same |
| Grounding failures / invalid span combinations | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 |
| Unsupported explicit dates / malformed typed values rejected | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 |
| Key-figure-eligible records after provenance | 6 | 51 | 30 | 30 |
| Accepted records with `origin=unknown` | 59 | 21 | 52 | 52 |

The four rejected records were model-labeled obligations with invalid date metadata. C1/T0 described a programme-expansion **goal**, while C2 described an allocation in source text that says offices *may* receive allocations. The validator preserved precision by rejecting these obligation interpretations. No raw record was lost by JSON parsing. No accepted record collided with another under the tested premerge identity within its call.

## Identity rule and raw-output union

The experiment-compatible **primary quantitative identity** is sorted cited span IDs plus canonical numeric magnitude(s), scale, currency, and percent flag. Normalized label/period/unit disambiguate bases that collide within any call. Nonnumeric identity is kind, cited spans, and normalized value. The **strict sensitivity** identity always adds normalized label/period/unit. No generated database IDs or fuzzy semantic matching were used.

- Raw-provider primary identity union across all four calls: **152**; quantitative subset: **145**. Strict sensitivity union: **256**. The raw union, not the accepted union, is the comparison reference.
- Identity-call events relative to that union: **204 MODEL_OMISSION** and **4 VALIDATOR_REJECTION**. OUTPUT_TRUNCATION, PARSER_LOSS, GROUNDING_FAILURE were **0**. The 4 rejections were nonpriority obligation/date records. Primary attribution assigns the earliest observed divergence; secondary projection drift is reported separately below.
- Among 112 primary identities accepted in at least two calls, **58** changed projected provenance origin, **42** changed key-figure eligibility, **25** changed kind, and **24** changed the core typed-value result. **89** changed the premerge identity; actual merge loss remains unevaluated. These counts overlap and reflect different model fields across calls, not nondeterminism when the raw response is fixed.
- Assigning each comparable identity its first *different downstream outcome* gives 25 classification, 5 typed-value, and 46 provenance differences; 13 have only a premerge-identity difference at the tested stages and are marked **NOT EVALUABLE** for actual merge behavior. All such identities had provider-supplied raw-field variation. This separate outcome view must not be added to the raw-omission event counts.
- A concrete amplification case is E033's 69.4-million contribution. C1 supplied `period=2024`, but its cited E033 row does not state the year, so the current provenance rule conservatively set `origin=unknown` and excluded the figure. C2/T0 supplied `period=null` for the same cited value; origin became `document` and key-figure eligibility became true. The downstream rule behaved consistently. The model's period/citation choices materially changed eligibility.

Every primary raw-union identity's per-call status and earliest divergence stage, including typed/provenance/eligibility output, is in the companion JSON report. Raw identity matching is deterministic but source-side candidate matching remains a proxy.

## SOURCE-SIDE OBSERVABLE REFERENCE

The reference was enumerated **from the exact fixture source before comparing model outputs**. It contains 152 span-local signals: **54 currency, 47 percentage, 21 date/time, 11 scaled operational, 18 numeric operational, and 1 obligation-language** expression. Its 76 broad “potentially material” flags use simple numeric thresholds and include chart axes; they are **not** a semantic gold standard. The single `must` is a rhetorical statement about sharing funding responsibility, not a clear enforceable obligation. No clear standalone deadline was identified in this slice.

A more interpretable **28-claim source-observable priority subset** covers named donor amounts, income totals and partner shares, major operational figures, programme expenses, regional shares, and the first-48-hours statement. This subset was curated during **post-call source review**; its recovery fractions are descriptive and selection-sensitive, not a pre-registered recall score. Matching requires the cited span and canonical quantity; it may miss a valid paraphrase or alternate citation. `A` means a raw record was present and accepted; `–` means no matching raw record.

| Source observation (span) | C1 | C2 | T1 | T2 |
|---|:---:|:---:|:---:|:---:|
| $230.0m; $124.4m donor amounts (E066), each | A | A | – | – |
| 7.9m children; 154m lives; 56m cases; 2b people (E075–E077), each | A | A | A | A |
| $1.584b core-resource income (E095) | A | A | A | A |
| $512.6m; $724.9m; $346.1m partner income (E101), each | A | A | A | A |
| **32%; 46%; 22% partner shares (E101), each** | – | – | – | – |
| 10m people (E107); $1b investment (E108); 13 countries and 8.8m reached (E109), each | – | A | A | A |
| 59.3m water; 17.9m sanitation (E120), each | – | A | A | A |
| $952m EPF; $174m/$227m capital; $162m finance (E133–E140), each | A | A | A | A |
| **2.1b children, context-dependent fragment (E145)** | – | – | – | – |
| $817.2m country programmes (E146) | A | A | A | A |
| **41%; 21% regional shares (E173), each** | – | – | – | – |
| First **48 hours** emergency response (E185) | A | – | – | – |

Per-call accepted recovery against the 28-claim subset: **C1 16/28, C2 21/28, T1 19/28, T2 19/28**. The Control arm's union covers **22/28**; T0's union covers **19/28**. The three Control-only claims are the two E066 donor amounts and E185's first-48-hours observation. T0 has no priority claim absent from the Control union. **Six** priority observations were absent from *all four raw responses*: E101's 32%/46%/22%, E173's 41%/21%, and E145's context-dependent 2.1b fragment. The five percentage omissions are clearly visible in the supplied text; E145's isolated phrase needs adjacent context and should not be treated as an independently complete finding.

All 28 × 4 priority observations generated **37 raw-stage absence events**. The other **75** observation-call matches appeared in raw output and all **75 survived validation/grounding**. Thus the material *extraction presence* losses in this priority set began at raw output, not parser/validator/merge. Of 43 accepted priority currency observation-call matches, however, **27 projected `origin=unknown` and became key-figure ineligible**. That is material downstream *eligibility* loss, generally caused by period/value grounding differences in model-supplied fields and handled conservatively by DocIntel.

The broad 152-signal proxy had accepted-record recovery of **49, 66, 66, 66** by C1/C2/T1/T2. Sixty-six signals were unrepresented in every call, but many are chart-axis values or context fragments. Proxy precision/recall against true evidence are **NOT EVALUABLE** without a hand-verified semantic gold set. The source-derived 28-claim subset is a diagnostic reference, not ground truth.

## Control versus temperature 0 — directional only

| Measure | Control C1/C2 | T0 T1/T2 |
|---|---:|---:|
| Primary raw-identity pairwise Jaccard | **0.403** (54/134) | **1.000** (108/108) |
| Strict sensitivity Jaccard | **0.027** | **1.000** |
| Quantitative raw-identity Jaccard | **0.415** | **1.000** |
| Key-figure-eligible identity Jaccard | **0.056** | **1.000** |
| Returned records | 80, 108 | 108, 108 |
| Accepted records | 79, 107 | 107, 107 |
| Priority source claims recovered | 16, 21 | 19, 19 |
| Broad source proxy recovered | 49, 66 | 66, 66 |

T1 and T2 had **byte-identical provider content blocks**, although their response IDs differ. Their identical outputs are diagnostic evidence only; temperature 0 is **not** proven deterministic. Control showed substantial raw-output and metadata variance. T0's raw primary union is **108 versus Control's 134 (80.6%)**: 90 shared, 44 Control-only, 18 T0-only. The T0 priority union is **19 versus Control's 22**. Its exact repeatability therefore carries a **STABLE UNDER-EXTRACTION RISK** on this slice, including the E066 donor figures and E185 observation. T0 was not worse on validation rate or grounding failures, but it did not recover at least as much materially important source evidence as the two Control calls together. A larger count or perfect n=2 Jaccard is not, by itself, a precision or recall improvement.

## Root-cause decision

**C — BOTH MATERIALLY CONTRIBUTE, within this one slice.** Raw provider omissions account for all 37 absences among the curated priority observation-call comparisons, including omissions shared by all four calls. Downstream processing is deterministic for identical raw input and rejected only four nonpriority obligation/date records across 404 raw records, with no grounding failure. Yet current provenance and key-figure rules make many accepted material currency records ineligible when the model supplies period metadata that is absent from the cited span; 27/43 priority currency appearances were affected. This is a real eligibility consequence of model-output field variation, not evidence that the deterministic grounding rule should be weakened. Parser loss, technical truncation, and proven merge loss do not explain the observed extraction-presence gap.

**Larger temperature study:** justified only as another controlled diagnostic with source-side recall and provenance/eligibility guards across additional chunks/document types. These two T0 calls do **not** justify a production sampling change; the repeated output omitted material claims present in Control. Full document top-six stability, actual persisted merge/KPI alias effects, and true semantic completeness remain **NOT EVALUABLE** from this fixture.

Artifacts: [machine-readable audit](unicef-4call-root-cause-audit.json), [source-observable reference](unicef-4call-source-reference.json), and per-call raw/replay files under `unicef-4call/`. **Provider calls = 4; retries = 0; normal application persistence = 0; production code/config changes = 0.** No push, merge, deploy, or B2 work occurred.
