# DocIntel Intelligence V2 — contract

**Status:** Stage A implemented; CR-001 through CR-014 approved. Stage B1 is in progress; later Stage B and Stage C are deferred.
**Date:** 2026-10-07
**Revised:** 2026-10-07 (clarification pass — see the change log at the end)
**Basis:** [`current-architecture-audit.md`](current-architecture-audit.md) (same directory), as revised by the same pass.
**Change process:** [`contract-change-request-template.md`](contract-change-request-template.md).

This document defines a contract, not an implementation plan. It states the shapes, invariants and compatibility rules that V2 must satisfy. Section numbers are stable and may be cited in change requests.

---

## 0a. What V2 extends — read this first

The first draft was written against a backend tree that did not contain the intelligence visualization work. It therefore specified several things as new that **already exist**. This revision corrects that. V2 is an **extension of `App\Services\Intelligence\*`**, not a greenfield design.

### 0a.1 Prerequisite, not part of V2

The visualization work is unmerged on both repositories and, on the backend, diverges from the audit branch in a way that can silently revert `ff37df8` (proactive chunk planning). **Merge and reconcile both repos before V2 starts.** See audit §0.2, §0.4 and R15. V2's design assumes `App\Services\Intelligence\*` is present.

### 0a.2 What already exists and must be preserved

| Existing | Contract section that governs it now |
|---|---|
| `DocumentAnalysisComposer` — assembles `analysis`, no provider call, no writes | §17.1 — V2 extends its output |
| `MeasurementParser`, `Measurement`, `PeriodParser`, `ReportingPeriod` | §1 — V2's typed values wrap these, not replace them |
| `MetricObservation::groupKey()` — comparability | §1.2 V7, §16.2 |
| `ChartCandidateBuilder` — eligibility, scoring, rejection counters | §16 (rewritten to its actual constants) |
| `TakeawayBuilder` — grounded takeaways, quotas, dedupe, `notes()` | §7, §13 |
| `ImportantFindingsBuilder::TIERS` — seven-tier usefulness model | §5, §9 |
| `AnalysisGrouper` | §17.1 `analysisGroups` |
| `DocumentAnalysis` TS interface + 38 backend tests + 2 frontend test files | §18.2, §22 |

### 0a.3 What V2 genuinely adds

1. **Typed values and date roles** — including the period-only obligation (§1, §4).
2. **One versioned materiality service** unifying three existing ranking mechanisms without changing their decisions (§5, §7, §9).
3. **Forced-item rules** — nothing today guarantees a critical risk survives a cap (§7.3).
4. **Deterministic verification of synthesis prose** against cited records (§14).
5. **Structured coverage and attention states** (§10, §11).
6. **The negative-claim guard** (§12).
7. **Brief blocks** (§13) and the evidence-highlighting contract (§15).

### 0a.4 Power BI is out of scope

Power BI was never implemented in production: no endpoint, no credential, a `NOLOGIN` reader role, and `workspace_settings.powerbi_enabled` gates nothing (audit §6.0). **V2 adds no Power BI view and no Power BI column, and Prompt 2 must not modify or test against the feed.** §18.1 is reduced to a non-modification rule.

---

## 0. Governing principles

1. **Zero new provider calls by default.** Every V2 artefact is derived deterministically from records that already exist. See §19.
2. **Additive only.** No existing API field, resource key, database column, Power BI view column or enum value changes meaning, type or name. See §17, §18.
3. **The model never supplies evidence text, offsets, identifiers or numbers that DocIntel can supply itself.** Preserved from V1's span-grounding design.
4. **Deterministic beats generated.** When a block can be produced by template from typed values, it is. AI prose is a fallback, is verified, and is labelled. See §13, §14.
5. **Absence is never asserted from incomplete coverage.** See §12.
6. **One versioned config/service owns every weight and threshold.** See §9.

---

## 1. Typed values

> **Revised (clarification pass).** `MeasurementParser`, `Measurement`, `PeriodParser` and `ReportingPeriod` already exist and already parse value + unit → magnitude, currency, scale, kind, and period text → granularity, basis, label, chronological order. They have unit tests (`tests/Unit/IntelligenceNormalizationTest.php`) covering ambiguous-value rejection, bare `$` not being assumed to be any currency, one currency at two scales being one measurement, and a quarter never comparing with a year or fiscal year. **`TypedValue` wraps these; it does not replace them, and `ValueParser` delegates rather than re-parsing.**

### 1.1 `TypedValue`

Every numeric, monetary, temporal or quantified thing a record asserts is carried as a `TypedValue`. A `TypedValue` is produced **only** by `App\Services\Intelligence\Values\ValueParser` — which delegates to the existing `MeasurementParser` and `PeriodParser` — from the record's own `raw` string. The model never populates any field except by having produced the `raw` text, which is itself grounded in evidence.

Mapping to the existing objects: `Measurement` supplies `number`, `scale`, `currency`, `unit`, `unit_kind`; `ReportingPeriod` supplies `Period.grain` (its `granularity`), `Period.fiscal_year_basis` (its `basis`) and `Period.text` (its `label`). V2 adds `date`, `duration`, `precision`, `sign`, `scale_source` and `anchored`. Where the existing objects reject a value as ambiguous, `TypedValue` is **not** created and the record stays `typing: "unparsed"` — the existing rejection is authoritative.

```
TypedValue {
  type: "number" | "money" | "percent" | "ratio" | "count"
      | "date" | "period" | "duration" | "text" | "boolean"

  raw: string                 # verbatim substring of the cited evidence, never rewritten
  verbatim: true              # always true; a TypedValue whose raw is not a substring
                              # of its cited evidence is invalid and is not created

  # numeric family (number, money, percent, ratio, count)
  number: float | null        # canonical magnitude, scale already applied
  scale: 1 | 1e3 | 1e6 | 1e9 | 1e12 | null   # the multiplier stated by the document
  scale_source: "stated" | "unit" | null  # "stated" = "2.1 billion"; "unit" = "USD m" column header
  currency: string | null     # ISO-4217 when the document names one, else null
  unit: string | null         # the document's own words, unchanged: "USD billion", "%", "employees"
  unit_kind: "currency" | "percent" | "ratio" | "count" | "duration" | "other"
  sign: -1 | 0 | 1 | null
  precision: "exact" | "rounded" | "approximate"   # "approximate" for "about", "~", "circa"
  measure_status: "actual" | "forecast" | "target" | null # stored metric_type/value_basis only
  entity_ref: { id: string | null, text: string } # id only for a confirmed entity record

  # temporal family
  date: "YYYY-MM-DD" | null   # a complete calendar date, literally stated (see §4.2)
  period: Period | null
  duration: Duration | null

  # provenance of the parse itself
  parser_version: "values.v1" # see §9.4
}

Period {
  text: string                # the document's own words: "FY2025", "Q3 2026", "second half of 2024"
  grain: "day" | "month" | "quarter" | "half" | "year" | "fiscal_year" | "range" | "unknown"
  start: "YYYY-MM-DD" | null  # inclusive; null when the grain cannot be anchored
  end:   "YYYY-MM-DD" | null  # inclusive
  anchored: bool              # true only when start and end are both non-null
  fiscal_year_basis: string | null   # only when the document states it; never assumed
}

Duration {
  text: string                # "within 30 days after execution"
  iso8601: string | null      # "P30D"
  anchor_text: string | null  # "after execution"
  anchor_resolved: false      # V2 never resolves a relative anchor to a calendar date
}
```

### 1.2 Invariants

- **V1** `raw` is a substring of at least one cited evidence span's resolved text. A `TypedValue` that fails this is not created; the record keeps its untyped string and is marked `typing: "unparsed"`.
- **V2** `number` is `null` whenever `type` is not in the numeric family.
- **V3** `date` is non-null **only** when the cited evidence literally states a complete, unambiguous calendar date, using exactly the recogniser V1 already ships (`EvidenceSchema::evidenceStatesDate` semantics: `Y-M-D`, `D Month Y`, `Month D, Y`, and `D/M/Y` only when the day exceeds 12). This rule is inherited unchanged; V2 does not loosen it.
- **V4** `Period.anchored` is false unless both bounds are derivable from the document's own statements. A fiscal year is never anchored by assumption.
- **V5** `Duration.anchor_resolved` is always `false`. V2 does not compute dates from relative anchors.
- **V6** `scale` is applied to `number`; `raw` keeps the document's presentation. `"2.1"` with `scale: 1e9` has `number: 2100000000.0` and `raw: "2.1"`.
- **V7** Two `TypedValue`s are comparable only if `type`, `unit_kind`, `currency` and (for `percent`) basis all match. Comparability is a precondition for charting (§16) and for any trend statement (§13.4).

### 1.3 Where `TypedValue`s live

Additively, inside `document_evidence.data` under a new key:

```
data.typed: {
  value:  TypedValue | null      # the record's primary measured value
  dates:  { <DateRole>: TypedValue }     # see §4
  extras: { <name>: TypedValue }         # e.g. a penalty amount on an obligation
}
```

`EvidenceMerger::identity()` reads a fixed field list and is **not** changed, so adding `data.typed` cannot alter evidence identity or cause duplicate rows.

---

## 2. Origin / assertion / attribution model

V1 conflates three independent questions into `date_type`, `basis` and `confidence` (audit §3.4). V2 separates them into three orthogonal axes. Every record and every Brief block carries all three.

### 2.1 `origin` — who produced this statement

| Value | Meaning |
|---|---|
| `document` | The document states it. DocIntel only located and typed it. |
| `unknown` | Accepted source evidence exists, but stored evidence and extraction metadata do not establish that the record's wording was directly asserted by the document. |
| `docintel_deterministic` | DocIntel computed it from `document`-origin records by code: an arithmetic delta, a sort, a count, a template sentence. No model involved. |
| `docintel_ai` | A model wrote it. Always carries `verification` (§14) and is always labelled as an AI summary in any surface that shows it (§13.6). |

### 2.2 `assertion` — epistemic status relative to the document

| Value | Meaning |
|---|---|
| `stated` | Directly asserted by the cited evidence. |
| `derived` | Follows from cited evidence by a stated deterministic rule (e.g. "down 3 pp" from two cited percentages). |
| `inferred` | A reading that goes beyond what the cited evidence states. |
| `absent` | A claim that the document does **not** state something. Permitted only under §12. |
| `unspecified` | Direct assertion cannot be established from stored evidence and metadata. |

### 2.3 `attribution` — who inside the document asserts it

```
Attribution {
  speaker: string | null        # source_id of an entity record, when the document names one
  role: "author" | "auditor" | "regulator" | "counterparty" | "third_party"
      | "quoted" | "unattributed"
  reported: bool                # true when the document reports someone else's claim
  evidence_ref: EvidenceRef | null   # the span that establishes the attribution
}
```

- `role` defaults to `unattributed`, **never** to `author`. An unattributed statement is not the same as a statement by the document's author, and V2 must not silently promote one to the other.
- `reported: true` forbids a Brief block from presenting the claim as the document's own position; the block must name the speaker or the role.
- Attribution matching is English-only, case-insensitive, and driven by the versioned `config/intelligence_v2.php` pattern map approved in CR-007. The nearest approved lexical pattern to the claim wins. An exact tie or no match yields `role: unattributed`, `speaker: null`, `reported: false`. `reported` is true only for `counterparty`, `quoted`, and `third_party`; auditor and regulator remain false in V1. Management statements remain unattributed; never infer `author` from “management”. **No provider call is made to determine attribution.** A model-proposed attribution is out of scope.

The approved literal phrases are in CR-007 and config. The `quoted` templates are `according to <named speaker>`, `<named speaker> said`, and `<named speaker> stated that`. A speaker ID is present only when a matching confirmed entity record exists; otherwise it is null. The cited span establishing the pattern supplies `evidence_ref`.

### 2.4 Combination rules

- `origin: document` ⇒ `assertion ∈ {stated}`.
- `origin: unknown` ⇒ `assertion: unspecified`, `attribution.role: unattributed`. It cannot back a `stated` block or support any absence claim; the record remains stored, tiered and visible to analysts.
- `origin: docintel_deterministic` ⇒ `assertion ∈ {derived, absent}`.
- `origin: docintel_ai` ⇒ `assertion ∈ {stated, derived, inferred}`; `stated` requires verification to have passed (§14).
- `assertion: absent` ⇒ `origin: docintel_deterministic` **and** §12 satisfied. No other combination may assert absence.

Origin is assigned from stored evidence alone, never model-reported origin fields. Normalize using `EvidenceMerger::normalize` semantics. Assign `document` only when the normalized `value` is **non-empty** and is a substring of the normalized cited quote, the normalized `period` (when present) is also a substring of that quote, and `due_date` (when present) passed the existing `EvidenceSchema` date-grounding check. Empty or whitespace-only values never satisfy the substring rule. Otherwise assign `unknown`.

### 2.5 Storage

Additively, per record: `data.provenance = {origin, assertion, attribution}`, including `unknown` / `unspecified` when directness cannot be established. Per Brief block: fields on the block (§13.2).

---

## 3. Legacy explicit / inferred mapping

