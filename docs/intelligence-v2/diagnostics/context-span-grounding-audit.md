# Context-span grounding audit closeout

**Recommendation: B. Field-role provenance is required first.** This closes the context-span investigation. No prompt-only context-citation experiment is recommended. The next priority returns to **CONTROL vs COLLECTOR cost decomposition and compact/targeted extraction**.

This work made **zero provider calls**, changed no production code, and used only saved request/response artifacts plus offline synthetic fixtures. No push, merge or deploy occurred. The earlier four-call fixture and later 12-call fixture are reported separately because their chunks and sampling conditions differ.

## 1. Current code-path answers

| Question | Exact answer and code path |
|---|---|
| Does value grounding search all cited spans collectively? | **There is no value check in span validation.** `EvidenceSchema::validate()` calls `ground()` to validate IDs/version/cap/locality and build a joined `quote`; it does not verify that `value` belongs to a value span ([EvidenceSchema.php](../../../app/Services/AI/Incremental/EvidenceSchema.php#L229-L256), [ground()](../../../app/Services/AI/Incremental/EvidenceSchema.php#L308-L354)). Downstream `ProvenanceProjector::direct()` tests value against that joined quote, so any cited span can supply it; CR-015's numeric guard also sees the joined quote ([ProvenanceProjector.php](../../../app/Services/Intelligence/ProvenanceProjector.php#L38-L52), [NumericEquivalentGrounding.php](../../../app/Services/Intelligence/NumericEquivalentGrounding.php#L44-L76)). `ValueParser::cited()` instead ORs over individual source quotes; it still does not assign value and context roles ([ValueParser.php](../../../app/Services/Intelligence/Values/ValueParser.php#L114-L124)). |
| Does period grounding search all cited spans collectively? | **Yes downstream, not in span validation.** Provenance checks the period substring in the joined quote, so any cited span can supply it ([ProvenanceProjector.php](../../../app/Services/Intelligence/ProvenanceProjector.php#L53-L58)). Typed period projection accepts a match in any single cited quote ([ValueParser.php](../../../app/Services/Intelligence/Values/ValueParser.php#L55-L63), [cited()](../../../app/Services/Intelligence/Values/ValueParser.php#L114-L124)). Neither path ties a period to the row whose value is asserted. `EvidenceSchema::validateDate()` is a date/deadline check, not a general row-period ownership check. |
| Does unit/currency grounding search all cited spans collectively? | **No explicit unit/currency evidence check exists.** `ValueParser` passes the model's `unit` into `MeasurementParser`, and the typed amount/currency can be derived from that field even if no cited quote states it ([ValueParser.php](../../../app/Services/Intelligence/Values/ValueParser.php#L28-L45)). Provenance checks value and period, not `unit` ([ProvenanceProjector.php](../../../app/Services/Intelligence/ProvenanceProjector.php#L38-L71)). CR-015 considers currency when comparing a parsed value with a single numeric candidate, but it is not a per-field citation check ([NumericEquivalentGrounding.php](../../../app/Services/Intelligence/NumericEquivalentGrounding.php#L17-L41)). |
| Are citations roleless? | **Yes.** The schema carries a list of `evidence_ids`; `ground()` resolves each and concatenates their text without value/period/unit/currency roles ([EvidenceSchema.php](../../../app/Services/AI/Incremental/EvidenceSchema.php#L314-L350)). `EvidenceSpanSet::resolve()` provides location and coarse `type`, not a field role ([EvidenceSpanSet.php](../../../app/Services/AI/Incremental/EvidenceSpanSet.php#L63-L80)). |
| Do all cited IDs participate in `EvidenceMerger::identity()`? | **No.** IDs do not appear directly in the identity fields. Metric identity uses concept, subject, period, unit, value and metric axes; entity and deadline/obligation identities also omit IDs. Default kinds include the joined `quote`, which changes when citations change ([EvidenceMerger.php](../../../app/Services/AI/Incremental/EvidenceMerger.php#L35-L47)). |
| Can adding context change identity? | **Yes, depending on layer/kind.** Experiment primary and strict identities include cited IDs, so E033 versus E032+E033 always differs ([unicef-4call-analyze.py](unicef-4call-analyze.py#L58-L104)). `EvidenceMerger::identity()` stays equal for an otherwise identical metric when only citations change, but changes if period/unit/other metric fields change; for default facts it changes via joined quote. The [identity fixtures](experiment-flags/context-identity-fixture.json) and [PHP fixtures](experiment-flags/context-grounding-fixtures.json) confirm this. In `merge()`, equal metric identities collect source links on one row, while first-record data is retained except aliases ([EvidenceMerger.php](../../../app/Services/AI/Incremental/EvidenceMerger.php#L125-L155)); adding a later source does not necessarily repair the stored quote/provenance input. |
| Can a header number falsely satisfy value grounding through CR-015? | **Yes.** The synthetic `Top 30 ... 2024` header plus `German Committee ... 69.4` row with `value=30` passed span validation and received `origin=document`. `ProvenanceProjector::direct()` finds the header's 30 in the joined quote; `contradictsSubstring()` returns false when multiple numeric candidates are present, so it does not reject this cross-span mismatch ([NumericEquivalentGrounding.php](../../../app/Services/Intelligence/NumericEquivalentGrounding.php#L59-L63)). This is an offline fixture result, not an observed paid-call finding. |

## 2. Numeric-collision and identity fixtures

The [offline PHP fixture](experiment-flags/context-grounding-fixtures.php) exercised current `EvidenceSchema`, `TypedEvidenceProjector`, `ProvenanceProjector` and `EvidenceMerger` without DB writes. Its [JSON output](experiment-flags/context-grounding-fixtures.json) preserves fields, origins, typed values and identity hashes.

| Fixture | Observed result |
|---|---|
| `Top 30 ... 2024` + German Committee row `69.4`; asserted value `30` | Accepted; `origin=document`; typed number **30**, borrowed from header rather than row. |
| Header contains both 2023 and 2024; same row 69.4 | Both period=2023 and period=2024 accepted and marked document; no column ownership check. |
| `PARTNER USD (MILLIONS)` header, row 69.4 | Unit `USD millions` yields typed USD **69.4 million**. Row-only citation with the same model unit also yields typed USD 69.4 million and document origin. With the USD header cited but model unit `EUR millions`, it still yields document origin. |
| Unrelated header number 42, row 69.4; asserted value 42 | Accepted; `origin=document`, another cross-span numeric collision. |
| Same value 50 in both 2023 and 2024 | Both model periods receive document origin; metric merger identities differ because period differs. |

The [exact identity fixture](experiment-flags/context-identity-fixture.json) compares the same C1 E033 raw fact as A `[E033]` and counterfactual B `[E032,E033]`. Experiment primary and strict identities **both differ** because each includes IDs. Direct `EvidenceMerger::identity()` on the same metric fields is **equal** for A and B; on a fact it differs because the joined quote differs. A metric with the same explicit period also retains one merger identity, but the row-only citation projects `origin=unknown` while header+row projects `origin=document`. Actual B would be rejected for the earlier call because E032 was not supplied. These are identity/projection fixtures, not a DB merge run.

## 3. Locality and structure enforceability

| Constraint | Stored metadata available? | Enforced now? |
|---|---|---|
| Same page | `page` is stored per span, though nullable ([EvidenceSpanSet.php](../../../app/Services/AI/Incremental/EvidenceSpanSet.php#L15-L25), [resolve()](../../../app/Services/AI/Incremental/EvidenceSpanSet.php#L70-L80)). | **No** same-page check in `EvidenceSchema::ground()`. A future check could compare known pages. |
| Max span distance | `ordinal` is stored. | **Yes:** max-minus-min ordinal must be at most configured **12** ([EvidenceSchema.php](../../../app/Services/AI/Incremental/EvidenceSchema.php#L337-L340), [config](../../../config/document_intelligence.php#L25-L28)). |
| No intervening heading/caption | Ordered spans and coarse `type` are stored; text can be resolved. | **No.** A heading heuristic is possible, but captions are not a distinct reliable type and OCR can split them. |
| Header/caption structural type | Only coarse types such as `table_row`, `heading`, `sentence`/prose/section. | **No reliable header/caption role.** In the saved UNICEF spans E045 `PARTNER USD (MILLIONS)` is typed `table_row`; E061's Top 30 title is typed `sentence`. |
| Exactly one candidate period/unit/currency | Raw text and offsets can be retrieved. | **No.** Candidate tokens can be counted lexically, but field ownership and competing table columns are not encoded or enforced. |
| Same table/block ownership | No table/block identifier or column relationship is stored. | **No.** Page, ordinal and coarse type cannot prove table-row ownership. |

`SourceSpanBuilder::build()` stores only ordinal, key, page, offsets and coarse type ([SourceSpanBuilder.php](../../../app/Services/AI/Incremental/SourceSpanBuilder.php#L30-L47)); its line classifier is heuristic ([SourceSpanBuilder.php](../../../app/Services/AI/Incremental/SourceSpanBuilder.php#L100-L148)). The current validator enforces ID existence in the exact chunk, a three-ID cap and ordinal locality, but not these structural relations ([EvidenceSchema.php](../../../app/Services/AI/Incremental/EvidenceSchema.php#L322-L349)).

## 4A. Earlier four saved UNICEF responses: E033–E186

The [earlier per-record artifact](unicef-4call/context-span-audit.json) applies the same written period/context rules and 12-span/three-ID limits to saved C1, C2, T1 and T2. C1/C2 were Control; T1/T2 used temperature zero, so this fixture is **not** a Collector comparison. The saved response hashes were checked against metadata. There are **404 raw records**, including validator-rejected records; **204** supplied a period and **200** had period null.

| Category | Records | Share of 204 |
|---|---:|---:|
| CORRECT_AND_SUPPORTED | 40 | 19.61% |
| CORRECT_BUT_UNSUPPORTED_BY_CITATION | 27 | 13.24% |
| WRONG | 0 | 0.00% |
| AMBIGUOUS | 32 | 15.69% |
| NO_EXPLICIT_PERIOD_CONTEXT | 105 | 51.47% |

WRONG among evaluable = **0/67**. Unsupported-but-correct among evaluable = **27/67 = 40.30%**; all 27 are C1's Top 30 table rows dated 2024 from E061 without citing that title. The 32 ambiguous cases include flattened multi-year E066 table values, uncertain cross-sentence `since 2000` scope, and E108's COVID/investment scope. No-context cases often attach 2024 from the report or another block without a claim-owned explicit period. The per-call counts are in the JSON.

**E033/E032:** E033 is in all four exact request payloads; **E032 is in none**. The actual 2024 Top 30 title is **E061**, also in all four payloads and on E033's page, but **28 span ordinals away**, exceeding the configured 12. E045's `PARTNER USD (MILLIONS)` is exactly 12 ordinals from E033 and on the same page, yet stored as `table_row` rather than a header role. C1 supplied `period=2024` for E033 citing only E033: correct by the E061 table title, unsupported by its citation and outside permitted locality. C2/T1/T2 supplied period null for E033. Offline replay of the exact saved span set rejects `[E032,E033]` as `evidence_id_outside_chunk` and `[E033,E061]` as `invalid_evidence_span_combination`. A context span outside the exact request is not an actionable opportunity.

Citation distribution: **393** records cite one span, **11** cite two, **0** cite three. Thus 393 have two cap slots and 11 have one; adding E061 to E033 would use only 2/3 IDs but still fail locality. The cap is not the main E033 obstacle. The same-rule context classifier found **0 safe in-payload**, **0 safe outside-payload**, **100 ambiguous in-payload**, and **0 ambiguous outside-payload** record instances. It also marked 178 selected candidates outside page/locality and 86 structurally unsafe. The [11-case source spot check](unicef-4call/context-span-spotcheck.json) records **one disagreement**: C2 index 102 remains `OUTSIDE_LOCALITY` in the automated aggregate, while manual inspection sees ambiguous yearly-allocation scope between E143/E144. No label was silently rewritten.

## 4B. Later 12 saved responses: UNICEF E100–E139 and India E200–E238

The [later audit](context-span-audit.md) and [per-record JSON](experiment-flags/paid-study/analysis/context-span-audit.json) cover **508 raw records**, **223** with periods and **285** period null. Period categories are **131 supported**, **0 correct-but-uncited**, **1 wrong**, **62 ambiguous**, **29 no explicit context**. WRONG among evaluable = **1/132 = 0.76%**. Citation counts are **488 one-ID**, **19 two-ID**, **1 three-ID**.

The only safe same-payload context relation is **E212→E211**, repeated in four India calls: **4 record instances, one distinct source relationship**. Each proposed addition stays below the three-ID cap. Safe outside-payload = **0**; ambiguous in-payload = **114**; ambiguous outside-payload = **0**. The earlier 12-case spot check had no remaining disagreements. These are possible citation opportunities, **not measured recall gains**.

## 4C. Combined descriptive summary

| Measure | Earlier four | Later twelve | Combined descriptive |
|---|---:|---:|---:|
| Saved calls | 4 | 12 | 16 |
| Raw record instances | 404 | 508 | 912 |
| Non-null periods | 204 | 223 | 427 |
| Supported | 40 | 131 | 171 (40.05%) |
| Correct but uncited | 27 | 0 | 27 (6.32%) |
| Wrong | 0 | 1 | 1 (0.23%) |
| Ambiguous | 32 | 62 | 94 (22.01%) |
| No explicit context | 105 | 29 | 134 (31.38%) |
| Period null | 200 | 285 | 485 |
| Safe in-payload context instances | 0 | 4 | 4, one distinct relationship |
| Citation count 1 / 2 / 3 | 393 / 11 / 0 | 488 / 19 / 1 | 881 / 30 / 1 |

Combined WRONG among evaluable is **1/199 = 0.50%**, while **27/199 = 13.57%** are correct-but-uncited. These aggregates are descriptive only: the earlier four calls used a larger E033–E186 chunk and a Control/temperature comparison; the later twelve used two smaller chunks and Control/Collector variants. Repeated model outputs are not independent facts, and OCR-flattened charts dominate some ambiguity. The broader document population is not represented.

The same **written** rules were used for both fixtures. While applying them, detector coverage was completed for literal `since 2000`, multi-year E066 chart ambiguity, and candidate visibility beyond the 12-span acceptance distance. The locality and cap thresholds, safe-context criteria, request membership requirement and later 12-call category totals did not change. The first earlier pass was 36 supported / 27 correct-but-uncited / 7 ambiguous / 134 no-context; the final pass is shown above. This implementation correction is disclosed rather than hidden. No source, response, request or benchmark was edited.

## 5. Decision and stop

**B. Field-role provenance is required first.** Prompt-only context citations can make a header's unrelated number satisfy value grounding, let either of two header years satisfy period grounding, and permit a model-supplied currency that is absent or contradicted in the cited text. The E033 table's actual 2024 title also fails current locality, and stored metadata does not reliably encode table ownership. The four safe later instances are an upper bound on possible benefit and do not offset those grounding risks. This is a small offline sample, indicative rather than proof for all document types; offline availability does not show that a model would cite context correctly. No further context-span experiment is proposed. The next priority returns to **CONTROL vs COLLECTOR cost decomposition and compact/targeted extraction**.
