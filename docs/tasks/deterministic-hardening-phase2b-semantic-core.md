# DocIntel Phase 2B: deterministic semantic core

Date: 2026-10-11. Status: implementation and verification in this working tree. No provider request, deployment, push, merge, pricing or billing change.

## 1. Ownership inventory and conflicts

| Owner | Existing rule and callers | Conflict or duplication | Affected output |
|---|---|---|---|
| `MeasurementParser` | Reads magnitude, scale, currency, family, kind for metric collection, normalization, diagnostics, `ValueParser`, and numeric provenance | Float magnitude; percent and point family parsing; currency alias table | Stage A charting, extraction-derived typed views and exports |
| `ValueParser` | Quote-local exact value typing, date/period roles, precision flag | Separate whitespace matching; a physical `per year` unit can be typed as duration | Stage A read model, B1/B2 inputs |
| `NumericEquivalentGrounding` / `ProvenanceProjector` | One-quantity notation exception, direct-substring contradiction guard and document/unknown origin | Independent float/scale/qualifier equality; the old direct match erased punctuation, including decimal points; quote lexer does not establish arbitrary multi-number relations | Document/unknown provenance, then B1/B2 eligibility |
| `BriefVerifier` | B1 and B2 prose checks, typed-value integrity, currency resolution, per-number grounding, duration spans, derived growth | Float tolerance and generic numeric equality could bypass unit meaning; derived-growth fallback previously lacked a percent gate | B1 blocks, B2 claim verification and deterministic fallback |
| `NumericClaimGrounder` | B2 claim-to-record ownership, nouns, currency, qualifiers, denominator, period, entity, label relation | Duplicate currency, unit and qualifier tables; single-period/subject shortcut could borrow another record's context | B2 claim admission |
| `SignalEvaluator`, `KeyFigureSelector`, `MetricCollector`, `AnalysisGrouper`, `ProvenanceDiagnostic`, `ProvenanceDiagnosticExtension` | Stage A materiality, ranking, series grouping and diagnostics consume typed values or measurements | Their selection policy is separate from claim compatibility and is unchanged | Stage A/B1 figures, diagnostics and exports |
| `NarrativeContextBuilder` / `NarrativeCheckpoint` | B2 input hash and persisted attempt reuse | Previously no semantic-policy axis in the hash | B2 persisted verdict and GET reuse |

The central owner is `SemanticCompatibility`. It compares a written claim quantity with **one** typed canonical record, returns `compatible` or a stable reason, and owns decimal magnitude, narrow semantic unit aliases, currency, qualifiers, simple denominators and direction contradiction. `MatchingTextNormalizer` owns surface-only Unicode/whitespace matching. `MeasurementParser` remains the extraction/chart parser; `ValueParser` remains the quote-local typing owner; verifiers retain period/entity/relation ownership and candidate lexing. The B2 duplicate currency/unit/qualifier methods were removed. The provenance exception now calls the central comparator for notation equivalence, while its direct-substring guard retains the special quoted-bare-number rule needed when the same record's `unit` supplies scale.

## 2. Matching and semantic policy

`MatchingTextNormalizer` applies NFC composition, whitespace collapse, common ligatures, typographic quote and dash variants to **matching copies**; its optional case fold is used for provenance surface matching. Stored source bytes remain unchanged. `ValueParser`, `ProvenanceProjector` and the numeric notation exception now use this matching owner. Provenance no longer drops `.` and `,`, so `15.5` cannot match `155` through punctuation erasure. `EvidenceMerger` retains its separate legacy identity normalization for extraction merging; that identity policy was not changed in Phase 2B. Matching never repairs numbers, dates, currency codes, names, legal identifiers, acronyms or references. Unit aliases are explicit in `SemanticCompatibility`: singular/plural forms and litres/liters, tonnes/tons. Beneficiaries and people remain separate because the record does not prove they are interchangeable.