V1's `basis: "explicit" | "inferred"` (`SynthesisSchema`, validated at `ResponseValidator.php:369`) and V1's `date_type` are both retained **unchanged in the API** and are derived from the V2 axes. The mapping is total and deterministic in both directions where V1 can represent the V2 state.

### 3.1 V1 `basis` → V2 axes (reading legacy rows)

| V1 `basis` | `origin` | `assertion` | `attribution` |
|---|---|---|---|
| `explicit` | `document` | `stated` | `{speaker: null, role: "unattributed", reported: false}` |
| `inferred` | `docintel_ai` | `inferred` | `{speaker: null, role: "unattributed", reported: false}` |
| absent / null | `docintel_ai` | `inferred` | `{speaker: null, role: "unattributed", reported: false}` |

A legacy row carries no attribution information, so attribution is `unattributed` — not reconstructed, not guessed.

### 3.2 V2 axes → V1 `basis` (writing the legacy field)

| `origin` | `assertion` | V1 `basis` emitted |
|---|---|---|
| `document` | `stated` | `explicit` |
| `docintel_deterministic` | `derived` | `explicit` |
| `docintel_deterministic` | `absent` | `explicit` |
| `docintel_ai` | `stated` | `explicit` |
| `docintel_ai` | `derived` | `inferred` |
| `docintel_ai` | `inferred` | `inferred` |
| `unknown` | `unspecified` | `inferred` |

The rule is: **`explicit` means "a reader can check this against the cited records"; `inferred` means "this is a reading".** `docintel_deterministic` maps to `explicit` because a derived delta is checkable arithmetic over cited numbers.

### 3.3 The five legacy summary arrays

`keyFindings`, `criticalRisks`, `upcomingDeadlines`, `importantEntities`, `recommendedAttention` are plain strings with no grounding (audit §9.2). They:

- **remain exactly as they are**, same column, same camelCase API key, same content, same caps;
- are treated by V2 as `origin: docintel_ai`, `assertion: inferred`, `attribution: unattributed`, `cites: []`;
- are **never** promoted into Brief blocks, takeaways or any Tier assignment;
- are mirrored, where a V2 Brief block covers the same ground, by that block — the two coexist. V2 does not rewrite them and does not deduplicate against them.

V2 additionally exposes `analysis.overview.summaryNotes` (`supported: false`) for ungrounded synthesis prose, which is the mechanism the frontend already declares for exactly this.

### 3.4 Legacy `date_type` → date roles

See §4.5.

---

## 4. Date roles

V1 has one date slot (`due_date`) plus a three-value `date_type` that mixes resolution and epistemics. V2 introduces named roles. `due_date`'s existing meaning is preserved exactly.

### 4.1 Roles

| Role | Meaning |
|---|---|
| `due_date` | The date by which an obligation must be met. |
| `effective_date` | When a term, policy or agreement takes effect. |
| `expiry_date` | When it ceases to apply. |
| `issued_date` | When the document or the referenced instrument was issued or signed. |
| `as_of_date` | The point in time a measurement describes ("as at 31 March 2025"). |
| `period_covered` | The reporting period a measurement or statement covers. |
| `observed_date` | When a reported event occurred. |
| `review_date` | A scheduled review or reassessment point. |

A record may carry several roles. `data.typed.dates` is a map from role to `TypedValue`.

### 4.2 Resolution

Each date-role `TypedValue` carries a resolution, derived from which temporal field is populated:

| `resolution` | Condition |
|---|---|
| `calendar` | `date` non-null. A complete, unambiguous date literally stated in the cited evidence (§1.2 V3). |
| `period` | `period` non-null, `date` null. A partial period: "FY2025", "Q3 2026". |
| `relative` | `duration` non-null, `date` null. "within 30 days after execution". |
| `unknown` | The role is asserted but no temporal value could be typed. |

`resolution` is a property of the **date**, not of the record's epistemics. A period-resolved due date may be `document` / `stated` when §2.4 establishes directness; otherwise its record is `unknown` / `unspecified`.

### 4.3 The period-only obligation (closing audit §3.2 / R10)

Before the Stage A validator fix, V1 rejected a `deadline`/`obligation` record whose `date_type` was null. Newly extracted incremental-route documents now retain grounded period-only obligations regardless of the V2 flag. V2 represents one as:

```
kind: "obligation"
data.typed.dates.due_date = { type: "period", resolution: "period",
                              period: {text: "Q3 2026", grain: "quarter", ...} }
data.provenance = { origin: "document", assertion: "stated", ... } # only when §2.4 directness passes
```

**Compatibility:** the derived `document_deadlines` row for such a record is written with `date_type = 'relative'`, `due_date = NULL`, `relative_text = <period text>`. This satisfies the existing `NOT NULL` + three-value CHECK without a migration and without changing what `due_date` means. The V2 record carries the accurate `resolution: "period"`; the legacy row carries the closest representable value. The imprecision is confined to the legacy row, and is disclosed in the record's `legacy_mapping` note.

This is a deliberate correctness fix to legacy behaviour, independent of the V2 flag and limited to newly extracted incremental-route documents. The normal route still does not retain period-only obligations. Flag-off conformance snapshots must use post-fix code or fixtures without period-only records.

### 4.4 Role inference rules

Roles are assigned deterministically from the cited span's surrounding text using a fixed, versioned pattern set owned by the same config as the scorer (§9). No provider call. When no pattern matches:

Match the cited quote case-insensitively with English word boundaries. The pattern nearest the date mention in characters wins; an exact distance tie yields role `unresolved`. `unresolved` never counts as a deadline, defaults to `due_date`, or triggers a date forced rule. The V1 patterns are:

| Role | Literal patterns |
|---|---|
| `effective_date` | `effective from`; `effective on`; `with effect from`; `takes effect`; `comes into force` |
| `due_date` | `due by`; `due on`; `due in`; `deadline`; `no later than`; `must be ... by` with up to 6 intervening words |
| `expiry_date` | `expires`; `valid until`; `terminates on` |
| `issued_date` | `signed on`; `executed on`; `issued on` |
| `as_of_date` | `as at`; `as of` |
| `period_covered` | `for the year ended`; `for the period` |
| `observed_date` | `occurred`; `struck`; `was held`; `took place` |
| `review_date` | `review date`; `to be reviewed` |

Only when no pattern matches, use the following fallback:

- `kind ∈ {deadline, obligation}` → the record's primary date takes role `due_date`;
- `kind = metric` → `period_covered` if a period was typed, else `as_of_date` if a calendar date was typed;
- otherwise → `observed_date`.

### 4.5 Legacy `date_type` mapping

**Reading V1 rows → V2:**

| V1 `date_type` | V1 `due_date` | V2 role | `resolution` | `origin` / `assertion` |
|---|---|---|---|---|
| `explicit` | set | `due_date` | `calendar` | `document` / `stated` only if §2.4 directness passes; otherwise `unknown` / `unspecified` |
| `relative` | null | `due_date` | `relative` (if a duration parses from `relative_text`) else `unknown` | `document` / `stated` only if §2.4 directness passes; otherwise `unknown` / `unspecified` |
| `inferred` | null | `due_date` | `relative` or `unknown` | `docintel_ai` / `inferred` |

Note that V1's `inferred` carries epistemic information that V2 moves onto the `assertion` axis; the *date* is simply unresolved. This is the conflation §2 exists to undo.

**Writing V1 rows from V2:**

| V2 `resolution` | V2 `assertion` | `date_type` emitted | `due_date` emitted |
|---|---|---|---|
| `calendar` | `stated` or `derived` | `explicit` | the date |
| `calendar` | `inferred` | `inferred` | `NULL` — V1 forbids a `due_date` on a non-explicit row |
| `calendar` | `unspecified` | `inferred` | `NULL` — direct assertion is unestablished |
| `period` | any | `relative` | `NULL` |
| `relative` | `stated` or `derived` | `relative` | `NULL` |
| `relative` | `inferred` | `inferred` | `NULL` |
| `relative` | `unspecified` | `inferred` | `NULL` |
| `unknown` | any | `inferred` | `NULL` |

The invariant "a non-null `document_deadlines.due_date` is a complete calendar date literally stated in the cited evidence" is preserved by every row of this table.

---

## 5. Materiality tiers

> **Revised (CR-006 and CR-008).** A materiality model already exists: `ImportantFindingsBuilder::TIERS` (seven usefulness tiers), plus `TakeawayBuilder`'s quotas and `ChartCandidateBuilder::score()` — three independent mechanisms (audit §9.3, R16). V2 unifies them. The existing `importantFindings` selected set, non-tie order, takeaway selection and chart order remain calibration gates; exact-score V1 confidence/reference tie order is deliberately replaced by §9.5. Tier 1 has the separate attention compatibility rule in T9.

### 5.0 Mapping onto the existing seven tiers

V2's four presentation tiers are a banding of the existing usefulness classes. This mapping does **not** mean `importantFindings == Tier 1`. `importantFindings` remains the top-ranked findings selected for the legacy surface: top `MAX` by materiality score, subject to `MAX_PER_STEM` and per-kind rules for non-forced items. It is selected independently of Tier 1 membership. Tier 1 is a separate materiality/attention concept.

| Existing `ImportantFindingsBuilder` class | Existing value | V2 tier |
|---|---|---|
| `critical_risk` | 0 | 1 `attention` |
| `high_risk`, `upcoming_obligation` | 1 | 1 `attention` |
| `dated_obligation` | 2 | 2 `substantive` |
| `undated_obligation`, `risk` | 3 | 2 `substantive` |
| `metric`, `fact` | 4 | 3 `detail` |
| `definition`, `entity` | 5 | 3 `detail` |
| `other` | 6 | 4 `background` |

Two behaviours remain:

- **Confidence is not a direct V2 score, tier, forced-rule or §9.5 tiebreak input.** It can affect tiering indirectly: existing chart-group resolution uses confidence, and the approved `comparability` signal uses membership in a valid chart group. The approved §9.5 order replaces V1 confidence/reference order on exact-score ties.
- **A finding the document's own synthesis cited is promoted exactly one tier**, and no further. Preserved as the `cited_by_synthesis` signal, capped at one tier of movement.

### 5.1 Tiers

| Tier | Name | Meaning |
|---|---|---|
| 1 | `attention` | A reader who reads nothing else must read this. Bounded (§7). |
| 2 | `substantive` | Material to understanding the document. Shown by default in its section. |
| 3 | `detail` | Supporting detail. Available, collapsed, paginated. |
| 4 | `background` | Boilerplate, repetition, structural noise. Retained, never surfaced by default. |

Tier 4 records are **never deleted**. Tiering is a presentation decision, not a retention decision.

### 5.2 Assignment

Every record gets exactly one tier, from `MaterialityScorer` (§9), as:

```
TierAssignment {
  tier: 1 | 2 | 3 | 4
  score: float                  # raw score before banding
  forced: bool                  # true when a forced-item rule placed it in Tier 1 (§7.3)
  forced_rule: string | null    # the rule id, when forced
  reasons: [ {signal: string, weight: float, contribution: float} ]   # ordered, complete
  scorer_version: string        # §9.4
  config_version: string        # §9.4
}
```

`reasons` must account for the whole score: `sum(contribution) == score` within float tolerance. A tier the system cannot explain is a bug, not a tier.

### 5.3 Tier is not confidence

`confidence` is a model-supplied number the extraction prompt itself disclaims ("Confidence is not evidence"). It is **not** a scorer signal. Existing chart-group resolution can nevertheless use it to choose a representative observation; membership then feeds `comparability` (§9.3), so an indirect tier effect is possible. It remains on derived rows and remains the sort order of the three existing paginated endpoints, unchanged (§18.3).

---

## 6. (reserved)

*Section number reserved so §7–§22 keep stable numbering if §5 is split by a change request.*

---

## 7. Tier 1 budget and forced-item rules

The V2 normal budget has target 8 and maximum 10. Forced items use a separate maximum of 8. The existing `MAX_PER_KIND = 3` cannot exclude a fourth forced critical risk.

### 7.1 Budget

```
intelligence_v2.tier1 = [
  'min'        => 5,    # below this, Tier 1 is widened from the top of Tier 2
  'target'     => 8,    # = the existing TakeawayBuilder/ImportantFindingsBuilder MAX
  'max'        => 10,   # normal items only; ceiling of the approved 5-10 range
  'forced_max' => 8,    # forced items beyond the normal budget
  'hard_cap'   => 18,   # absolute ceiling: max + forced_max
  'per_kind'   => 3,    # existing ImportantFindingsBuilder::MAX_PER_KIND
  'per_stem'   => 2,    # existing ImportantFindingsBuilder::MAX_PER_STEM
  'imminent_days' => 90,
  'recompute_after_hours' => 24,
  'origin_quotas' => ['synthesis' => 4, 'metric' => 3, 'trend' => 2, 'risk' => 2, 'obligation' => 1],
]
```

**`per_kind`, `per_stem` and `origin_quotas` bind normal items only. A forced item is never excluded by any of them** — that is the whole point of §7.3, and the behaviour change from today.

