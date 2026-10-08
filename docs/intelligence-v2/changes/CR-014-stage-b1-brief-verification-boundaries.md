# CR-014: Stage B1 deterministic Brief and fixture 25 verifier resolution

| | |
|---|---|
| **Author** | Stage B1 implementation |
| **Date** | 2026-10-08 |
| **Status** | approved by user |
| **Approver** | User, 2026-10-08 |
| **Supersedes** | none |

## Affected contract

§7.4 T9b, §13.2–13.7, §14.2, §20.1 and §22.3–22.5. The eleven checks in §14.2 remain mandatory, including `origin_assertion_consistent`. No scorer value, forced rule, provider budget or legacy flag-off output changes.

## Approved Stage B1 boundary

The new Brief is derived on read, deterministic only, and has `ai_blocks_available: false`. It contains only `headline`, `attention`, `timeline`, `measure` and `coverage_note`; no AI or absence Brief block is inserted. The existing synthesis/takeaway surface is separate. A verified synthesis takeaway can retain `ai_generated: true` there without becoming an AI Brief block. No synthesis prompt, schema, request, provider call, migration, route or API resource is changed.

`headline.document_identity` may have empty `cites` because it renders only document name and type. `coverage_note` retains its existing empty-citation exception. Every deterministic block has `template_id` and `template_version = "1"`. The approved source-quote limit is `brief.max_quote_chars = 160`.

The key-figure selector admits only document-origin metrics with a valid canonical numeric money `TypedValue` and non-null currency. It is independent of materiality tier, including Tier 3 and Tier 4; §13.2's block `tier` therefore includes `4` without promoting the record. Exact equivalents are deduplicated by currency, canonical number and normalized period, keeping the first under §9.5. Order is total/headline-label match first (`total`, `overall`, `aggregate`, `net`, `gross`, English word boundary and case insensitive), then the existing `monetary_magnitude` signal value descending within the currency group, then §9.5. The cap is six; fewer are all shown without padding. No raw cross-currency magnitude comparison and no unsupported delta is allowed.

## Fixture 25 diagnostic and golden change

The existing fixture's two synthesis-derived candidates each cite only `kpi:1`. Its typed value is USD 9.8 billion (canonical 9.8e9), but its record is `unknown` / `unspecified`: the stored period `2022` is absent from its cited quote, `Total financing: 9.8`. The draft verifier correctly rejects both candidates when the legacy `explicit` basis is routed as `stated`:

| Candidate | Exact text | `failed_reasons` |
|---|---|---|
| finding | `Financing growth is concentrated in infrastructure.` | `["origin_assertion_consistent"]` |
| trend | `Approvals have risen in each of the last three reporting years.` | `["comparison_valid", "origin_assertion_consistent"]` |

The trend also cites only one record for a directional comparison, while §14.2 requires two comparable records. Neither candidate has a declared deterministic fallback. Their omission is required; no verifier rule is weakened.

T9b now compares V2 takeaway selection with V1 logic on V2-formatted candidate text **minus synthesis-derived candidates rejected by BriefVerifier**. The §22.5 golden `tests/Fixtures/intelligence-v2/expected/25-takeaways-v2.json` is updated only to remove the two rows above. Chart order, `importantFindings` under CR-009, Tier 1 attention compatibility, and fixture 26 remain strict. The rejection count belongs in `stats.briefAiBlocksRejected`. A separate new fixture must show a document-origin, typed candidate passing verification with `ai_generated: true`, and the same candidate citing an unknown-origin record being omitted.

## Brief flag config shape

Commit `6ea54d0` changed `intelligence_v2.brief` from a bool to an array with `brief.enabled`. Both read `DOCINTEL_V2_BRIEF` with default `true`. There was no runtime caller of the old bool and there is currently no runtime caller of `brief.enabled`; the new services read settings below `brief.*`. The config key type change is approved and §20.1 records it. The main `intelligence_v2.enabled` flag and flag-off runtime behaviour are unchanged.

## Impact

No provider call, request-size change, job, embedding call, migration, extraction, OCR, chunking, billing, security, isolation, Power BI or frontend change. The 16,000-byte evidence bound and all materiality parameters remain fixed. Rollback is the V2 feature flag for runtime output; the new Brief has no stored rows. CR-001 directness remains unchanged. No protected test or fixture-25 input is edited.