The policy fingerprint is `SemanticCompatibility::POLICY = semantic.v1`. A future semantic-rule edit must update this constant in the same change. `NarrativeContextBuilder::inputHash` includes it alongside the existing prompt, contract, model and verifier axes. A policy change therefore creates a new B2 attempt identity and prevents reuse of an old verdict. This can lead to a fresh B2 provider call on an already processed document **only when a separate authorized synthesis job runs**; a GET remains read-only. B1 is assembled on read and has no persisted verdict cache to invalidate. Extraction and other cache identities are unchanged. B1 verifier remains `2`; B2 verifier remains `3`; no prompt or provider setting changed.

## 3. Compatibility rules

- **Magnitude and scale:** compare normalized decimal strings after an explicit decimal-power shift. `12.4 billion` equals `12,400 million`; `12.4` does not equal `12.4001`; million does not equal billion. Trailing displayed zeroes do not change an exact value. No floating epsilon admits a claim. Existing derived-growth arithmetic remains separate and requires an explicit percent claim and structured two-input derivation.
- **Percent:** percent, percentage points and basis points are distinct semantic units. Five points equals 500 basis points only when a caller explicitly sets `allowPointConversion`; B1/B2 default to no conversion. A value spelling `%` with a point unit is rejected during measurement parsing. A missing percentage denominator is never inferred by this core.
- **Currency:** claim currency must resolve from the same typed record. ISO aliases and unambiguous symbols are deterministic; a bare `$` is compatible with a coded claim only if the source record itself resolves that code and its value actually writes `$`. A conflicting value/unit code or unresolved `$` cannot ground a coded claim. No cross-record, title, location or neighboring-row inference.
- **Durations, counts and physical units:** duration is separate from count and physical quantities. Supported count nouns are compared explicitly. `employees` and `households` differ; `litres` and `tonnes` differ. Relative calendar duration conversion is unsupported. A simple money/person rate may compare when the record's unit states its denominator. Physical rates with a duration denominator fail closed as `schema_required` because current typed values can lose numerator/denominator structure.
- **Sign, direction and qualifiers:** signed decimal strings preserve plus/minus meaning. Exact, strict/inclusive bounds and approximation are separate. `more than`/`over` and `about`/`approximately` are narrow aliases. Range and `between` claims fail closed; source ranges do not type as exact values. A direction contradiction in record-local label/raw wording rejects. Some direction only present in a multi-fact quote still needs extraction meaning.
- **Precision:** exact decimal equality and exact scale equivalence are supported. `12.4` and `12.40` are representationally equal. Claim rounding that changes value is unsupported; `12.4001` cannot be asserted from `12.4`. The old B1 loose tolerance is removed from direct claim matching. No precision is inferred beyond the source spelling.
- **Multiple numbers:** B1 and B2 iterate numeric mentions independently. B2 additionally binds each mention to its own label, subject and period. B1 binds a sole explicit subject and a same-clause period for a single-number claim to its numeric record. A single fact cannot take its value, currency, unit, entity and period from separate records in these supported shapes. Explicitly structured derivations remain a separate two-input relationship.

## 4. Fallback audit

| Fallback | Classification | Disposition |
|---|---|---|
| Verified B2 unavailable/rejected → deterministic B1 | B: visibility | Retained; existing B1 is verified independently and no rejected text is shown. |
| Typed parse failure → raw magnitude | C: unsafe semantic masking | Blocked in B1/B2 through `groundableValue` and `SemanticCompatibility`; no raw-number rescue was added. |
| Generic B1 numeric match despite wrong unit/currency/qualifier | C: unsafe semantic masking | Removed from direct grounding; central comparison is required. |
| Provenance punctuation erasure before direct source matching | C: unsafe semantic masking | Removed from the provenance match. Extraction merge identity normalization remains outside this claim-matching path. |
| Derived growth numeric fallback for non-percent claim | C: unsafe semantic masking | Removed; derivation only applies to a percent claim. |
| B2 same-label whole-claim wording when local label is absent | A: conditional ownership | Retained with the existing all-labels-identical guard; record-local numeric semantics and subject/period checks still apply. |
| Provenance direct substring with unit scale stated in the **same** record | A: correctness-preserving | Retained. A briefly attempted central check wrongly rejected quoted `12.4` plus record unit `USD billion`; the first full suite found five feature regressions, and the same-record substring rule was restored. |
| Optional view/cache misses | B: product | Existing behavior retained. |