- **Normal items**: at most `max` (10), selected as the top-scoring records not already forced.
- **Forced items**: admitted in addition to the normal budget, up to `forced_max`.
- When fewer than `min` records qualify in total, Tier 1 holds however many qualify — it is **never padded** with low-scoring items to reach a count. A thin document gets a short Tier 1 and says so (§10).
- When forced items exceed `forced_max`, they are ordered by forced-rule priority (§7.3), the first `forced_max` are admitted, and the remainder stay in Tier 2 with `overflow_from_forced: true` recorded. The overflow count is surfaced in the attention state (§11.4). **Forced items are never silently dropped.**
- `hard_cap` is an assertion, not a selection step: exceeding it is a contract violation and must fail the conformance fixture (§22).

### 7.2 Selection order

1. Evaluate every forced-item rule over every record. Collect forced items.
2. Score all remaining records. Sort descending by score, then by a deterministic tiebreak (§9.5).
3. Admit normal items until `max` is reached or scores fall below the Tier 1 threshold (§9.2).
4. If total admitted is below `min`, continue admitting the next-highest Tier 2 records until `min` is reached **or Tier 2 is exhausted**.
5. Order the final Tier 1 for presentation: forced items first, by rule priority; then normal items by score.

### 7.3 Forced-item rules

Each rule is a **deterministic predicate over one record**, identified by a stable id, with a priority. All rules live in the same versioned config as the weights (§9). None requires a provider call.

| Priority | Rule id | Predicate |
|---|---|---|
| 10 | `critical_risk` | `kind = risk` **and** `severity = critical`, except historical risks defined below |
| 20 | `imminent_dated_obligation` | `kind ∈ {deadline, obligation}` **and** a `due_date` role with `resolution = calendar` **and** that date is within `tier1.imminent_days` (default 90) of "now" **and** the derived row's `status = open` |
| 30 | `overdue_dated_obligation` | as above, but the date is in the past and `status = open` |
| 40 | `penalised_obligation` | `kind = obligation` **and** a `ValueParser` money or percent value from the same cited quote as a penalty/consequence pattern |
| 50 | `high_risk` | `kind = risk` **and** `severity = high`, except historical risks defined below |
| 60 | `regulator_attributed` | `attribution.role ∈ {regulator, auditor}` **and** `assertion = stated` |
| 70 | `headline_measure` | `kind = metric`, typed `unit_kind = currency`, non-null currency and valid scale-applied canonical number; pre-forcing scored Tier ≤ 2; at least two valid metrics in the document's same `unit_kind` + `currency` group; and the largest absolute canonical magnitude in that group. Exact magnitude ties use §9.5. Chart candidacy, participation, eligibility and confidence do not enter this predicate. Non-currency metrics can still reach Tier 1 through ordinary scoring. |
| 80 | `unresolved_material_reference` | `kind = unresolved` **and** no `resolved_evidence_id` **and** the span lies inside a Tier ≤ 2 neighbourhood as defined below |

A historical risk has an `observed_date` resolving to a past calendar date or to an anchored period ending before `asOf`, and no other record citing the same span has a future date role or open status. Its kind and scored tier remain intact, but rules 10 and 50 do not force it. An unresolved or missing observed date does not qualify. This V1 rule cannot see a continuing consequence grounded only in a different span; it makes no semantic or cross-span inference.

For rule 80, a neighbour must have a **pre-forcing scored tier** of 2 or better and share the section defined by the nearest preceding heading span. If section data is unavailable, it may instead share a page. If neither section nor page is available, rule 80 does not fire. Using pre-forcing tiers avoids circularity.

The penalty/consequence patterns are case-insensitive with word boundaries: `penalty`, `penalties`, `liquidated damages`, `late fee`, `default interest`, `forfeit`, `service credit`, `termination for`.

Rules 20 and 30 depend on "now", so a tier assignment has a validity window. A Tier 1 computed more than `tier1.recompute_after_hours` (default 24) ago is stale and must be recomputed on read; recomputation is deterministic and free.

### 7.4 Invariants

- **T1** `count(normal) <= tier1.max`.
- **T2** `count(forced) <= tier1.forced_max`.
- **T3** `count(Tier 1) <= tier1.hard_cap`.
- **T4** Every forced item has `forced: true` and a non-null `forced_rule`.
- **T5** No record appears in Tier 1 both as forced and as normal.
- **T6** Tier 1 is never padded above the number of qualifying records.
- **T7** Tier 1 ordering is fully determined by (forced-rule priority, score, tiebreak) — no randomness, no insertion order.
- **T8** `per_kind`, `per_stem` and `origin_quotas` never exclude a forced item.
- **T9a — importantFindings compatibility.** For the existing corpus, the V2 `importantFindings` list preserves the V1 selected set and order except at an exact-score tie group crossing a `MAX`, `MAX_PER_STEM` or per-kind selection boundary. A tie group remains tied through materiality score and all higher-priority selection semantics. Select the first N under §9.5 and require the same number from that tied group, but do not require V1 identities chosen by confidence/reference ordering. No lower-scoring record may displace a higher-scoring record. Outside this exception, selected set and order are strict. The list is top `MAX` by score, subject to `MAX_PER_STEM` and per-kind rules for non-forced items, independently of Tier 1 membership.
- **T9b — attention compatibility.** Tier 1 contains every record V1 classifies as `critical_risk`, `high_risk` or `upcoming_obligation`. Tier 1 may be smaller than `importantFindings` and is never padded (T6). T9 places no tier-membership constraint on metrics, facts, definitions or entities. Chart candidate order remains strict. V2 takeaway selection applies V1's candidate order, duplicate rule, minimum length and quotas to **V2-formatted candidate text** (CR-010), then omits synthesis-derived candidates that fail §14 verification (CR-014). V1 flag-off output, including its integer-formatting defect, remains unchanged.

---

## 8. (reserved)

---

## 9. Scorer location and versioning

### 9.1 Location

`App\Services\Intelligence` already exists (audit §5.3.1). V2 adds a `Materiality` sub-namespace inside it:

```
app/Services/Intelligence/Materiality/MaterialityScorer.php     # the only scorer
app/Services/Intelligence/Materiality/ForcedItemRules.php       # §7.3 predicates
app/Services/Intelligence/Materiality/Signals/                  # one class per signal
config/intelligence_v2.php                                      # every weight and threshold
```

The three existing ranking mechanisms become callers of this service, subject to the CR-006 exact-score tie-order exception (§5.0, §7.1, T9):

| Existing | After |
|---|---|
| `ImportantFindingsBuilder::TIERS` + `classify()` | reads `TierAssignment`; the tier table moves to config at its current values |
| `TakeawayBuilder::QUOTAS` / `MAX` | reads `tier1.origin_quotas` / `tier1.target` at their current values |
| `ChartCandidateBuilder::score()` | keeps its own shape-specific score; may consume the materiality score per §16.3a |

`MaterialityScorer::score(IntelligenceRecord $record, ScoringContext $context): TierAssignment`

- **Pure.** No database access, no `now()` except through `$context->asOf`, no HTTP, no container lookups beyond injected config. Given the same record, context and config version, it returns the same `TierAssignment` byte-for-byte.
- `ScoringContext` carries only what a signal legitimately needs: the document's record-set aggregates (counts per kind, magnitude ranges per comparable group), the coverage state, `asOf`, and the resolved config. It is constructed once per document.
- **No weight, threshold, cap, day count or pattern list may appear anywhere else.** A literal in a scorer, a job, a resource or a view is a contract violation.

`EvidenceBudget::forDocument()`'s existing five-arm `match` (audit §4.1, R11) is **superseded as an ordering function** when V2 is enabled: the budget loop consumes the scorer's order instead. The 16 000-byte budget itself is unchanged and still bounds the payload — **a scorer that promotes more items must never be able to enlarge a provider request** (§19.4).

### 9.2 Thresholds

```
intelligence_v2.materiality = [
  'version'    => '1',
  'class_base' => ['critical_risk' => 0.94, 'high_risk' => 0.82, 'upcoming_obligation' => 0.82,
                   'dated_obligation' => 0.64, 'undated_obligation' => 0.56, 'risk' => 0.56,
                   'metric' => 0.36, 'fact' => 0.36, 'definition' => 0.24,
                   'entity' => 0.24, 'other' => 0.08],
  'weights'    => ['severity' => 0.010, 'date_proximity' => 0.010,
                   'date_resolution' => 0.005, 'attribution_authority' => 0.005,
                   'obligation_consequence' => 0.010, 'structural_prominence' => 0.020,
                   'monetary_magnitude' => 0.060, 'relative_magnitude' => 0.020,
                   'comparability' => 0.020, 'repetition_penalty' => -0.020,
                   'boilerplate_penalty' => -0.040, 'unresolved_penalty' => -0.020,
                   'cited_by_synthesis' => 0.000],
  'bands'      => ['tier1' => 0.72, 'tier2' => 0.45, 'tier3' => 0.18],  # score >= band
  'signals'    => ['date_proximity' => ['imminent_days' => 90,
                   'horizon_days' => 365, 'floor_value' => 0.2],
                   'structural_prominence' => ['heading_span_types' => ['heading'],
                                               'ordinal_first_fraction' => 0.10],
                   'boilerplate_penalty' => ['boilerplate_span_types' => []]],
]
```

Bands are score thresholds; Tier 1 membership additionally requires passing §7's budget. A record above the Tier 1 band that does not fit the budget lands in Tier 2 with `band_qualified: true` recorded, so "we had more Tier 1 candidates than the budget allows" is observable. `class_base` uses today's `ImportantFindingsBuilder::classify()` result with injectable `asOf`; unresolved and unknown classes use `other`. Compute `raw = class_base[kind_class] + Σ(weight[signal] × value[signal])`, then `score = clamp(raw, 0.0, 1.0)`. Never round stored values. A synthesis citation promotes a record exactly one tier after banding, subject to §7's budget; the zero-weight citation signal does not change the score.

### 9.3 Signals

Each signal yields a value in `[0, 1]` before multiplication by its configured signed weight. The initial signal set (ids are contract; values are config):

`kind_class`, `severity`, `date_proximity`, `date_resolution`, `monetary_magnitude`, `relative_magnitude`, `attribution_authority`, `obligation_consequence`, `structural_prominence` (heading type / early document ordinal), `repetition_penalty`, `boilerplate_penalty`, `cited_by_synthesis`, `comparability` (participates in a valid comparison group), `unresolved_penalty`.

The exact persisted `SourceSpanBuilder` type vocabulary is `heading`, `section`, `table_row`, `list_item`, `sentence`. Its intermediate `prose` classification becomes `sentence` before persistence; no aliases are inferred. Only `heading` is unambiguously heading-like. Materiality v1 has no explicit table-header, header, or footer type. Table-header prominence is unavailable; `boilerplate_penalty` is inactive until a later config/materiality version because the current vocabulary cannot represent header/footer semantics. Unavailable span-derived branches are skipped rather than guessed.

`confidence` is deliberately **not** a direct signal, tier or forced-rule input (§5.3). `ChartCandidateBuilder` uses confidence when resolving competing observations; this can change valid chart-group membership and therefore the approved `comparability` signal, indirectly affecting V2 score and tier. Reasons list `kind_class` first with its base contribution, then non-zero contributing signals and skipped unavailable signals (contribution 0, `skipped: true`), then a non-zero `clamp` contribution (`score - raw`), then `tier_adjustment` (contribution 0) when applicable. Their contributions sum to the stored score within 1e-9.

Signal values are deterministic:

| Signal | Value rule |
|---|---|
| `severity` | critical 1.0; high 0.7; medium 0.35; low 0.1; otherwise 0. |
| `date_proximity` | For a calendar date or anchored-period end while status is open (derived status `open` or absent): past and 0–90 days ahead 1.0; from 90 to 365 days ahead `1.0 - 0.8 × (days - 90) / (365 - 90)`; beyond 365 days 0.2. Otherwise 0. |
| `date_resolution` | calendar 1.0; period 0.6; relative 0.3; unknown 0. |
| `attribution_authority` | regulator/auditor 1.0; counterparty 0.6; quoted/third_party 0.3; otherwise 0. |
| `obligation_consequence` | 1.0 only for an obligation whose cited quote both matches a §7.3 penalty pattern and yields a money or percent `TypedValue` via `ValueParser`; otherwise 0. |
| `structural_prominence` | Cited span type `heading`: 1.0; ordinal within first 10% of document spans: 0.5. When both apply use the maximum, 1.0, never their sum. If no heading-like emitted type exists, skip that branch with `reason: span_type_unavailable` while keeping the ordinal rule available. No span data at all skips the entire signal. No table-header inference. |
| `monetary_magnitude` | Currency metrics only, grouped by unit_kind + currency. Rank by canonical magnitude after scale, counting strictly smaller group members; value `rank / (group_size - 1)`, or 0.5 for a singleton. |
| `relative_magnitude` | Numeric metrics grouped by unit_kind + currency: `abs(number) / max(abs(number))` in group; 0 if maximum is 0. |
| `comparability` | 1.0 if the record participates in a valid group under existing `ChartCandidateBuilder` rules; otherwise 0. Eligibility and candidate construction stay unchanged. |
| `repetition_penalty` | 1.0 for every duplicate after the first in §9.5 order, grouped by normalized kind + label + value + period; otherwise 0. |
| `boilerplate_penalty` | Materiality v1 config has `boilerplate_span_types: []`; contribution 0.0, `skipped: true`, `reason: span_type_unavailable`. No replacement heuristic. Deferred to a later config/materiality version. |
| `unresolved_penalty` | 1.0 for an unresolved record with no `resolved_evidence_id`; otherwise 0. |
| `cited_by_synthesis` | 0 in score sum; one post-banding tier promotion when the document's own synthesis cites the record's `source_id`. |

