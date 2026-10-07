# CR-009: Resolve fixture 25 selected-set conflict at an exact-score cap boundary

| | |
|---|---|
| **Author** | Backend implementation |
| **Date** | 2026-10-07 |
| **Status** | draft; approval required |
| **Approver** | Pending |
| **Approved on** | Pending |
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

## Decision requested

Approve an explicit rule for exact-score ties that cross a `MAX_PER_STEM`, per-kind or `MAX` selection boundary. The approver must decide whether T9a allows a different selected set **only** at that boundary, or requires a separate deterministic selection policy that preserves V1 membership while §9.5 still determines V2 presentation order. The latter would need an exact permitted policy; none is inferred here. No confidence input to score, tier, forced rules or §9.5 may be introduced. No scorer parameter is proposed for retuning.

## Scope and stop

MaterialityScorer, ForcedItemRules and integration into ImportantFindingsBuilder, TakeawayBuilder, EvidenceBudget and ChartCandidateBuilder remain stopped. The approved CR-008 contract amendments are committed. Flag-off behavior, the 16,000-byte synthesis evidence bound, provider calls, extraction, OCR, chunking, billing, frontend and Power BI are unchanged.

**Approver notes:** Pending.