## 5. Schema and extraction classification

| Issue | Classification | Boundary |
|---|---|---|
| Exact decimal scale, explicit currency, percent/point separation, narrow unit aliases, bounds/approximation equality, simple per-person money rate | `SOLVED_DETERMINISTICALLY_NOW` | One typed record and claim text contain all needed fields. |
| Table header/row relationship, field-role provenance, rate numerator/denominator for physical duration rates, calendar/legal duration equivalence, structural footnotes, period/entity role ownership in compound tables | `SCHEMA_REQUIRED` | Current typed value does not retain enough structure. |
| Percentage basis/denominator omitted from a source record, direction only in ambiguous multi-number wording, legal force/modality, conflicts/restatements | `EXTRACTION_MEANING_REQUIRED` | String equality cannot assign the missing meaning. |
| Arbitrary typo/spell correction, unnamed currency conversion, heuristic approximate rounding, broad count-noun equivalence | `INTENTIONALLY_UNSUPPORTED` | Could manufacture facts or erase semantic distinctions. |

No migration was introduced. No production data or worker compatibility change is required. Structural provenance, table interpretation, legal modality and temporal conflict work remain outside Phase 2B.

## 6. Measured effect and verification

Safety in the focused adversarial matrix: **zero** observed wrong-unit, wrong-currency, wrong-direction, percent/point, cross-record borrowing or numeric fallback accepts. These are fixture observations, not a production-rate estimate. Each negative comparator case asserts a stable rejection reason; verifier tests assert their public rejection checks. Multi-number, record-local currency/period/entity and rate confusion tests are included.

Usefulness: exact scale conversion, displayed-zero equivalence, record-local USD aliases, safe unit spelling/singular aliases, same-qualifier bounds and a simple money/person rate pass. One previously rejected basis-point claim with an explicit basis-point source is now accepted. The replayed F28/F35/F44 recoveries and F03/F19 rejections remain as before. In the reviewed and feature fixtures, **zero** supported B1 figures or B2 claims were lost after corrections; this is not a production recall estimate. The first complete suite exposed **five** false rejections in B1/B2 wiring and takeaway fixtures due to an overstrict provenance check; the correction rerun passed **9 tests, 719 assertions**. No B1 ranking/top-six, Stage A materiality, prompt, provider, billing or customer-pricing policy changed.

Focused semantic, parser, grounding, B1, B2 and replay unit run: **219 passed, 711 assertions**. Focused Stage A/B1/B2/incremental database-backed run: **118 passed, 822 assertions**. Later focused B1 owner/replay run: **85 passed, 222 assertions**; provenance/replay/semantic run: **77 passed, 153 assertions**; affected database-backed B1/B2 run: **57 passed, 1,154 assertions**. These focused groups overlap and are not summed. The initial complete suite reached **1,335 tests, 1,329 passed, 8,809 assertions, five failures, one skipped**; all five failures were the same provenance false-rejection defect above. Final complete backend suite: **1,340 tests; 1,339 passed; 9,488 assertions; zero failures/errors; one skipped**. Pint on all changed PHP files: **passed**. `git diff --check`: **passed**.

Remaining limitation: the central comparator is a compatibility gate over existing typed values, not a structural claim graph. Unknown or compound roles cannot be proven by matching. Exact duration conversion, percentage denominator identity, multi-fact quote direction, restatements and some B1 relation ownership remain for their respective provenance/extraction phases. Physical duration rates fail closed. The policy does not silently normalize factual content.