### 9.4 Versioning

Three independent version strings, all stamped onto every artefact that depends on them:

| Version | Changes when | Effect of a change |
|---|---|---|
| `materiality.version` | any weight, band, signal set or forced rule changes | tier assignments recomputed on next read; **no provider call** |
| `values.parser_version` | `ValueParser` behaviour changes | typed values recomputed; **no provider call** |
| `brief.template_version` | a deterministic Brief template changes | deterministic blocks regenerated; **no provider call** |

None of these is part of `pipeline_key`. **Bumping any of them must never re-extract a document** (audit R2). Any proposal that would require re-extraction is a change request and needs explicit approval (§19.3).

### 9.5 Determinism and tiebreaks

The V2 total order is, in sequence: (1) forced-rule priority (absent = 999), (2) tier band, (3) `kind` in `[obligation, deadline, risk, metric, fact, definition, entity, unresolved]`, (4) earliest `sources[0].start_offset`, (5) earliest `sources[0].page`, null last, (6) `sources[0].end_offset`, (7) normalized label ascending using `EvidenceMerger::normalize`, (8) `identity` ascending. Identity is a sha256 and makes the order total. Unavailable offsets sort last. Confidence is not a direct score, tier, forced-rule or tiebreak input; §9.3 describes the indirect comparability path. V1's confidence/reference order for exact-score ties is deliberately not preserved (CR-006/CR-009). Literal patterns remain config-driven.

### 9.6 Testability

`MaterialityScorer`, each signal, `ForcedItemRules` and `ValueParser` are unit-testable in isolation with no database and no HTTP. Required unit coverage before V2 ships behind its flag:

- each signal: boundary inputs and the zero case;
- each forced rule: positive, negative, and the boundary (e.g. exactly `imminent_days`);
- budget invariants T1–T7 (§7.4), including the forced-overflow and under-`min` paths;
- `reasons` completeness (`sum(contribution) == score`);
- tiebreak totality: no two distinct records can compare equal;
- `parser_version` stability: a frozen corpus of `raw` strings maps to frozen `TypedValue`s.

---

## 10. Coverage states

V1 emits a nine-field coverage array plus a prose warning appended into `executive_summary` (audit §4.5). V2 keeps every field and adds a single state.

### 10.1 States

| State | Condition |
|---|---|
| `complete` | V1's `comprehensive` is true: `evidence_omitted`, `unresolved_references`, `failed_chunks`, `dropped_records` and `saturated_chunks` are all zero — **and** `source_text = "full"`, with every required observable stage fact known. `"excerpts"` is bounded. |
| `bounded` | Nothing failed, but evidence was trimmed, source context reduced, or a required stage fact is unobservable. Unknown required facts add stable `<fact>_unknown` reasons. |
| `partial` | Something could not be processed: any of `failed_chunks`, `dropped_records`, `saturated_chunks`, `unresolved_references` is non-zero. |
| `unavailable` | No usable evidence exists, or synthesis terminally failed: `ai_pipeline.synthesis ∉ {completed}` and no summary is served. |

### 10.2 Shape

```
CoverageState {
  state: "complete" | "bounded" | "partial" | "unavailable"
  evidence_total, evidence_omitted, unresolved_references,
  failed_chunks, total_chunks, dropped_records, saturated_chunks: int   # V1 fields, unchanged
  comprehensive: bool                                                   # V1 field, unchanged
  source_text: "full" | "excerpts" | "omitted"                          # V1 field, unchanged
  synthesis_level: int                                                  # V1 field, unchanged
  warning: string | null                                                # V1 field, unchanged
  reasons: [string]        # NEW: stable reason codes, e.g. ["evidence_trimmed", "chunk_failed"]
  tier1_truncated: bool    # NEW: §7.1 forced overflow or band-qualified records excluded
  stages: {               # NEW; review is reserved and not emitted
    ingestion: StageCoverage,
    extraction: StageCoverage,
    synthesis: StageCoverage
  }
}
StageCoverage {
  status: "complete" | "bounded" | "partial" | "unavailable" | "unknown"
  unknown_facts: [string]
  ...stage-specific numeric diagnostics: int | null # unknown is null, never 0
}
```

### 10.3 Rules

- **C1** `state` is derived; it is never stored as the source of truth. The V1 counters remain authoritative.
- **C2** The V1 `warning` string keeps being appended to `executive_summary` exactly as today, for backward compatibility. V2 additionally exposes the structured state so a client can stop depending on prose.
- **C3** `state ≠ complete` forbids every `assertion: absent` block (§12).
- **C4** `state: partial` or `unavailable` must be visible on any surface that shows Tier 1, including exports.
- **C5** A document whose route is `normal` has no chunk-level counters; its coverage is computed per §21.
- **C6** Each stage is `partial` for any observed non-zero failure counter; otherwise `unavailable` with no usable data; otherwise `bounded` if trimmed/reduced; otherwise `unknown` for an unobservable required fact; otherwise `complete`. Only facts `CoverageStateBuilder` can currently observe may populate diagnostics. List unobservable facts; do not instrument extraction. Existing top-level V1 integer counters retain their type and semantics; unknown new-stage numeric diagnostics are `null`. A required unknown fact prevents top-level `complete`, producing `bounded` and `<fact>_unknown` in `reasons`.

The current builder observes `pipeline.route`, `pipeline.synthesis`, summary availability, `tier1_truncated`, and the stored top-level coverage counters/fields shown in §10.2. It cannot observe ingestion completeness or failure counts, per-stage extraction attempt/failure counts beyond the existing aggregate chunk counters, or per-stage synthesis request/failure counts. Those stage facts stay in `unknown_facts`; missing numeric diagnostics are `null`. The existing top-level counters retain their V1 zero fallback for compatibility, but that fallback never proves an unknown stage fact was observed.

---

## 11. Attention states

### 11.1 Per-item

```
AttentionState {
  state: "needs_attention" | "watch" | "informational" | "resolved"
  reasons: [string]          # stable codes, from the forced-rule ids and signal ids
  due: "YYYY-MM-DD" | null   # only when a due_date role resolves to calendar
  as_of: ISO-8601            # when this state was computed
}
```

| State | Condition |
|---|---|
| `needs_attention` | Tier 1 **and** forced by `critical_risk`, `high_risk`, `overdue_dated_obligation`, `imminent_dated_obligation` or `penalised_obligation`. |
| `watch` | Tier 1 by any other rule or by score; or Tier 2 with a `due_date` role resolving to `calendar` or `period` in the future. |
| `informational` | Everything else surfaced. |
| `resolved` | The derived row's `status` is a terminal non-open value (`risks`: `mitigated`/`closed`; `deadlines`: `met`/`missed`), **or** an `unresolved` record acquired a `resolved_evidence_id`. |

Historical risk exception: a risk with `observed_date` resolving to a past calendar date or an anchored period ending before `asOf`, and with no other same-span record carrying a future date role or open status, keeps its risk kind and scored tier but has `informational` attention with reason `historical_context`. Missing or unresolved observed dates never qualify. A continuing consequence grounded only in a different span may fail to suppress this conservative V1 exception; there is no semantic or cross-span inference.

### 11.2 Document-level

```
AttentionSummary {
  state: "needs_attention" | "watch" | "clear" | "unknown"
  needs_attention_count, watch_count: int
  next_due: "YYYY-MM-DD" | null
  coverage: CoverageState            # §10, repeated so a client needs one read
  forced_overflow: int               # §7.1
}
```

- `needs_attention` when any item is; else `watch` when any item is; else `clear` **only when `coverage.state = complete`**; otherwise `unknown`.
- **A document with incomplete coverage is never `clear`.** `clear` is an absence claim and falls under §12.

### 11.3 Interaction with existing deadline attention

`docs/DEADLINE_ATTENTION.md`, `TrackedItem` and `SendTrackedDeadlineReminder` act only on an explicit `due_date`. V2's attention states are **read-only and additive**: they do not create, modify or suppress tracked items or reminders. Wiring attention into reminders is out of scope and requires a change request.

### 11.4 Disclosure

`forced_overflow > 0` or `coverage.tier1_truncated` must be shown wherever Tier 1 is shown, as "more items met the attention threshold than are shown". Silently truncating attention is a contract violation.

---

## 12. Negative-claim guard

### 12.1 The rule

A Brief block, takeaway, summary note or export line may assert that the document **does not** contain something — "no material risks were identified", "the document does not disclose a penalty", "there are no upcoming deadlines", "all obligations are met" — only when **all** of the following hold:

1. `coverage.state = complete` (§10.1). `bounded`, `partial` and `unavailable` all forbid it.
2. A deterministic absence check ran over the complete record set for the current `pipeline_key` and returned zero matches for a **declared, named predicate** (e.g. `risk_severity_in(high, critical)`).
3. The block carries `origin: docintel_deterministic`, `assertion: absent`, and `absence_check: {predicate: <id>, scope: <id>, matched: 0}`.
4. The block's text is produced from a deterministic template (§13.5). **An AI-written sentence may never carry `assertion: absent`.**

### 12.2 Failure behaviour

If any condition fails, the block is not emitted. There is no softened variant, no "appears to contain no…", no hedged rewrite. If a bounded statement is wanted, it must be a positive statement about what *was* found, within the stated coverage.

### 12.3 AI prose screening

Before any `origin: docintel_ai` block is accepted, it is screened against `intelligence_v2.negative_claim.patterns`, version `1` (CR-012). Matching is English-only, case-insensitive and word-boundary based; any single match rejects the AI block with reason `negative_claim`. The version-1 groups are:

- **Existence negation:** `no <noun> (was|were|has been|have been)? (identified|found|disclosed|reported|noted|recorded|provided|mentioned)`; `none (was|were|has been)? (identified|found|disclosed|reported)`; `not (identified|found|disclosed|reported|mentioned|provided|stated|addressed)`; `(does|do|did) not (disclose|identify|mention|state|contain|include|report|address)`; `without (any )?(material |significant )?(risk|issue|finding|deadline|obligation|penalty)`; `(there is|there are|there was|there were) no`; `lack(s|ed)? (of )?`; `absence of`; `nothing (material|significant|to report)`; `fails? to (disclose|identify|mention)`; `never (disclosed|mentioned|reported)`.
- **Clean-bill phrases:** `all (obligations|risks|deadlines|items) (are|were) (met|resolved|closed|addressed)`; `no (outstanding|open|unresolved|pending|overdue)`; `fully (compliant|resolved|addressed)`; `(complete|comprehensive) coverage`.

A match is a conservative trigger, never proof that an absence assertion is valid. A separately declared deterministic predicate must pass §12.1's complete-coverage, zero-match and provenance conditions before its approved absence template may be emitted. A match alone never creates a template. If the guard fails, emit no absence block. A rejected block with a separately available **non-absence** deterministic template over the same cited records follows §14.4; otherwise omit it and count `brief.ai_blocks_rejected`. Never repair by provider call. Log only block type, reason and count, without text. The five legacy summary arrays remain unchanged (§12.4). Accepted false positives include `lack of clarity`, `not addressed in this section` and `complete coverage` used for insurance; see CR-012. No negation-scope analysis is attempted.

Stage A's existing `material_findings` and `trends` have no declared deterministic absence predicate, so a match on their V2 takeaway presentation is omitted before selection and counted in the additive `analysis.stats.briefAiBlocksRejected` diagnostic. `key_findings` mirrored into `summaryNotes` is one of the unchanged legacy arrays. The only Stage A named absence template is `absence.high_critical_risks`: the separately declared predicate `risk_severity_in(high,critical)` yields “No high or critical risks were identified.” only after the guard passes. The V2 takeaway caller can emit that deterministic template when complete coverage, a zero-match scan, known provenance and directly grounded low/medium risk citations are available. No AI wording is reused; without cited risk evidence no absence takeaway is emitted.

### 12.4 Scope of the guard

The guard applies to V2 blocks and V2 surfaces. The five legacy summary arrays (§3.3) are not screened — they are V1 content, unchanged, and screening them would be a modification. They stay labelled as ungrounded AI prose.

---

## 13. Brief block structure

The Brief replaces nothing. It is a new, additive structure served alongside `intelligenceSummary`.

### 13.1 Blocks, not prose

A Brief is an ordered list of typed blocks. There is no free-text Brief body. Every block cites record IDs.

### 13.2 Block shape

