# CR-009: Resolve fixture 25 selected-set conflict at an exact-score cap boundary

| | |
|---|---|
| **Author** | Backend implementation |
| **Date** | 2026-10-07 |
| **Status** | approved |
| **Approver** | Stage A approver |
| **Approved on** | 2026-10-07 |
| **Supersedes** | none |

## Affected contract

CR-008 / T9a requires the V2 `importantFindings` selected set to be exactly the V1 selected set, while CR-006 / §9.5 replaces V1 confidence/reference ordering for exact-score ties with identity ordering. `importantFindings` applies a per-stem cap during selection. A tie that crosses that cap changes membership, not just presentation order. The current contract does not authorize that membership change.

## Focused fixture 25 result

The diagnostic reused the unmodified `DocumentIntelligenceAnalysisTest::annualReport()` fixture with fixed `asOf=2026-10-07`, then selected the same eligible records after the existing chart/takeaway exclusions, using approved scorer values, score-descending order, §9.5 tiebreak and approved stem/kind caps. The committed V1 list was:

1. Currency volatility exposure
2. Total financing approved
3. Operating note 1
4. Operating note 2
5. African Development Bank
6. Chief Financial Officer
7. Nairobi

The V2 diagnostic list in this run was:

1. Currency volatility exposure
2. Total financing approved
3. Operating note 9
4. Operating note 7
5. Nairobi
6. Chief Financial Officer
7. African Development Bank

The selected-set difference was `Operating note 1` and `Operating note 2` versus `Operating note 9` and `Operating note 7`. Each of these fact records has score `0.36` from `kind_class: fact` only; structural prominence is skipped (`span_data_unavailable`) and boilerplate penalty is skipped (`span_type_unavailable`). They have the same kind and stored source start offset. V1's reference-derived order chooses the earliest created notes; §9.5 reaches salted identity and chooses other notes. The approved `per_stem = 2` then excludes the remaining notes. Which two identities win may vary between fixture runs because the fixture salts identities, but the conflict between these two selection rules remains. The entity order also changed only among exact-score ties; no entity membership difference occurred in this run.

The risk and metric remained selected. This is a **selected-set failure**, not a scorer weight, band, class-base, signal, promotion or forced-rule failure. The temporary scorer and diagnostic test were removed after the required stop; no materiality integration was committed. Takeaway selection, chart candidate order, T9b attention compatibility, and the ADB diagnostic were not evaluated after this failure.

## Read-only tie diagnosis before fixture changes

A focused read-only run of the unchanged `annualReport()` fixture recorded these fields. Identity hashes are from this one run; the helper salts them, so they vary between runs. The materiality score is `0.36`, forced priority `999`, tier `3`, and kind `fact` for each record.

| Record | start_offset | page | end_offset | normalized label | identity in diagnostic run |
|---|---:|---:|---:|---|---|
| Operating note 1 | 10 | 14 | 60 | `operating note 1` | `c0f09e172d72f0a649ef5d10e5d2b38ec0955ddb831a384542cbf9fa996e0cfb` |
| Operating note 2 | 10 | 14 | 60 | `operating note 2` | `9ebd1533af80a64da7c4214dd8cd50a765a99f207f4c7cb47363c1f3ee173748` |
| Operating note 7 | 10 | 14 | 60 | `operating note 7` | `d50b8ea7edb183a58a0be250b23aaa5fff655867e81e2d2ea77dd0ee41521832` |
| Operating note 9 | 10 | 14 | 60 | `operating note 9` | `440cb76cdd4eba522b4c4175fca73a95d32a00199cf3dae99383cf007e9a8990` |

These positions are identical **only because of a synthetic fixture-builder artefact**: `BuildsIntelligenceFixtures::evidenceRow()` assigns `start_offset=10`, `end_offset=60`, `page=14`, and `span_id=E001` to every fixture evidence row. `annualReport()` creates 40 operating notes, but its `Document.extracted_text` contains only three short section labels, not those notes. It therefore provides no independent source order or actual note offsets. No protected baseline test or fixture data was changed, and no offset was manufactured to reproduce V1 order. The amended §9.5 proceeds to normalized label, then identity.

## Approved resolution

§9.5 total order is now forced-rule priority (absent 999), tier band, fixed kind order, earliest source start offset, earliest source page (null last), source end offset, normalized label ascending, then identity ascending. Confidence is excluded. T9a preserves the V1 selected set and order except when `MAX`, `MAX_PER_STEM` or per-kind selection cuts through an exact-score tie group after all higher-priority selection semantics. At that boundary, select the same **number** of records from the group by §9.5; exact V1 identities are not required. No lower-scoring record may displace a higher-scoring one. V1's boundary choice was confidence/reference-derived and is deliberately not preserved there. Weights, bands, class bases, signals, promotion, patterns and forced rules remain unchanged.

## Scope and stop

MaterialityScorer, ForcedItemRules and integration into ImportantFindingsBuilder, TakeawayBuilder, EvidenceBudget and ChartCandidateBuilder may resume subject to the revised fixture 25 gate. Flag-off behavior, the 16,000-byte synthesis evidence bound, provider calls, extraction, OCR, chunking, billing, frontend and Power BI remain unchanged.

**Approver notes:** Approved revised §9.5 and exact-score cap-boundary exception above. No fixture position correction was authorized without independent source-position evidence; none exists for this fixture.