```
BriefBlock {
  id: string                  # stable within a Brief version
  type: BlockType             # §13.3
  order: int

  text: string                # the rendered sentence or phrase
  detail: string | null

  origin: "document" | "unknown" | "docintel_deterministic" | "docintel_ai" # §2.1
  assertion: "stated" | "unspecified" | "derived" | "inferred" | "absent" # §2.2
  attribution: Attribution                                           # §2.3

  cites: [string]             # record source_ids. MUST be non-empty except for
                              # coverage_note and headline.document_identity (CR-014).
  evidence: [EvidenceRef]     # §15, resolved for display
  typed: { <name>: TypedValue }   # every number or date the text states, typed and cited

  template_id: string | null  # non-null for every deterministic block
  template_version: string | null
  verification: Verification | null   # §14; non-null for every docintel_ai block
  absence_check: AbsenceCheck | null  # §12; non-null iff assertion = "absent"

  tier: 1 | 2 | 3 | 4         # the cited record's materiality tier; B1 key figures may be Tier 4
  attention: AttentionState | null
  chart_id: string | null     # links a measure block to a chart candidate (§16)
}
```

### 13.3 Block types

| Type | Purpose | Allowed `origin` |
|---|---|---|
| `headline` | What this document is and what it is about. | deterministic, ai |
| `assessment` | The overall reading. At most one per Brief. | ai (deterministic fallback) |
| `finding` | One material finding. Unknown-origin wording must carry `unspecified`, never `stated`. | document, unknown, deterministic, ai |
| `measure` | One measured value, with its period and unit. Unknown-origin records require `unspecified`. | document, unknown, deterministic |
| `timeline` | A dated or period-bound obligation or event. Unknown-origin records require `unspecified`. | document, unknown, deterministic |
| `tension` | Two cited records that pull against each other. | ai (deterministic fallback) |
| `question` | Something a reader should ask. Must stay unanswered. | ai |
| `attention` | Why an item needs attention. | deterministic |
| `coverage_note` | What the analysis could not cover. | deterministic |

- `measure`, `timeline`, `attention` and `coverage_note` are **always deterministic**. They are rendered from typed values by template and never written by a model.
- `assessment` and `tension` prefer AI and fall back to template (§14.4).
- `question` is the one AI-only type; it asserts nothing, must remain unanswered, and still requires non-empty `cites`.

### 13.4 Citation rules

- **B1** `cites` is non-empty for every block except `coverage_note` and `headline.document_identity`, which renders only document type and name (CR-014).
- **B2** Every `source_id` in `cites` must exist in the current `pipeline_key`'s record set and be one that was actually supplied to the generator. This is V1's `$availableSourceIds` check (`ResponseValidator.php:326-335`), retained and extended to every block.
- **B3** Every number and every date stated in `text` or `detail` must appear in `typed` with its own citation, and must verify (§14).
- **B4** A `tension` block cites at least two records, from at least two distinct `identity` values.
- **B5** A block whose `attribution.reported` is true must name the speaker or role in `text`. It may not present the claim as the document's own.
- **B6** Cite count per block is bounded: `intelligence_v2.brief_limits.max_cites_per_block` (default 4), matching V1's existing 1–4 `source_ids` rule.

### 13.5 Deterministic templates

Templates live in `app/Services/Intelligence/Brief/Templates/`, are identified by `template_id`, versioned by `brief.template_version`, and render **only** from `TypedValue`s and record fields. A template may not interpolate model-written text. Example shapes (wording is implementation detail; the contract is that they exist and are pure):

- `measure.period_value` — "<label>: <raw> <unit> (<period.text>)"
- `headline.document_identity` — document type and name only; it may have empty `cites` (CR-014).
- `timeline.calendar_due` — "<label> — due <date>"
- `timeline.period_due` — "<label> — due in <period.text>"
- `timeline.relative_due` — "<label> — due <duration.text>"
- `attention.overdue` — "<label> was due <date> and is still open."
- `coverage_note.partial` — renders from `CoverageState.reasons`, never from prose.

**Stage B1 key figures (CR-014).** Select independently of materiality tier, without promotion or score changes. Eligible records are document-origin currency metrics with a valid canonical numeric `TypedValue` and non-null currency. Deduplicate exact equivalents by currency, canonical number and normalized period, keeping the first under §9.5. Order total/headline labels first using case-insensitive English word boundaries and `brief.key_figures.total_label_patterns = ["total", "overall", "aggregate", "net", "gross"]`; then by the existing `monetary_magnitude` signal value descending within the unit-kind/currency group; then by §9.5. `brief.key_figures.max = 6`; emit every eligible record when fewer than six, without padding. Do not compare raw magnitudes across currencies. Omit comparisons unless a deterministic comparable prior-period record exists under V7.

### 13.6 AI identifiability

Any block with `origin: docintel_ai` is identifiable as an AI summary **in the data, not only in the UI**:

- the block carries `origin: "docintel_ai"` and a non-null `verification`;
- it carries `ai_generated: true` as an explicit boolean, so a consumer cannot miss it by not knowing the `origin` vocabulary;
- every rendering surface — API consumer, panel, Word export — must label it. The existing `AnalysisSummaryNote { supported: false }` mechanism is the precedent and remains for ungrounded prose.

A surface that shows an AI block without a label is a contract violation, and §22's fixture asserts the flag's presence, not the UI.

### 13.7 Brief assembly

1. Compute tiers (§5, §9) and Tier 1 (§7).
2. Emit deterministic blocks for every Tier 1 item: `measure`, `timeline` or `attention` as the record's kind dictates.
3. Emit `coverage_note` when `coverage.state ≠ complete`, or when `tier1_truncated`.
4. Request AI blocks (`assessment`, `tension`, `question`, and `finding` where a deterministic template cannot express the record) **within the existing synthesis call** (§19.2).
5. Verify every AI block (§14). Replace failures with deterministic fallbacks.
6. Order: `headline`, `assessment`, `attention` blocks, `timeline`, `measure`, `finding`, `tension`, `question`, `coverage_note`. Within a type, by tier then by §9.5 tiebreak.

**Stage B1 boundary (CR-014).** The read-only Brief contains only deterministic `headline`, `attention`, `timeline`, `measure` and `coverage_note` blocks and reports `ai_blocks_available: false`. No AI Brief block is generated or inserted. Existing synthesis takeaways are a separate surface; their V2 candidates pass §14 verification and may retain `ai_generated: true` when accepted. B1 creates no absence Brief block. Timeline includes open or unknown-status non-historical records with a typed due-date role, including Tier 2 watch items; attention blocks come from Tier 1 needs-attention/watch items.

---

## 14. Verification of AI blocks

This closes audit R5: V1 validates that `source_ids` exist, never that the prose agrees with them.

### 14.1 Shape

```
Verification {
  status: "passed" | "failed"
  checks: [ {check: string, status: "passed"|"failed"|"skipped", detail: string|null} ]
  failed_reasons: [string]
  verifier_version: string
}
```

### 14.2 Checks

Run in order; all are deterministic, local, and free.

| Check | Rule |
|---|---|
| `cites_available` | Every `source_id` in `cites` is in the supplied record set (V1's existing check). |
| `numbers_grounded` | Every number-shaped token in `text`/`detail` matches a `TypedValue` from a cited record, comparing canonical `number` after scale, with tolerance `brief.numeric_tolerance` (default: exact for integers and currency; last-digit tolerance for values the document itself presents as rounded, i.e. `precision ≠ exact`). |
| `dates_grounded` | Every date-shaped token resolves to a cited record's date-role `TypedValue` with `resolution: calendar`. A date the cited records do not state fails. |
| `periods_grounded` | Every period expression matches a cited `Period.text` or its normalised form. |
| `entities_grounded` | Every capitalised multi-token name matches the `value` or an `alias` of a cited entity record, or the `subject` of a cited record. Normalised with `EvidenceMerger::normalize()` semantics. |
| `units_consistent` | A number presented with a unit uses the cited record's `unit`, or a form derivable from it by scale; never a different `unit_kind` or currency. |
| `comparison_valid` | A comparative statement ("rose", "higher than", "down from") cites at least two records that are comparable per §1.2 V7, and the stated direction matches the arithmetic. |
| `negative_claim` | §12.3 screen. Any match fails. |
| `attribution_respected` | §13.4 B5. |
| `origin_assertion_consistent` | An `unknown`-origin record cannot verify a `stated` block or support an absence claim; it retains `unspecified` assertion and unattributed attribution. |
| `no_source_text_leak` | The block does not reproduce a span of cited evidence longer than `brief.max_quote_chars` without it being an explicit quote block. Preserves V1's "the model never supplies evidence text" posture. |

For Stage B1, `brief.max_quote_chars = 160` (CR-014). All eleven checks above, including `origin_assertion_consistent`, are required.

### 14.3 Outcome

`status: "passed"` requires every non-skipped check to pass. A check is `skipped` only when the block contains nothing of that category (no numbers → `numbers_grounded` is skipped).

### 14.4 Fallback

A failed block is **discarded, not repaired by a further provider call.** It is replaced by:

1. the deterministic template for the same block type and the same cited records, when one exists; else
2. nothing — the block is omitted, and the omission is counted into `brief.ai_blocks_rejected`, which surfaces in `stats` (§16.4) and in the coverage reasons.

Rejections are logged as **metadata only** (block type, failed reasons, counts) — never the text, the evidence or the response, consistent with the existing logging posture throughout the pipeline.

### 14.5 No repair call

V1 has one bounded repair call for missing `executive_summary` / `key_findings`. That call is **retained unchanged** for those two legacy required fields. V2 adds **no** repair call for Brief blocks. Verification failure means fallback, never another request. (§19.)

---

## 15. Evidence references

### 15.1 Shape

```
EvidenceRef {
  record_id: string          # document_evidence.id
  source_id: string          # "entity:12" / "risk:7" / "deadline:3" / "kpi:91" / "fact:<uuid>"
  span_id: string | null     # document_source_spans.span_key, span-grounded records only
  extraction_version: string | null   # the span set this span_id belongs to
  chunk_id: string
  start_offset: int          # into documents.extracted_text
  end_offset: int
  quote: string              # resolved by DocIntel from offsets, never model-supplied
  page: int | null           # only when unambiguous (§15.4)
  highlight: Highlight       # §15.2
}
```

Every field except `highlight` already exists in `document_evidence.sources` or is derivable from it. `EvidenceRef` is a serialisation of existing data, not new storage.

### 15.2 Highlighting — offsets and text match, never bounding boxes

Bounding boxes do not exist and cannot be obtained without re-OCR (audit §7.4, R7). **V2 requires no re-parsing and no re-OCR.** The highlight contract:

```
Highlight {
  mode: "offset" | "text_match" | "page_only" | "none"
  start_offset: int | null    # mode = offset
  end_offset: int | null
  needle: string | null       # mode = text_match: the exact string to search for
  occurrence: int | null      # 1-based index of the intended occurrence, when known
  occurrences: int | null     # total occurrences found, when known
  page: int | null            # mode = page_only
}
```

### 15.3 Mode selection

| Mode | When | Consumer behaviour |
|---|---|---|
| `offset` | The record has stored offsets **and** the consumer is rendering `extracted_text` (the in-app text view). | Highlight `[start_offset, end_offset)` directly. Exact, no searching. |
| `text_match` | Offsets exist but the consumer renders a different representation (the original PDF, a Word export, a re-flowed view). | Search for `needle` — the exact resolved quote — and highlight the `occurrence`-th match. `occurrences > 1` with unknown `occurrence` means highlight all and say so. |
| `page_only` | No usable match, but a single page is known. | Navigate to the page; show the quote beside it, unhighlighted. This is V1's current behaviour. |
| `none` | Neither offsets nor a single page are usable. | Show the quote as a detached blockquote. This is also V1's current behaviour. |

`needle` is always the quote DocIntel resolved from offsets. It is never a model-supplied string, and it is never normalised, trimmed or re-cased — a text match that fails must fail visibly rather than match the wrong text.

### 15.4 Page attribution

Unchanged from V1, deliberately conservative:

- evidence-row path: a page is reported only when `array_unique` over the sources' pages yields exactly one value;
- legacy-row path: `EvidencePageLocator` semantics — `pages >= 2`, text contains `\f`, `count(explode("\f")) === pages`, and the excerpt occurs on exactly one page;
- `EvidenceGrounding::pagesKnown()` additionally requires `type = 'PDF'` with an exact `\f` count.

Any ambiguity yields `page: null`. V2 adds no new page inference. A large share of documents will have `page: null` and every surface must degrade to `mode: none` cleanly.

### 15.5 Compatibility

The existing `evidence` map — `{source_id: {quote, sources[]}}` — and `sourcePages` — `{source_id: page}` — remain in the response **exactly as they are** (§18.2). `EvidenceRef` is served inside the new `analysis` and `brief` structures only.

---

## 16. Chart eligibility baseline

### 16.1 Scope

V2 derives **chart candidates** deterministically from metric records. It does **not** create `document_charts` rows, does not touch `document_chart_points`, and does not alter `AnalyzeEmbeddedVisualsJob` or `GenerateInsightsJob`. Existing charts and the Power BI chart feed are untouched.

> **Rewritten (clarification pass).** The first draft invented eligibility rules, thresholds and rejection codes. `ChartCandidateBuilder` already implements all of this. The contract now **records the existing behaviour** and states only what V2 may change.

### 16.2 Eligibility — as implemented

The baseline is `ChartCandidateBuilder`. Its actual constants:

```php
MAX_CATEGORIES               = 12
MAX_SERIES                   = 5
COMPOSITION_TOLERANCE_PERCENT = 3.0   // percentages summing to ~100
COMPOSITION_TOLERANCE_RATIO   = 0.02  // parts vs a stated total
MIN_COMPOSITION_PARTS        = 3
SCALE_WORDS                  = [1e12 => 'trillion', 1e9 => 'billion', 1e6 => 'million', 1e3 => 'thousand']
```

Rules, as the implementation enforces them:

- **E1** Candidates are built from accepted metric findings only (`MetricCollector` → `MetricObservation`).
- **E2** Comparability is decided **upstream** by `MetricObservation::groupKey()` = `conceptKey|measureKey`, which already requires an identical measurement family, currency, measure kind and basis. **Scale is the one difference this layer may reconcile** — USD million and USD billion are the same measurement written two ways. **Currency is never converted.**
- **E3** Time series group by normalised `subject` (`KpiLabelNormalizer`). Mixed subjects become separate series, not one line.
- **E4** Minimum **2** points per series (not 3). Maximum `MAX_CATEGORIES` (12) categories, `MAX_SERIES` (5) series.
- **E5** One granularity and one basis per time series: *"Mixed granularities (a quarter beside a year) and mixed bases (FY2024 beside calendar 2024) are never plotted together."* Where several buckets exist, the largest consistent set wins; a tie prefers more points, **never a mix**.
- **E6** In a categorical set, a total is dropped when its own parts are present, *"so one bar cannot dwarf the comparison it belongs to"* (`MetricObservation::isTotal`).
- **E7** Composition requires demonstrable parts of one whole — percentages summing to ~100 within 3 pp, or parts matching a stated total within 2 %. *"Nothing becomes a pie chart on the strength of looking like a breakdown."*

Every candidate carries `sourceIds`, so every point remains navigable to its evidence.

**Rejection counters — the existing five codes.** V2 must not rename them; the frontend renders `visualAnalysis.rejected` as a map:

```
insufficient_points, incomparable_periods, conflicting_values, not_a_composition, single_category
```

### 16.3 Basis, type and scoring — as implemented

```
basis: "time_series" | "categorical" | "composition"
```

`score()` is already deterministic and already exists:

```
base            = time_series 1.0 | composition 0.8 | categorical 0.7
+ 0.2 * min(points, 8) / 8
+ 0.15  if any sourceId was cited by the document's own synthesis
+ 0.10  if time_series and |(last - first) / first| >= 0.05
```

Sorted by `[score desc, title asc]`; `DocumentAnalysisComposer::MAX_CHARTS = 12` are returned, the remainder counted into `omitted`.

`type` remains a **suggestion**. `CA/src/components/chartLayout.ts` owns final rendering and overrides it (pie overflow > 6 slices → table; sequential-looking bar with ≥ 4 points and ≥ 0.75 matching labels → line). V2 does not duplicate, bypass or contradict that logic.

### 16.3a What V2 may change here

Only these, and each needs a change request because each alters what users see:

1. Move the constants above into `config/intelligence_v2.php` under `charts.*`, **preserving every current value**, so they become versioned and testable (§9.1).
2. Feed `score()` the unified materiality score (§9) in place of, or alongside, its `cited` bonus — **only if** fixture case `14-chart-eligible` shows the candidate order unchanged for the existing test corpus.
3. Add `page` to points where a single page is unambiguous (§15.4). Additive.

Everything else in §16.2–§16.3 is inherited as-is.

### 16.4 Candidate shape

Matches `CA/src/types.ts` `AnalysisChart` exactly (audit §5.3), which is already committed and tested on the frontend:

```
AnalysisChart {
  id, basis, type, title, description,
  metric, unit, unitKind, currency, scale,
  points: [ {label, value, sourceIds, page} ],
  series: [ {name, data: [AnalysisChartPoint]} ],
  sourceIds: [string],
  score: float            # the candidate's materiality score (§9)
}
```

Rejected groups are counted, never silently dropped: `visualAnalysis.rejected` is `{<reason code>: count}` using the five existing codes in §16.2, and `visualAnalysis.omitted` counts candidates above the display cap. Both fields already exist on both sides.

### 16.5 Caps — as implemented

| Cap | Value | Owner |
|---|---|---|
| Candidates served | **12** | `DocumentAnalysisComposer::MAX_CHARTS` |
| Categories per candidate | **12** | `ChartCandidateBuilder::MAX_CATEGORIES` |
| Series per candidate | **5** | `ChartCandidateBuilder::MAX_SERIES` |
| Minimum points per series | **2** | `ChartCandidateBuilder::timeSeries()` |

The first draft gave 6 / 24 / 3 / 3. Those numbers were invented and are **withdrawn**. V2 may move these into config at their current values (§16.3a) but may not change them without a change request.

---

## 17. API additions

All additions are new keys on existing responses, or new read-only endpoints. **No existing key changes name, type, nullability or meaning.**

### 17.1 `GET /documents/{document}/intelligence`

> **Revised (clarification pass).** `analysis` is **not** a V2 addition. `DocumentIntelligenceResource` already emits it on the visualization branch, composed by `DocumentAnalysisComposer`. V2 **extends its contents**; the key itself already exists.

Existing behaviour V2 inherits and must preserve:

- `analysis` is composed only when `status ∈ {Ready, Needs Review}`, else `null` — deriving it during processing would be work nobody reads.
- `DocumentAnalysis` is **exactly** the interface at `CA/src/types.ts:267` — `overview {takeaways, summaryNotes}`, `visualAnalysis {charts, omitted, rejected}`, `analysisGroups`, `importantFindings`, `stats`. Both sides already agree on it, with 38 backend tests and two frontend test files.
- `DocumentAnalysisComposer::referencedSourceIds()` drives which evidence rows the response carries (see §18.2 on the narrowing this introduced).

Stage A currently adds `analysis.tier1` (source ID, kind, forced status/rule, machine-readable tier reasons and per-item attention) and `analysis.attention` (document attention state, counts, next due date, nested coverage and forced overflow). It also adds `analysis.stats.briefAiBlocksRejected` for V2 prose-screen diagnostics. These are the implemented keys. Top-level `coverageState` and `attentionSummary` remain deferred to Stage C and are not emitted by Stage A.

Mapping from V2 concepts onto that interface — **the left column already exists and is already populated as described; V2 only changes how the values are computed**:

| Frontend field | V2 source |
|---|---|
| `overview.takeaways[].origin` | `synthesis` for `docintel_ai` assessment/tension blocks; `metric`, `trend`, `risk`, `obligation` for deterministic blocks by record kind |
| `overview.takeaways[].basis` | §3.2 mapping from `origin` + `assertion` |
| `overview.takeaways[].sourceIds` | `BriefBlock.cites` — non-empty, per B1 |
| `overview.takeaways[].chartId` | `BriefBlock.chart_id` |
| `overview.summaryNotes[]` | ungrounded AI prose, `supported: false` |
| `visualAnalysis` | §16 |
| `analysisGroups` | Tier 2–3 records grouped by kind, with `total` for pagination |
| `importantFindings` | Legacy-surface top-ranked `AnalysisFinding` records selected independently of Tier 1 under T9a; `citedBySynthesis` set from the summary's `source_ids` |
| `stats` | the nine declared counters |

Stage C proposes two further top-level keys:

```
coverageState: CoverageState | null        # §10.2 — the structured form of existing data
attentionSummary: AttentionSummary | null  # §11.2
```

`processingDetails.coverage` and `processingDetails.synthesisCoverageWarning` remain exactly as they are; when Stage C is implemented, `coverageState` is a structured sibling, not a replacement.

### 17.2 New endpoints

| Method | Path | Returns |
|---|---|---|
| GET | `/documents/{document}/brief` | `{data: {blocks: BriefBlock[], coverage: CoverageState, attention: AttentionSummary, briefVersion, materialityVersion, templateVersion} \| null}` |
| GET | `/documents/{document}/records` | paginated `IntelligenceRecord[]`, filterable by `tier`, `kind`, `attention`, `date_role`, `origin` |
| GET | `/documents/{document}/records/{record}/evidence` | `{data: EvidenceRef[]}` |

Rules:

- Every endpoint calls `authorize('view', $document)` or goes through `IntelligenceAccess`, exactly as `DocumentIntelligenceController` does today.
- No AI call and no job dispatch, exactly as `DocumentIntelligenceController` does today.
- `200` with `data: null` when the artefact has not been produced — never `404`, matching the existing `summary` endpoint's documented convention.
- Pagination defaults mirror the existing controller: 20 default, 100 max.
- Routes are registered with the same care as `/documents/search` and `/documents/query`: no new literal segment may be shadowed by `/documents/{document}`.

### 17.3 Field naming

camelCase in resources, snake_case in storage and config, matching the existing convention. Enum values are the snake_case strings this contract names, unchanged between storage and API.

---

## 18. Backward compatibility

### 18.1 Power BI — out of scope

> **Revised (clarification pass).** Power BI was never implemented in production (audit §6.0): no HTTP surface, no credential, `powerbi_reader` is `NOLOGIN` with `PASSWORD NULL`, `powerbi_credentials` is empty, and `workspace_settings.powerbi_enabled` is read by nothing. There is no consumer, so there is no compatibility obligation for V2 to discharge. The first draft's seven P-rules and its candidate column list asserted an obligation to a consumer that does not exist; both are **withdrawn**.

The contract reduces to one rule:

- **P1** **V2 does not touch Power BI.** It creates no view, alters no view, adds no column to `power_bi_kpis` or `power_bi_chart_points`, and changes nothing about `powerbi_credentials`, the `powerbi_reader` role, the RLS policies or the grants. Prompt 2 must not modify or test against the feed.

Consequences:

- The two views keep working exactly as they do now — dormant, and fed by the same `document_kpis` / `document_chart_points` rows as before. V2 writes neither table, so the views cannot change behaviour.
- `document_kpis.trend` / `trend_value` stay null for incremental documents. V2 does **not** populate them. (The first draft proposed this; it is now deferred to whoever activates the feed.)
- `PowerBiIsolationTest` and `PowerBiRlsTest` must keep passing untouched. Nothing in V2 should require editing them; if V2 ever does, that is a signal V2 has strayed into P1.
- If Power BI is activated later, audit §6.2 holds the future guidance (append-only via `CREATE OR REPLACE VIEW`, the four coupled RLS requirements, the `Restricted` exclusion, the unverified live grants). Surfacing V2 data to BI is a **separate, later project** with its own change request.

### 18.2 Existing API

**Must not change:** every key of `DocumentIntelligenceResource` (`document`, `documentType`, `summary`, `entities`, `risks`, `deadlines`, `intelligenceSummary`, `evidence`, `processingDetails`, `processing`, `sourcePages`) and every key of the five item resources, including:

- `DocumentDeadlineResource.dueDate`, `.dateType`, `.relativeText` — same names, same values, per §4.5;
- `DocumentIntelligenceSummaryResource` — all twelve keys, including the five legacy string arrays and the five V1.5 structured fields;
- `evidence` as an **object keyed by `source_id`** with `{quote, sources[]}`;
- `sourcePages` as an **object keyed by `source_id`**;
- `processing` as the five-stage status map;
- `processingDetails` including `coverage` and `synthesisCoverageWarning`.

**Rules:**

- **A1** New keys only. No renames, no retypes, no nullability changes, no enum-value removals.
- **A2** `summary` endpoint keeps returning `200` with `data: null`.
- **A3** `loadIntelligence()`'s staleness behaviour — nulling `intelligenceSummary` when `ai_pipeline.summary_stale` — applies to every V2 artefact too: a Brief, tier set or chart candidate computed against a superseded pipeline is not served (audit R13).
- **A4** The three paginated endpoints keep `orderByDesc('confidence')`. Tier is additive; it does not reorder an existing endpoint.
- **A5** The coverage `warning` keeps being appended to `executive_summary`.
- **A6** Behaviour with the V2 flag off is byte-identical to the merged pre-V2 baseline (§20) — i.e. to `main` **with the visualization work merged**, which is the tree V2 branches from (§0a.1).
- **A7** The `evidence` map's narrowing to referenced `source_id`s was introduced by the visualization branch, not by V2 (audit §5.3.2). V2 **inherits** it and must not narrow it further: any `source_id` that V2 newly references — from a Brief block, a tier assignment or an attention state — must be added to `DocumentAnalysisComposer::referencedSourceIds()`, or its evidence will be missing from the response while the UI tries to open it.
- **A8** Retaining grounded period-only obligations is a deliberate correction to legacy behaviour. It applies regardless of the V2 flag, only to newly extracted incremental-route documents. Flag-off snapshots must come from post-fix code or omit period-only records. The normal route still does not retain period-only obligations. This exception is not an authorization to change any other extraction behaviour.

### 18.3 Database

- **D1** No column is dropped, renamed or retyped.
- **D2** No CHECK constraint is widened or narrowed: `document_deadlines.date_type`, `document_risks.severity` / `status`, `document_entities.entity_type`, every `confidence >= 0 AND <= 1`, `documents` classification and status.
- **D3** New columns are nullable with no default that changes existing-row semantics.
- **D4** New keys inside `document_evidence.data` do not affect `EvidenceMerger::identity()`, which reads a fixed field list.
- **D5** Every new table carries `workspace_id` with a cascade FK and is filtered by it in every query.

---

## 19. Provider-call budget

### 19.1 The rule

**V2 adds zero provider calls.** Every V2 artefact is derived from records that already exist, by deterministic code.

### 19.2 Call inventory, unchanged

| Stage | Calls today | Calls under V2 |
|---|---|---|
| Token count (large documents) | 1 (`countTokens`) | 1 — unchanged |
| Extraction | 1 per chunk, + splits, + ≤ 2 continuations per chunk | unchanged |
| Merge | **0** | **0** |
| Synthesis | 1, + ≤ 1 bounded repair | 1, + ≤ 1 bounded repair — unchanged |
| Visual plan / vision | existing, capped by `visual_cap` | unchanged |
| Embeddings | existing | unchanged |
| **Tiering, typed values, date roles, Brief assembly, verification, chart candidates, attention, coverage** | — | **0** |

AI Brief blocks (`assessment`, `tension`, `question`, some `finding`) are requested **inside the existing synthesis call** by extending `SynthesisSchema` and the `document_summary` prompt. No second call. The existing repair call keeps its existing purpose (`executive_summary` / `key_findings`) and is not extended to Brief blocks (§14.5).

### 19.3 Anything that needs a call is a change request

The following are **explicitly out of scope** for V2 and each requires a separate, approved change request stating the call count, the per-document cost, the effect on `budget_max_usd`, and the interaction with `canReserve()` and `synthesisReservation()`:

- a model-proposed attribution (§2.3);
- a repair call for a failed Brief block (§14.5);
- a second synthesis pass for Brief blocks;
- re-OCR for bounding boxes (§15.2);
- any chart generation by a model;
- any re-extraction caused by bumping `pipeline_version`, `prompt_version` or `span_segmenter_version`.

### 19.4 Budget interaction

- The extended synthesis schema and prompt **increase request size**. The existing `synthesis_token_budget` (16 000 bytes of evidence) and `sourceReserveBytes()` reservation are unchanged and still bound the request. A scorer that promotes more items to Tier 1 **must not** enlarge the evidence payload: Tier 1 changes the *order* the budget loop consumes, never the budget (audit R11).
- `synthesisReservation()` must be recomputed to include the extended schema's bytes, so the existing cost-reservation ladder (current level, next level, one repair) stays correct. This is a reservation arithmetic change, **not** a new call.
- Output pressure: `synthesis_max_tokens` is 8192. Brief blocks compete for it with the legacy fields. The legacy fields keep priority: if the response is truncated, the legacy fields are what the repair call restores, and Brief blocks fall back to deterministic templates (§14.4). **V2 must never make a legacy field less likely to be produced.** §22's fixture asserts this.

---

## 20. Feature-flag behavior

### 20.1 Flags

```
config/intelligence_v2.php
  'enabled'        => (bool) env('DOCINTEL_INTELLIGENCE_V2', false),   # default OFF
  'workspaces'     => [],    # optional allow-list of workspace ids; empty = honour 'enabled'
  'brief'          => ['enabled' => (bool) env('DOCINTEL_V2_BRIEF', true),
                       'template_version' => '1', 'verifier_version' => '1',
                       'max_quote_chars' => 160,
                       'key_figures' => ['max' => 6,
                         'total_label_patterns' => ['total','overall','aggregate','net','gross']]],
  'charts'         => (bool) env('DOCINTEL_V2_CHARTS', true),          # within V2
```

> The first draft also declared `powerbi_columns`. **Removed** — V2 touches no Power BI view (§18.1 P1), so there is nothing for it to gate.

Mirrors the established pattern of `document_intelligence.evidence_spans`.

### 20.2 Off (the default)

- `analysis` remains present as in the pre-V2 visualization baseline. V2-only `coverageState` and `attentionSummary` are **absent** from the intelligence response — not `null`, absent — so a client cannot distinguish the response from today's byte-for-byte.
- `/brief` and `/records` return `404` (route not registered) when `enabled` is false. A registered route returning `200 {data: null}` would itself be a shape change.
- No tier, Brief, chart-candidate or verification row is written.
- Nothing in the existing pipeline behaves differently. The synthesis schema and prompt are the **V1 versions**.

### 20.3 On

- Stage A emits its additive `analysis.tier1` and `analysis.attention` keys. The later Stage B/C keys and endpoints appear only when those stages are implemented.
- The synthesis schema and prompt gain the Brief block fields.
- Tiers, typed values, date roles and chart candidates are computed.
- Legacy fields and legacy behaviour are unchanged (§18.2).

### 20.4 Flipping

- **F1** Flipping the flag **must not** change `pipeline_key` and **must not** cause re-extraction. Unlike `evidence_spans` — which *is* part of the pipeline key because it changes the record shape the provider returns — V2 derives from records that already exist. (`evidence_spans` keeps its existing behaviour, unchanged.)
- **F2** Flipping on is immediately effective on read: tiers, briefs and chart candidates are derived, cached by `(pipeline_key, materiality_version, parser_version, template_version)`, and recomputed for free when any of those changes.
- **F3** Flipping off hides V2 output and leaves it in place. Flipping back on serves it again without recomputation unless a version changed.
- **F4** Changing the synthesis schema/prompt under the flag means a document synthesised with the flag off has no AI Brief blocks. Its Brief is then **fully deterministic** — valid, smaller, and marked `brief.ai_blocks_available: false`. No re-synthesis is triggered to fill it.
- **F5** No flag gates a Power BI change, because V2 makes none (§18.1 P1).

---

## 21. Legacy-document behavior

Three populations exist. V2 must be correct for all three, and must not re-process any of them.

### 21.1 `incremental`-route documents with `document_evidence` rows

Full V2. Records, typed values, date roles, tiers, Brief, chart candidates, offset-based highlighting (span-grounded records additionally carry `span_id`).

### 21.2 `normal`-route documents (no `document_evidence` at all)

Only derived legacy rows exist: `document_entities`, `document_risks`, `document_deadlines`, `document_kpis`, `document_charts`, `document_intelligence_summaries`. No `pipeline_key`, no offsets, no spans.

V2 builds records by **adapter**, reading only what exists:

```
IntelligenceRecord (adapted) {
  source_id          = "entity:<id>" | "risk:<id>" | "deadline:<id>" | "kpi:<id>"
  kind               = entity | risk | deadline | metric
  quote              = context (entities) | evidence (risks, deadlines) | null (kpis)
  provenance.origin  = "document" only when §2.4 directness is established; else "unknown"
  provenance.assertion = "stated" only for established document origin;
                         otherwise "unspecified" (or "inferred" for inferred legacy rows)
  provenance.attribution = {speaker: null, role: "unattributed", reported: false}
  typed              = ValueParser over value/unit/period, where parseable
  dates              = per §4.5 from date_type + due_date + relative_text
  sources            = []                # no offsets exist
}
```

Rules:

- **L1** Tiering runs. Signals that need span data the adapter cannot supply (`structural_prominence`, `comparability` when offsets are needed) contribute **0** and are recorded as `skipped` in `reasons`. A normal-route record has no span ordinal, so the entire structural signal is skipped; it does not acquire a guessed heading or table-header type. `boilerplate_penalty` is skipped with `span_type_unavailable` on every route in materiality v1. The tier is lower-fidelity, not absent.
- **L2** Highlighting is `mode: page_only` when `EvidencePageLocator` yields a page, else `mode: none`. **Never** `text_match` fabricated from a quote whose offsets are unknown — a search with no anchor can match the wrong occurrence silently.
- **L3** A Brief is deterministic-only. No synthesis re-run, no provider call. `brief.ai_blocks_available: false`.
- **L4** `coverageState.state` is `bounded` (adapted, chunk counters unavailable) with `reasons: ["legacy_route"]`. It is **never `complete`**, so §12 forbids every absence claim on a legacy document.
- **L5** Chart candidates require typed numeric values; `document_kpis.value_numeric` supplies them where non-null. E6 (every point cites a span) cannot be satisfied without offsets, so chart candidates are **suppressed** for legacy documents. Existing `document_charts` are served exactly as today.
- **L6** `documents.insights` (normal route only) is left untouched and is not promoted into any V2 structure.

### 21.3 Documents from a superseded `pipeline_key`

Evidence rows from an older key exist but `ai_pipeline.key` points elsewhere. V2 reads **only** the current `pipeline_key`, exactly as `EvidenceBudget::forDocument()` and `DocumentIntelligenceResource` already do. Superseded rows are invisible, never merged, never cited.

### 21.4 No backfill

V2 performs **no migration of legacy content into new shapes**. Everything is derived on read and cached. There is no backfill job, no re-extraction, and no provider call for any legacy document.

---

## 22. Contract-conformance fixture shape

### 22.1 Purpose

A fixture suite that fails when an implementation drifts from this contract. It runs with no network and no provider access — the repository already enforces this (`tests/Unit/StrayHttpRequestGuardTest.php`).

### 22.1a Build on the existing harness

`tests/Concerns/BuildsIntelligenceFixtures.php` already exists on the visualization branch, with `tests/Feature/DocumentIntelligenceAnalysisTest.php` (38 tests) and `tests/Unit/IntelligenceNormalizationTest.php`. **V2's fixtures extend that harness rather than starting a parallel one**, and the existing 38 tests become the regression baseline T9 refers to.

Two fixture cases from the first draft are **withdrawn**, because Power BI is out of scope (§18.1): any case asserting a Power BI column, view or RLS behaviour. `PowerBiIsolationTest` and `PowerBiRlsTest` keep covering that ground, unmodified.

### 22.2 Layout

```
tests/Fixtures/IntelligenceV2/
  cases/
    <case-id>/
      case.json         # metadata: what this case pins, which contract sections
      input/
        document.json         # documents row fields, incl. ai_pipeline and extracted_text
        evidence.json         # document_evidence rows (data + sources), or []
        legacy.json           # derived legacy rows, for §21.2 cases
        spans.json            # document_source_spans rows, or []
        config.json           # the exact intelligence_v2 config under test
        now.json              # frozen clock, for §7.3 rules 20 and 30
      expected/
        records.json          # typed values, date roles, provenance
        tiers.json            # TierAssignment per record, incl. reasons
        tier1.json            # ordered Tier 1, forced flags, rule ids
        brief.json            # ordered BriefBlocks, incl. origin/assertion/cites/verification
        coverage.json         # CoverageState
        attention.json        # AttentionSummary + per-item states
        charts.json           # chart candidates + rejected reason counts
        api/
          intelligence.json   # full GET response, flag ON
          intelligence-off.json  # full GET response, flag OFF
          brief.json
  schemas/
    *.schema.json       # JSON Schema per shape in this document
```

### 22.3 Required cases

| Case | Pins |
|---|---|
| `01-thin-document` | Tier 1 below `min`, not padded (T6); `coverage.state = bounded`; no absence claim |
| `02-rich-document` | Tier 1 at `max` normal items; `band_qualified` overflow recorded |
| `03-forced-overflow` | more forced items than `forced_max`; priority ordering; `forced_overflow` disclosed (T2, §11.4) |
| `04-hard-cap` | `max` normal + `forced_max` forced = `hard_cap`; T3 asserted |
| `05-period-only-obligation` | §4.3: V2 record `resolution: period`; legacy row `date_type = 'relative'`, `due_date` NULL |
| `06-ambiguous-numeric-date` | `D/M/Y` with day ≤ 12 → no calendar date; V1 recogniser preserved (§1.2 V3) |
| `07-overdue-and-imminent` | frozen clock; rules 20 and 30; recompute-after-hours staleness |
| `08-partial-coverage` | failed + saturated chunks → `partial`; every absence claim suppressed (§12) |
| `09-negative-claim-attempt` | an AI block asserting absence under `bounded` coverage → rejected, template fallback, counted |
| `10-number-mismatch` | AI prose states 14% citing an 11% record → `numbers_grounded` fails, fallback (R5) |
| `11-entity-mismatch` | AI prose names an entity not in any cited record → `entities_grounded` fails |
| `12-comparison-invalid` | "rose" over two non-comparable records → `comparison_valid` fails |
| `13-reported-attribution` | `reported: true` block must name the speaker (B5) |
| `14-chart-eligible` | time series, 4 periods, one subject, one unit → candidate with `basis: time_series` |
| `15-chart-rejected` | mixed subjects, mixed currency, duplicate categories, 2 points → one `rejected` count per E-code |
| `16-legacy-normal-route` | §21.2: adapter, skipped signals, `page_only`/`none` highlighting, charts suppressed, `bounded` coverage, deterministic-only Brief |
| `17-superseded-pipeline-key` | old evidence rows invisible (§21.3) |
| `18-flag-off` | `intelligence-off.json` is byte-identical to the merged pre-V2 baseline; new keys **absent**, not null; `analysis` still present, since it predates V2 (§20.2, A6) |
| `25-preserves-existing-ranking` | T9a: existing-corpus `importantFindings` selected set and order stay strict outside exact-score selection-cap boundaries; at such a boundary select the same number from the tied group under §9.5, never allowing a lower score to displace a higher one (CR-009). T9b: Tier 1 includes all V1 `critical_risk`, `high_risk` and `upcoming_obligation` records. Chart candidate order stays strict. Takeaway selection is V1 logic on V2-formatted text minus synthesis-derived candidates rejected by BriefVerifier, captured in `tests/Fixtures/intelligence-v2/expected/25-takeaways-v2.json` (CR-010, CR-014). Metrics, facts, definitions and entities need not enter Tier 1 (CR-008). |
| `26-forced-item-beats-per-kind-cap` | four critical risks: all four forced into Tier 1, `per_kind = 3` not applied to forced items (T8) — the behaviour today's `MAX_PER_KIND` does not provide |
| `19-no-bounding-boxes` | every highlight is `offset`, `text_match`, `page_only` or `none`; no case produces a box (§15.2) |
| `20-text-match-fallback` | offsets exist but the consumer is not `extracted_text` → `text_match` with `needle`, `occurrence`, `occurrences` |
| `21-provider-call-budget` | a recorded call count per stage, asserted against §19.2; **any increase fails** |
| `22-legacy-field-priority` | truncated synthesis output: legacy fields produced, Brief blocks fall back (§19.4) |
| `23-scorer-determinism` | the same input twice → byte-identical tiers; `sum(contribution) == score`; no two records tie |
| `24-version-bump` | bumping `materiality.version` recomputes tiers; `pipeline_key` unchanged; zero provider calls (§9.4, F1) |

### 22.4 Assertions every case makes

1. Output validates against `schemas/*.schema.json`.
2. Output equals `expected/` exactly — key order normalised, values not.
3. Invariants V1–V7 (§1.2), T1–T9 (§7.4), B1–B6 (§13.4), C1–C6 (§10.3), P1 (§18.1), A1–A8 (§18.2), D1–D5 (§18.3), L1–L6 (§21.2), F1–F5 (§20.4) hold.
4. Recorded provider calls equal the §19.2 inventory for that case — **zero V2-attributable calls** in every case.
5. Every `origin: docintel_ai` block has `ai_generated: true` and a non-null `verification` (§13.6).
6. No `assertion: absent` block exists unless `coverage.state = complete` and `absence_check` is present (§12).
6a. An `origin: unknown` record has `assertion: unspecified`, unattributed attribution, and never backs `stated` or `absent` output.
7. Every config value the case depends on comes from `config.json`; no literal threshold appears in the implementation (§9.1).
8. The `intelligence-off.json` snapshot matches a snapshot taken from the V1 code path.

### 22.5 Golden-file discipline

Updating an `expected/` file is a contract change and requires a change request (§22.6) naming the section changed and why. A silent golden-file update is the one failure mode a conformance suite cannot catch by itself, so it is caught in review instead.

CR-010 approves the V2 takeaway formatter: Employees is selected and Portfolio exposure is not. CR-014 updates the V2 takeaway golden `tests/Fixtures/intelligence-v2/expected/25-takeaways-v2.json` by omitting the synthesis finding “Financing growth is concentrated in infrastructure.” (`origin_assertion_consistent`) and the trend “Approvals have risen in each of the last three reporting years.” (`comparison_valid`, `origin_assertion_consistent`). Both cite one unknown-origin `kpi` record in fixture 25; the V1 golden and flag-off formatter remain unchanged.

### 22.6 Relationship to the change-request process

Any deviation from this document — including adding a provider call, changing a Power BI column, renaming an API key, loosening a date rule, or updating a golden file — goes through [`contract-change-request-template.md`](contract-change-request-template.md).

---

## Open questions

### Answered from repository evidence (clarification pass)

**Q2 — Power BI additive columns. RESOLVED: add none.**
There is no consumer (audit §6.0): no HTTP surface, `powerbi_reader` is `NOLOGIN` with `PASSWORD NULL`, `powerbi_credentials` is empty, and `powerbi_enabled` is read by nothing — only written `false` by `WorkspaceObserver` and `BackfillWorkspaceSettings`. A column added for a consumer that cannot connect is cost with no benefit. §18.1 P1 now forbids touching the views, and the `powerbi_columns` flag is **removed** from §20.1 rather than shipped off.

**Q3 — `document_kpis.trend` population. RESOLVED: no, not in V2.**
Follows from Q2. The only reason to populate these columns is the Power BI feed, which is dormant; `analysis.visualAnalysis` already gives the UI its trend information deterministically via `ChartCandidateBuilder`, without writing to `document_kpis`. Deferred to whoever activates the feed. Withdrawn from §18.1.

**Q6 — Where V2 code lives. RESOLVED: `app/Services/Intelligence/`, which already exists.**
The visualization branch created exactly that namespace with 11 classes, all provider-free (audit §5.3.1). V2's `Materiality/`, `Values/` and `Brief/` sub-namespaces go inside it. No new top-level namespace, and the separation from `app/Services/AI/Incremental/` is already established by precedent.

**Q7 — Docs location. RESOLVED: keep them here.**
`CA_BACKEND/docs/` is the established home for this architecture — `docintel-date-contract.md`, `architecture/evidence-span-grounding.md`, `architecture/large-document-processing.md`, `tasks/docintel-*`. `/home/collins/boys/docs/` is empty, is not inside either git repository, and would therefore not be version-controlled at all. Keeping these files in `CA_BACKEND/docs/intelligence-v2/` is the only option that versions them.

**Q8 (new) — Does V2 need a new scorer at all, given three already exist? RESOLVED: yes, but as unification.**
`ImportantFindingsBuilder`, `TakeawayBuilder` and `ChartCandidateBuilder` each rank and cap independently via `private const`s (audit R16). None is versioned, configurable or testable as a unit, and none provides a forced-item guarantee — a fourth critical risk is excluded today by `MAX_PER_KIND = 3`. V2 unifies them under T9's calibrated membership and selection rules, with the CR-006 exact-score tie exception, and adds the guarantee (§7.3, fixture `26`).

### Still open — product decisions

**Q1 — Tier 1 `imminent_days`. RESOLVED:** The Stage A approver fixed it at 90 days in §7.1 and §7.3.

**Q4 — Synthesis output pressure** (§19.4, audit §12.4). `synthesis_max_tokens` is 8192 for one call plus one bounded repair. Brief blocks would compete with `executive_summary` and `key_findings`, and the visualization branch has made `material_findings` and `trends` load-bearing for the takeaway list while they remain the first fields dropped under pressure. The options — a smaller Brief cap, a raised `synthesis_max_tokens` (cost, no new call), or splitting Brief generation out — trade cost against completeness. **Needs measurement first, then a product decision.** This is the one place V2 could degrade existing behaviour, so it should be settled before the prompt changes.

**Deferred — presentation-independent takeaway deduplication (CR-011).** V2 currently deduplicates rendered text, so future formatter changes may affect selection again. A later design should evaluate a canonical selection key; it is outside CR-010 and Stage A Part 2.

**Resolved — negative-claim prose pattern set (CR-012).** The version-1 English patterns and match policy are in §12.3; the Stage A Part 2 screen uses them without changing legacy summary arrays.

**Approved — headline measure eligibility (CR-013).** Rule 70 uses a document-level currency group with at least two valid canonical metrics and pre-forcing Tier ≤ 2; chart-group membership is irrelevant to the forced predicate. The chart-derived `comparability` score signal remains unchanged.

**Q5 — `attribution` pattern set. RESOLVED by CR-007:** English-only, versioned, config-driven patterns and nearest-match rules are in §2.3 and CR-007. A model-proposed attribution remains out of scope under §19.3.

**Q9 — Scope of Stage A Part 2. RESOLVED:** The Stage A Part 2 instruction authorizes typed values, provenance, materiality, attention, coverage, negative-claim guard integration and explainability. Stage B is not authorized by this instruction.

---

## Change log

### 2026-10-08 — Stage B1 approval (CR-014)

The Brief is deterministic-only in B1; headline metadata may have empty cites, quote limit is 160 characters, and key figures use the approved currency selector. The existing synthesis/takeaway surface is verified separately. Fixture 25's two unsupported synthesis candidates are omitted and counted, with its V2 takeaway golden updated under §22.5. The Brief flag is an array with `enabled`, retaining the same environment variable and default. All eleven §14.2 checks remain required.

### 2026-10-07 — Stage A Part 2 approvals

CR-001 through CR-004 are approved as amended above: unknown/unspecified provenance, conservative historical-risk attention, exact scorer and role-pattern parameters, and typed-value/coverage shapes. A8 records the already-committed period-only validator exception. The Stage A Part 2 instruction is authoritative where earlier contract text differs.

CR-006 approves §9.5 tie ordering over V1 confidence/reference order for exact-score ties. CR-007 approves the exact attribution pattern map and tie/default/reported rules. CR-008 clarifies that `importantFindings` is independent of Tier 1 and replaces the inconsistent old T9 membership requirement with T9a/T9b. None changes scorer weights, bands, class bases, signals, promotion, patterns, or forced rules.

CR-009 extends §9.5 with page, end offset and normalized label before identity, and permits a different V1 selected identity only when an exact-score tie crosses an `importantFindings` selection cap while preserving the number selected from the tied group. The existing fixture uses placeholder positions for every evidence row and offers no independent source positions for a correction. See CR-009 for the read-only diagnostic. No scorer parameter changed.

CR-010 approves V1 takeaway selection logic applied to V2-formatted text, the corresponding §22.5 golden, and leaving the flag-off formatting defect unchanged. CR-011 separately tracks a future presentation-independent deduplication design; no scorer or selection constant changes in Stage A Part 2.

### 2026-10-07 — clarification pass

Prompted by a review of the audit's branch provenance. The corrections below all follow from one root cause: the first draft was written against a backend tree that did not contain the intelligence visualization work, while the frontend tree it was compared against did (audit §0.2, §5.3).

| # | Section | Change |
|---|---|---|
| 1 | **New §0a** | V2 is an extension of `App\Services\Intelligence\*`, not greenfield. Prerequisite merge, inventory of what exists, and the seven things V2 genuinely adds. |
| 2 | §1 | `TypedValue` wraps the existing `MeasurementParser` / `Measurement` / `PeriodParser` / `ReportingPeriod` and delegates to them; existing ambiguity rejections are authoritative. |
| 3 | **New §5.0** | V2's four tiers mapped onto the existing seven `ImportantFindingsBuilder::TIERS`. One-tier citation promotion retained; CR-006 later replaced confidence-based exact-score ties with §9.5. |
| 4 | §7.1 | Budget reconciled with the two existing caps of 8. Added `per_kind`, `per_stem`, `origin_quotas` at current values. Added **T8** (forced items escape all caps) and **T9** (strict calibration with the later CR-006 tie exception). |
| 5 | §9.1 | Scorer sits inside the existing namespace; the three existing mechanisms become its callers at their current values. |
| 6 | §16 | **Rewritten.** Replaced invented eligibility rules, thresholds and reason codes with `ChartCandidateBuilder`'s actual ones (2 min points not 3; 12/5 caps not 24/3; the five real rejection codes; the real `score()`). Added §16.3a for the little V2 may change. |
| 7 | §16.5 | Caps corrected to 12 / 12 / 5 / 2. The first draft's 6 / 24 / 3 / 3 were invented and are withdrawn. |
| 8 | §17.1 | `analysis` already exists and is already emitted; V2 extends its contents. Documented the `status ∈ {Ready, Needs Review}` gate. |
| 9 | §18.1 | **Power BI reduced to a single non-modification rule (P1).** Withdrew P2–P7 and the candidate column list: there is no consumer. Prompt 2 must not modify or test against the feed. |
| 10 | §18.2 | Added **A7** — the `evidence` narrowing came from the visualization branch; V2 inherits it and must register newly referenced `source_id`s. Clarified A6's baseline. |
| 11 | §22 | **New §22.1a** — build on the existing `BuildsIntelligenceFixtures` harness; the 38 existing tests are the T9 baseline. Withdrew Power BI cases; added cases `25` and `26`. |
| 12 | Open questions | **Answered Q2, Q3, Q6, Q7 from repository evidence; added Q8 (answered).** Left Q1, Q4, Q5 open as product decisions; added Q9 on phasing. |

**Unchanged:** §0 governing principles, §2 origin/assertion/attribution, §3 legacy mapping, §4 date roles, §10 coverage states, §11 attention states, §12 negative-claim guard, §13 Brief blocks, §14 verification, §15 evidence references, §19 provider-call budget, §21 legacy-document behaviour.

**Net effect on scope:** smaller. Power BI work removed entirely; chart eligibility, typed-value parsing and the tier model become *refinements of working code* rather than new subsystems. The five findings in audit §12 remain the substance.
