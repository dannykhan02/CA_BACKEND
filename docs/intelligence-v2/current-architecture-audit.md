# DocIntel Intelligence — current architecture audit

**Date:** 2026-10-07
**Revised:** 2026-10-07 (clarification pass — see §0 and the change log at the end)
**Scope:** audit only. No application code, migration, test or provider call was touched.
**Method:** static reading of `CA_BACKEND` (pipeline, models, migrations, resources, routes, policies, config, prompts) and `CA` (types, intelligence panel, chart layout, report builder), plus `git` branch-containment checks. Live database state was **not** inspected; schema claims come from migrations.

Companion document: [`intelligence-v2-contract.md`](intelligence-v2-contract.md).

---

## 0. Audit provenance and branch state

`CA` and `CA_BACKEND` are **two separate git repositories**, each with its own `main`. `/home/collins/boys` is not a repository. All refs below are local; `git fetch` was not reachable in this environment, so "origin/…" means the last-fetched local remote-tracking ref.

### 0.1 What was audited

| Repo | Audit branch | HEAD | Contains all of `origin/main`? | Ahead of main |
|---|---|---|---|---|
| `CA_BACKEND` | `feat/intelligence-v2-audit` | `ff37df8` | **yes** | 9 commits |
| `CA` | `feat/intelligence-v2-audit` | `12b6c37` | **yes** | 3 commits |

`origin/main` is `bdb7491` (backend, 2026-10-06) and `81e992e` (frontend). Both audit branches are strict descendants of their `main`, so **yes, the audit is based on current main** — but each is *ahead* of it, and the two are ahead by **different work**. That asymmetry caused one material error in the first pass (§5.3).

### 0.2 The intelligence visualization work is NOT merged into main

This corrects the premise of the clarification request.

```
                                      ┌─ ff37df8  Plan risky DocIntel roots before extraction
                                      │           = CA_BACKEND feat/intelligence-v2-audit (audited)
bdb7491 (origin/main) ─ … 8 commits ─ b537164 ─┤
                                      │
                                      └─ 5621d7d  Derive chart candidates and key takeaways
                                         c1d3230  Ground every takeaway and rank findings
                                                 = origin/feat/docintel-intelligence-visualization
```

Verified by containment check:

| Branch | In `origin/main`? | In the audited backend tree? |
|---|---|---|
| `CA_BACKEND` `feat/docintel-intelligence-visualization` | **no** | **no** |
| `CA` `feat/docintel-intelligence-visualization` | **no** | **yes** — it *is* the audited frontend tree |

So:

- The **frontend** visualization work (3 commits) was in the tree I read. That is why `DocumentAnalysis` and its components were found.
- The **backend** visualization work (2 commits, ~3 100 insertions) was **not** in the tree I read. The backend audit branch forked from the shared base `b537164` and took `ff37df8` (proactive chunk planning) instead.
- **Neither** side is in `main`. The visualization work is complete and committed, but it is unmerged on both repos.

### 0.3 What is in main, and what is only on the audit branch

Much of what §1–§9 below describe is **not in main either** — it arrived in the 9 commits ahead:

| Component | In `origin/main`? |
|---|---|
| `EvidenceBudget`, `EvidenceMerger`, `document_evidence`, `document_chunks` | **yes** |
| `EvidenceGrounding`, `EvidenceSpanSet`, `SourceSpanBuilder`, `document_source_spans` (span grounding) | no — audit branch only |
| `ProactiveChunkRisk` | no — audit branch only |
| `App\Services\Intelligence\*` (the analysis layer) | no — viz branch only |

**Read every section below as describing the audited branch tips, not `main`.** Where a statement is true only of one branch, it now says so.

### 0.4 Consequence for V2

Before Prompt 2 begins, the branch situation must be resolved, because V2's design depends on which code it is extending. Recommended order, as a prerequisite rather than part of V2:

1. Merge `feat/docintel-intelligence-visualization` (both repos) to `main`, or merge it into the V2 working branch.
2. Reconcile it with `ff37df8` (proactive chunk planning) — the two diverge at `b537164` and the viz-branch diff shows it *reverting* `ProactiveChunkRisk.php`, the `2026_10_07_000002_add_docintel_attempt_timing` migration and the associated test and doc, purely because it predates them. A naive merge in the wrong direction silently drops that work.
3. Only then branch V2.

This is a sequencing risk, not a design risk. It is tracked as **R15**.

---

## 1. Current data flow

### 1.1 Common prefix

```
upload → ScanUploadedFileJob → ExtractDocumentTextJob
            (OCR when needed: Tesseract or Claude Vision, per page)
         → GenerateInsightsJob
```

`ExtractDocumentTextJob` writes `documents.extracted_text`. Page boundaries are encoded as `\f` form-feed characters; `documents.pages` holds the page count. Page attribution is only trusted when `substr_count(text, "\f") + 1 === pages` (`EvidenceGrounding::pagesKnown`, `EvidencePageLocator`).

### 1.2 The routing fork

`IncrementalPipeline::route()` (`app/Services/AI/Incremental/IncrementalPipeline.php:34`) decides once per text hash + extraction model:

| Condition | Route |
|---|---|
| `tokens <= document_intelligence.large_tokens` (14 000) **and** `mb_strlen(text) <= document_processing.max_extraction_chars` | `normal` |
| anything else | `incremental` |

Token count is a byte upper bound below `large_tokens`, and an actual `countTokens` provider call (falling back to estimation) above it. The decision, the count and the count method are persisted in `documents.ai_pipeline` (jsonb).

The comment is explicit that `normal` is chosen **only when truncation would be lossless** — it is not a size preference.

### 1.3 Route A — `normal` (legacy four-job path)

```
GenerateInsightsJob  ─ one provider call → documents.insights,
                                           document_kpis (with trend/trend_value),
                                           document_charts + document_chart_points,
                                           documents.has_structured_data
Bus::batch([
  ClassifyDocumentTypeJob,      → document_type_classifications
  ExtractDocumentEntitiesJob,   → document_entities
  DetectDocumentRisksJob,       → document_risks
  DetectDocumentDeadlinesJob,   → document_deadlines
])->finally(GenerateDocumentSummaryJob)
                              → document_intelligence_summaries
```

Five or more provider calls. Each job reads `extracted_text` independently.

### 1.4 Route B — `incremental`

`GenerateInsightsJob` short-circuits immediately (`app/Jobs/GenerateInsightsJob.php:57`) and hands over to `IncrementalPipeline::start()`.

```
IncrementalPipeline::start()
  ├─ EvidenceGrounding::spans()  (span mode only) → document_source_spans
  ├─ ChunkPlanner                                → document_chunks(stage=extraction)
  └─ pipeline_key = sha256(workspace, document, text_hash, pipeline_version,
                           prompt_version, extraction_model, grounding_mode)

pump() → ProcessDocumentChunkJob × N   (one provider call per chunk)
           EvidenceSchema::extraction/instructions/validate
           result checkpointed into document_chunks.result
           capacity failures → split() (depth ≤ 2, ≤ 2 split parents per root)
           truncation       → salvage() + ≤ 2 continuations

all extraction chunks settled
  → MergeDocumentEvidenceJob → EvidenceMerger   ← NO provider call
       document_evidence (canonical, identity-keyed)
       + derived rows: document_entities / document_risks / document_deadlines / document_kpis
       + source_id back-link on each evidence row

  → GenerateDocumentSummaryJob  (one provider call, + ≤ 1 bounded repair)
       EvidenceBudget::forSynthesis() → evidence + source context
       SynthesisSchema + prompt `document_summary` v3
       ResponseValidator::validateSummary()
       → document_intelligence_summaries, document_type_classifications

  → AnalyzeEmbeddedVisualsJob   (vision calls; may create document_charts)
  → GenerateEmbeddingsJob
  → documents.status = Ready, progress = 100
```

### 1.5 Consequences of the fork that matter for V2

These are differences in **output**, not just in mechanism:

1. **`document_charts` are route-dependent.** On `normal`, charts come from `GenerateInsightsJob`'s single call. On `incremental`, `GenerateInsightsJob` never runs its body, so the **only** chart source is `AnalyzeEmbeddedVisualsJob` — charts recovered from *embedded images* by vision. A large text-only report therefore produces **zero** persisted charts, however many KPI observations it has.
   *Partly mitigated on the visualization branch:* `ChartCandidateBuilder` derives chart candidates for the **API/UI** from metric evidence (§9.1), so the page is no longer empty. But it writes nothing, so `document_charts`, `document_chart_points` and the dormant `power_bi_chart_points` view stay empty for incremental documents.
2. **`document_kpis.trend` / `trend_value` are route-dependent.** `EvidenceMerger::plan()` inserts KPI rows with `label`, `kpi_definition_id`, `identity_metadata`, `period`, `value`, `unit`, `value_numeric` — and **no `trend` or `trend_value`**. Both columns are null for every incremental document. `power_bi_kpis` exposes both.
3. **`documents.insights` is route-dependent** (normal only); the synthesis prompt on `normal` consumes `array_slice($document->insights, 0, 5)`.
4. **`documents.has_structured_data`** is set by `GenerateInsightsJob` (normal) or `AnalyzeEmbeddedVisualsJob::mergeCharts()` (incremental, only when charts were found).

Most large, interesting documents take the `incremental` route, so in practice the richest documents are the ones with the least structured presentation.

---

## 2. Current record shapes

### 2.1 `document_evidence` — the canonical incremental record

`2026_10_04_000003_add_incremental_document_processing.php`, model `App\Models\DocumentEvidence` (uuid, `$guarded = []`, casts `data` and `sources` to array).

| Column | Type | Notes |
|---|---|---|
| `id` | uuid | |
| `workspace_id`, `document_id` | uuid FK | cascade delete |
| `pipeline_key` | string(64) | invalidation key; see §1.4 |
| `identity` | string(64) | `EvidenceMerger::identity()` — sha256 of normalized kind-specific fields |
| `kind` | string(24) | `entity`, `metric`, `deadline`, `obligation`, `risk`, `fact`, `definition`, `unresolved` |
| `source_id` | string, nullable | `entity:<id>` / `risk:<id>` / `deadline:<id>` / `kpi:<id>` / `fact:<uuid>` — the citation handle |
| `data` | jsonb | the validated extraction record |
| `sources` | jsonb | list of source links |

Unique on `(document_id, pipeline_key, identity)`; index on `(workspace_id, document_id, pipeline_key)`.

`data` (from `EvidenceSchema::extraction()` + `ground()`):

```
label, value, subject, reference            string (required, may be "")
quote                                       string — in span mode DocIntel writes it from the spans
evidence_ids                                list<string>  (span mode only)
evidence                                    list<{span_id,page,start_offset,end_offset,text,type}> (span mode)
aliases                                     list<string>
kind                                        enum (8 values, above)
confidence                                  number 0..1
entity_type, unit, period, severity,
metric_type, value_basis, aggregation,
quantity_kind                               string|null
date_type                                   "explicit"|"relative"|"inferred"|null
due_date                                    "YYYY-MM-DD"|null
resolved_evidence_id                        set later for resolved `unresolved` records
```

`sources[]`: `{chunk_id, span_id?, start_offset, end_offset, quote, page|null}`. Offsets are into `documents.extracted_text`. `span_id` present only for span-grounded records.

### 2.2 Derived legacy rows

Written by `EvidenceMerger::persistDerived()` on the incremental route and by the four detect/extract jobs on the normal route. **Same tables, same columns, both routes.**

- **`document_entities`** — `bigint id`, `entity_type` (CHECK: organization, person, department, location, regulator, contract, reference, date, other), `value`, `normalized_value`, `confidence` decimal(4,3) CHECK 0..1, `context` (holds the quote), `prompt_version`, `provider`, `model`.
- **`document_risks`** — `risk_type` nullable, `title`, `description`, `severity` CHECK (low, medium, high, critical), `confidence`, `evidence` text (the quote), `status` CHECK (open, mitigated, closed) default open.
- **`document_deadlines`** — `deadline_type` nullable (merger writes the evidence `kind`: `deadline` or `obligation`), `title`, `description`, `due_date` date nullable, **`date_type` NOT NULL** CHECK (explicit, relative, inferred), `relative_text` nullable, `confidence`, `evidence` text, `status` CHECK (open, met, missed).
- **`document_kpis`** — `kpi_definition_id` nullable FK, `identity_metadata` jsonb (`scope`, `metric_type`, `value_basis`, `aggregation`, `quantity_kind`), `period`, `label`, `value` string, `value_numeric` decimal(14,4) nullable, `unit`, `trend`, `trend_value`. A `saving` hook enforces that definition and observation share the document's workspace.
- **`document_charts`** — `type`, `title`, `description`, `data` jsonb; **`document_chart_points`** — `label`, `value` decimal, `sort_order`. `points` exists purely so BI can read rows instead of JSON.
- **`document_intelligence_summaries`** — unique on `document_id`. Legacy columns `executive_summary` text, `key_findings`/`critical_risks`/`upcoming_deadlines`/`important_entities`/`recommended_attention` json (**arrays of plain strings**). V1.5 structured columns `executive_assessment`, `material_findings`, `trends`, `tensions`, `questions` json nullable.

### 2.3 `document_source_spans`

`2026_10_08_000001_create_document_source_spans.php`. Offsets only, never a copy of the text: `(document_id, extraction_version, span_key)` unique, plus `ordinal`, `page`, `start_offset`, `end_offset`, `type`. `extraction_version = substr(sha256(sha256(extracted_text) | span_segmenter_version), 0, 32)` — re-extraction creates a **new** span set rather than silently remapping existing IDs.

### 2.4 `document_chunks`

Unit of work for extraction, synthesis, visual and visual_plan stages. Carries `stage`, `status` (pending, queued, running, completed, failed, uncertain, budget, split, superseded), `attempts`, `failure_class`, `reserved_cost`, `result` jsonb, `input_hash`, offsets, pages, `token_count`, `depth`, `dispatch_token`, `cost_accounting`. The synthesis checkpoint is a `document_chunks` row with `stage = synthesis`.

---

## 3. Current date / origin semantics

### 3.1 Extraction-time date semantics

`EvidenceSchema::validateDate()` (`app/Services/AI/Incremental/EvidenceSchema.php:362`). `$critical = kind ∈ {deadline, obligation}`.

| Model output | deadline / obligation | other kinds |
|---|---|---|
| `date_type` outside {explicit, relative, inferred, null} | reject `invalid_deadline_date_type` | reject `invalid_date_type` |
| `date_type = null` | **reject** `invalid_deadline_date_type` | allowed |
| `date_type = explicit`, `due_date = null` | reject `explicit_date_missing_due_date` | sanitize `date_type → null` |
| `due_date` set, `date_type ≠ explicit` | reject `due_date_present_for_non_explicit_type` | sanitize `due_date → null` |
| `due_date` not a real `Y-m-d` calendar date | reject (`due_date_wrong_format` / `due_date_invalid_calendar_date`) | same |
| `due_date` not supported by a complete date in the grounded quote | reject `explicit_date_not_in_evidence` | sanitize both to null |

`evidenceStatesDate()` accepts `Y-M-D` numeric, `D Month Y`, `Month D, Y`, and `D/M/Y` **only when the day exceeds 12** — an ambiguous numeric date is never enough to assert one normalized date.

The surviving semantics: **a non-null `due_date` always means a complete calendar date that the cited evidence literally states.** That guarantee is worth preserving unchanged.

### 3.2 The gap this creates

A dated obligation whose date is a *period* — "payment is due in Q3 2026", "filing due in FY2025" — has no representable shape. The prompt instructs `date_type = null, due_date = null, period = <text>` for a partial period, but `validateDate()` rejects `date_type = null` for `deadline`/`obligation`. The record is **dropped**, not downgraded. The only survival routes are a reclassification to `fact` (losing deadline semantics) or `date_type = relative` (which is not what the text says).

`document_deadlines.date_type` is `NOT NULL` with a three-value CHECK, which is consistent with that rejection — the schema and the validator agree. But both agree on something lossy.

### 3.3 Downstream date handling

- `document_deadlines.relative_text` is set by the merger to `$r['due_date'] ? null : $r['value']` — i.e. the whole value string becomes the "relative text" whenever no calendar date exists, regardless of whether the value is actually a timing expression.
- `period` lives on `document_kpis.period` and in `document_evidence.data.period`, as free text, with no grain, start or end.
- Deadline reminders and tracking (`TrackedItem`, `SendTrackedDeadlineReminder`, `docs/DEADLINE_ATTENTION.md`) can only act on an explicit `due_date`.

### 3.4 Origin semantics today

There is no origin model. Three partial, unrelated signals exist:

1. **`date_type`** — `explicit | relative | inferred` on deadlines only. Conflates *date resolution* (is there a calendar date?) with *epistemic status* (did the document say this, or did we infer it?).
2. **`basis`** — `explicit | inferred`, on each structured synthesis item (`SynthesisSchema`, validated at `ResponseValidator.php:369`). The prompt defines it as "explicit for a direct statement from a source, inferred for a synthesis or question". Only present on the V1.5 structured fields.
3. **`confidence`** — a model-supplied 0..1 number, persisted on every derived row. The extraction prompt itself says "Confidence is not evidence."

Nothing records **who in the document** asserts a thing. A risk a regulator alleges, a risk management discloses and a risk a third party is quoted as claiming are indistinguishable once extracted. There is no `attribution` concept anywhere in the schema, the prompts or the API.

The five legacy summary arrays (`key_findings`, `critical_risks`, `upcoming_deadlines`, `important_entities`, `recommended_attention`) are **plain strings with no basis, no source_ids and no origin at all** — ungrounded AI prose, indistinguishable in the API from grounded content.

---

## 4. Current synthesis behavior

### 4.1 Input

`EvidenceBudget::forSynthesis()` = `forDocument()` + `sourceContext()`.

`forDocument()`:
- loads every `document_evidence` row for the current `pipeline_key`, ordered by `identity`;
- drops `unresolved` records that were never resolved, counting them;
- sorts by a **hard-coded priority**: `deadline`/`obligation` = 0, `metric` = 1, `risk` = 0 if severity ∈ {high, critical} else 2, `definition`/`entity` = 3, else 4;
- fills a byte budget (`synthesis_token_budget` = 16 000, bytes used as a conservative token bound), skipping whole records that would overflow;
- groups into `entities`, `risks`, `deadlines`, `kpis`, `facts`, each item `{id: source_id, ...data, sources}`;
- emits `coverage` (see §4.4).

`sourceContext()` adds the original text: the whole document when it fits the level's budget, otherwise deterministic windows of `synthesis_excerpt_radius_chars` (600) around the sources of the evidence already selected, merged and sorted, else nothing.

**This is the one place in the system where ordering/importance is decided, and it is a five-arm `match` inside a budget-trimming loop.** It is not a scorer, it is not versioned, it has no tests of its own, and it is not addressable or overridable.

### 4.2 The provider call

One call to `document_summary` (`AnthropicClient::generateDocumentSummary`, line 1063), model `AiModels::forTask('document_summary')`, structured output `SynthesisSchema::schema()`, prompt `document_summary` v3 (`DocumentSummaryPromptSeederV3`), per-level timeout, `max_tokens = synthesis_max_tokens` (8192), `single_response`, `max_attempts = 1`.

Plus **at most one bounded repair call** (`summary_repair` model, 40 s, `repair_max_tokens` = 2048) when `executive_summary` or `key_findings` came back missing or malformed — and only on the incremental route.

So the current synthesis provider budget is **1 call, 2 in the worst case, per synthesis attempt**, and a document may make several attempts as it descends the fallback ladder.

### 4.3 The fallback ladder

`config/document_intelligence.php` → `synthesis_levels`:

| Level | Source context | `source_fraction` | `radius_fraction` | Timeout |
|---|---|---|---|---|
| 0 | full | 1.0 | 1.0 | 110 s |
| 1 | reduced | 0.5 | 1.0 | 90 s |
| 2 | excerpts | 0.125 | 0.5 | 75 s |
| 3 | none | 0.0 | 0.0 | 60 s |

**Evidence is identical at every level; only source context shrinks.** Only `synthesis_degradable_failures` (`timeout`, `max_tokens`, `truncated`, `context_overflow`) descend the ladder. `transient` retries at the same level up to `attempts` (3). Auth, billing, model and schema failures never retry. Reservations for the current level, the next level and one repair are held separately so a timed-out attempt with unknown usage cannot consume every recovery option.

Terminal failure sets `status = Needs Review`, `progress = 100`, `ai_pipeline.synthesis = <failure class>`, `synthesis_failure_reason`, `partial = true`, and a short non-technical `error_message`. Extracted evidence is preserved and still served.

### 4.4 Validation of the response

`ResponseValidator::validateSummary($decoded, $availableSourceIds)`:

- `executive_summary` must be a non-empty string, else throw.
- `key_findings` must be a list, else throw. The other four legacy arrays default to `[]`; non-string members are counted into `_optional_items_dropped` and dropped.
- Each structured item (`executive_assessment`, `material_findings`, `trends`, `tensions`, `questions`) must have **1–4 `source_ids`**, each matching `/\A(entity|risk|deadline|kpi|fact):.+\z/` **and** present in `$availableSourceIds` — which `parseSummaryResponse` builds from the IDs it actually put in the prompt payload. A hallucinated or unavailable ID fails the item.
- Required text fields must be non-empty strings within `SUMMARY_TEXT_LIMIT`; prose fields over the limit are truncated, non-prose fields throw.
- `basis` must be `explicit` or `inferred` when present.
- Per-field caps: 4 material findings, 2 trends, 2 tensions, 3 questions. `executive_assessment` is a single nullable object.
- A failing optional item is dropped and counted; a failing required field throws into the repair/ladder path.

**What is *not* verified:** nothing checks that a number, a date, a monetary amount or a named entity appearing in the model's prose actually occurs in the records it cited. `source_ids` membership is checked; the *content* of the prose against those records is not. There is no numeric or entity cross-check anywhere in the synthesis path.

### 4.5 Coverage disclosure

`forDocument()` emits:

```
evidence_total, evidence_omitted, unresolved_references,
failed_chunks, total_chunks, dropped_records, saturated_chunks,
comprehensive (bool), warning (string|null)
```

`comprehensive` is true only when `evidence_omitted`, `unresolved_references`, `failed_chunks`, `dropped_records` and `saturated_chunks` are all zero. `forSynthesis()` adds `source_text` (`full` | `excerpts` | `omitted`) and `synthesis_level`.

When `warning` is set, `GenerateDocumentSummaryJob` **appends it to `executive_summary`** as a "Coverage note:" paragraph (line ~196) and stores the whole coverage array in `ai_pipeline.coverage` plus `ai_pipeline.synthesis_coverage_warning`. The warning is therefore mixed into prose rather than carried as a structured state.

---

## 5. Current API contract

### 5.1 Routes

All under `auth:sanctum` + verified, in `routes/api.php`:

| Method | Path | Controller |
|---|---|---|
| GET | `/documents/{document}/intelligence` | `DocumentIntelligenceController::show` |
| GET | `/documents/{document}/entities` | `::entities` (paginated, 20 default / 100 max) |
| GET | `/documents/{document}/risks` | `::risks` (paginated) |
| GET | `/documents/{document}/deadlines` | `::deadlines` (paginated) |
| GET | `/documents/{document}/summary` | `::summary` — 200 with `data: null` when absent, never 404 |
| POST | `/documents/{document}/reprocess` | `DocumentReprocessController` (throttle 20/min) |
| GET | `/documents/{document}/context` | `DocumentContextController` |
| PATCH | `/documents/{document}/risks/{risk}` | `DocumentRiskReviewController` |

Every intelligence read calls `$this->authorize('view', $document)` and performs **no AI call and no job dispatch**.

### 5.2 `GET /documents/{id}/intelligence` response

`DocumentIntelligenceResource`, camelCase keys:

```
document: {id, name, errorMessage, type, status, processingFailure}
documentType: DocumentTypeClassificationResource | null
summary: {entities: int, risks: int, deadlines: int}
entities: DocumentEntityResource[]
risks: DocumentRiskResource[]
deadlines: DocumentDeadlineResource[]
intelligenceSummary: DocumentIntelligenceSummaryResource | null
evidence: { "<source_id>": {quote, sources[]} }      // object, keyed by source_id
processingDetails: {route, partial, evidenceTrimmed, stages, coverage, synthesisCoverageWarning}
processing: { "<stage>": "<status>" }                 // five stages
sourcePages: { "<source_id>": <page int> }            // object
```

Item resource field names: entity `{id, entityType, value, normalizedValue, confidence, context, promptVersion}`; risk `{id, riskType, title, description, severity, confidence, evidence, status, promptVersion}`; deadline `{id, deadlineType, title, description, dueDate, dateType, relativeText, confidence, evidence, status, promptVersion}`; summary `{executiveSummary, keyFindings, criticalRisks, upcomingDeadlines, importantEntities, recommendedAttention, executiveAssessment, materialFindings, trends, tensions, questions, promptVersion, generatedAt}`.

`loadIntelligence()` nulls the `intelligenceSummary` relation when `ai_pipeline.summary_stale` is set, so a stale summary is never served.

### 5.3 The `analysis` section — CORRECTED

> **Correction (clarification pass).** The first pass reported this as "a complete frontend contract that no backend code produces", and called it the most important finding. **That was wrong, and it was an artefact of the branch asymmetry in §0.2, not a defect in the product.**
>
> `App\Services\Intelligence\DocumentAnalysisComposer` **does** produce `analysis`, and `DocumentIntelligenceResource` **does** emit it. That code lives on `CA_BACKEND` `feat/docintel-intelligence-visualization` (commits `5621d7d`, `c1d3230`), which was **not** in the backend tree I read, while the matching frontend work **was** in the frontend tree I read. I searched one tree for the counterpart of the other and reported the absence as a gap.
>
> **Why it appeared absent, precisely:** the grep for `analysisGroups`, `importantFindings`, `chartableFindings`, `chartCandidates` and `unitKind` was run against the working trees. In the backend working tree those symbols genuinely do not exist, because the commits that introduce them are not checked out. Searching the branch instead (`git grep <ref>`) finds `app/Services/Intelligence/DocumentAnalysisComposer.php` immediately. The failure was method: a cross-repo claim was made from two trees without first checking that they were at comparable points.
>
> **Status in `main`: still absent on both sides** — the visualization work is unmerged in both repositories (§0.2). So "a client calling the deployed API today receives no `analysis` key" is accurate *for main*; "no backend code produces it" is not.

#### 5.3.1 What actually exists (on the visualization branch)

`app/Services/Intelligence/` — 11 classes, ~3 100 insertions with tests:

| Class | Role |
|---|---|
| `DocumentAnalysisComposer` | assembles the whole `analysis` payload; no provider call, no writes |
| `MetricCollector` | reads accepted metric evidence into `MetricObservation`s |
| `MetricObservation`, `Measurement`, `ReportingPeriod` | readonly value objects |
| `MeasurementParser` | parses value + unit → magnitude, currency, scale, kind |
| `PeriodParser` | parses period text → granularity, basis, label, chronological order |
| `ChartCandidateBuilder` | chart eligibility, grouping, scoring, rejection counts |
| `TakeawayBuilder` | grounded takeaways + unsupported-summary notes |
| `AnalysisGrouper` | Tier-2/3-style grouping of findings |
| `ImportantFindingsBuilder` | usefulness-tier ranking of remaining findings |
| `KpiLabelNormalizer` (existing) | label normalisation, reused |

Tests: `tests/Feature/DocumentIntelligenceAnalysisTest.php` (38 tests), `tests/Unit/IntelligenceNormalizationTest.php`, `tests/Concerns/BuildsIntelligenceFixtures.php`.

The composer's own docblock states the design constraints V2 inherits: *"No provider request is made here, nothing is written, and no new column or table is required, so a document processed before this layer existed produces the same analysis as one processed after it."* Every query is workspace- and `pipeline_key`-scoped, and every emitted reference is a `source_id` belonging to that document.

#### 5.3.2 Two facts from that branch that matter for V2

1. **`analysis` is derived on read, gated on status.** `DocumentIntelligenceResource` composes it only when `status ∈ {Ready, Needs Review}`, else `null` — deriving it on every poll during processing would be work nobody reads.
2. **The same commit *narrows* the existing `evidence` map.** Previously every evidence row with a `source_id` was returned; now only rows the response actually references (chart points, takeaways, group items, important findings, synthesis citations, and every returned risk/deadline/entity). The stated reason is that a rich document shipped the full quote of 100+ row-level table figures nothing referenced. **This is a backward-incompatible narrowing of an existing API field, already decided on that branch.** V2 does not introduce it and must not be blamed for it, but V2's contract must acknowledge it (contract §18.2).

#### 5.3.3 The original frontend type, which remains the binding shape

`CA/src/types.ts:190-280` defines `DocumentAnalysis`, documented as *"the `analysis` section of the intelligence response … produced by the backend from accepted evidence it already stored: no extra AI request"*:

```ts
DocumentAnalysis {
  overview: { takeaways: AnalysisTakeaway[]; summaryNotes: AnalysisSummaryNote[] }
  visualAnalysis: { charts: AnalysisChart[]; omitted: number; rejected: Record<string, number> }
  analysisGroups: AnalysisGroup[]
  importantFindings: AnalysisFinding[]
  stats: { acceptedFindings, metricFindings, chartableFindings, chartCandidates,
           takeaways, summaryNotes, analysisGroups, importantFindings, groundedSources }
}
AnalysisTakeaway { id, origin: 'synthesis'|'metric'|'trend'|'risk'|'obligation',
                   text, detail, basis: 'explicit'|'inferred'|null, severity,
                   sourceIds, chartId }
AnalysisChart  { id, basis: 'time_series'|'categorical'|'composition',
                 type: 'line'|'bar'|'pie', title, description, metric, unit,
                 unitKind, currency, scale, points, series, sourceIds, score }
AnalysisFinding { sourceId, kind, label, value, unit, period, subject, severity,
                  dueDate, confidence, citedBySynthesis, page }
AnalysisSummaryNote { id, text, supported: false }
```

The frontend consumes it fully: `DocumentIntelligencePanel.tsx` has `normalizeAnalysis()` and renders `KeyTakeaways`, `VisualAnalysis`, `AnalysisGroups`, `ImportantFindings`; `chartLayout.ts` has `toChartRec()` for `AnalysisChart`; `reportBuilder.tsx` exports `importantFindings` to Word; `tests/document-intelligence-analysis.test.tsx` and `tests/chart-presentation.test.tsx` have full fixtures.

The frontend consumes it fully: `DocumentIntelligencePanel.tsx` has `normalizeAnalysis()` and renders `KeyTakeaways`, `VisualAnalysis`, `AnalysisGroups`, `ImportantFindings`; `chartLayout.ts` has `toChartRec()` for `AnalysisChart`; `reportBuilder.tsx` exports `importantFindings` to Word; `tests/document-intelligence-analysis.test.tsx` and `tests/chart-presentation.test.tsx` have full fixtures.

The backend counterpart on `feat/docintel-intelligence-visualization` conforms to this interface field-for-field (§5.3.1). So the two sides agree; they are simply unmerged, and were read at different points.

**Consequence while the work is unmerged:** on `main`, `intelligence.analysis` is absent, so four UI sections do not render — including the only consumer of `AnalysisSummaryNote.supported: false`, the existing mechanism for marking ungrounded prose. Merging fixes this; V2 does not need to.

V2 should treat `DocumentAnalysis` as a **backward-compatibility constraint it must satisfy, and an implementation it extends** — not as a blank sheet, and not as a gap to fill.

### 5.4 Other consumers

`DeadlinesPage`, `MatterDetailPage`, `DocumentConnections` and `reportBuilder` all consume the same field names via `SourceEvidence` and the matter/document resources. Any rename breaks them.

---

## 6. Power BI / export compatibility constraints

> **Status (clarification pass): Power BI was never implemented in production. It is treated from here on as deferred, future-compatibility documentation only — not an active integration, not a V2 blocker, and not something Prompt 2 should modify or test against.** §6.0 records the evidence. §6.2 is retained as *future* guidance for whoever eventually activates the feature, not as a constraint on V2.

### 6.0 There is no live feed — repository evidence

The first pass treated the two reporting views as a published contract that V2 had to protect. A direct check of what the running application actually exposes does not support that:

| Check | Result |
|---|---|
| HTTP/API surface for Power BI (`routes/api.php`, `routes/web.php`) | **none** — no route, no controller, no export endpoint |
| Who reads `workspace_settings.powerbi_enabled` | **nobody.** Only three writers, all setting it `false` (`WorkspaceObserver`, `BackfillWorkspaceSettings`) plus the model's `$fillable`/`$casts`. **It gates nothing.** |
| What `documents.power_bi_status` means | A derived label (`synced` / `not-synced` / `excluded`) maintained by `DocumentObserver` to describe *whether a row would appear in the views if anyone queried them*. **Nothing is pushed anywhere; no sync occurs.** Surfaced read-only in `DocumentResource` / `DocumentListResource`. |
| Reachability of the views | Only via a Postgres `LOGIN` role. Base role `powerbi_reader` is `NOLOGIN` with `PASSWORD NULL` (locked 2026-10-03); `powerbi_credentials` had **zero rows**; no per-workspace role exists. |
| Licence / activation | None. `POWERBI_DEFERRED_PLAN.md` (2026-10-03): *"deferred. No Power BI license yet. Nothing Power BI related should be active, exposed or provisioned in production."* |

So: no consumer, no credential, no endpoint, no gate, and a locked role. **There is no published report binding to these columns, because no one can connect.** The "column stability is the contract" framing in the first pass asserted an obligation to a consumer that does not exist.

This also answers an open question from `POWERBI_DEFERRED_PLAN.md`'s own checklist — *"Find out exactly what `workspace_settings.powerbi_enabled` gates"*. Repository answer: **nothing**. It is a dormant flag.

### 6.1 What exists, for future reference: two Postgres views, not an API

`2026_08_04_000013_create_power_bi_reporting_views.php`:

**`power_bi_kpis`** — `document_id, document_name, classification, year, document_uploaded_at, label, value_display, value_numeric, unit, trend, trend_value`
FROM `document_kpis` JOIN `documents` WHERE `classification != 'Restricted'` AND `status = 'Ready'`.

**`power_bi_chart_points`** — `document_id, document_name, classification, year, chart_id, chart_type, chart_title, label, value, sort_order`
FROM `document_chart_points` JOIN `document_charts` JOIN `documents`, same WHERE.

### 6.2 Future guidance — applies when, and only when, Power BI is activated

None of the following constrains V2. Each is recorded so that whoever activates the feature inherits the reasoning rather than rediscovering it.

1. **Appending is the only safe view change.** `DROP VIEW` + `CREATE VIEW` destroys grants (`POWERBI_DEFERRED_PLAN.md`); `CREATE OR REPLACE VIEW` preserves them but can only append columns at the end. So whenever a consumer *does* exist, additive-only follows from Postgres, not from policy.
2. **RLS has four coupled requirements.** `2026_08_19_081447_add_rls_to_powerbi_views.php` enables RLS on `documents`, `document_kpis`, `document_charts`, `document_chart_points` with policy `workspace_id = (SELECT workspace_id FROM powerbi_credentials WHERE db_role = current_user AND revoked_at IS NULL)`, flips both views to `security_invoker = true`, and grants `SELECT` to `powerbi_reader`. Any future view over a new table needs `workspace_id`, RLS enabled, that policy, and the grant. Missing the policy leaks across workspaces; missing the grant returns zero rows.
3. **Restricted documents are hard-excluded** regardless of app classification config, because BI is a broader-audience surface. Any future view repeats `classification != 'Restricted' AND status = 'Ready'`.
4. **`trend` / `trend_value` are already null for every incremental document** (§1.5). Whoever activates the feed should know these columns are structurally empty for the documents customers most care about, and decide then whether to populate them deterministically.
5. **Live grant state is unverified and had drifted.** `POWERBI_DEFERRED_PLAN.md` item 4 records that Neon's `powerbi_reader` had broader grants than the migrations describe. Reconcile before activation, as that document's own checklist requires.

Because there is no consumer, **this is the cheapest possible window to change the views** — but V2 has no reason to use it (contract §18.1).

### 6.3 Other export surfaces

- **Word export** — `CA/src/pages/reportBuilder.tsx`, client-side `docx`. Consumes `intelligence.analysis.importantFindings`, chart recs via `toChartRec`/`resolveChartType`, and the summary fields. No backend export endpoint.
- **No CSV/XLSX export exists** anywhere (`text/csv`, `toCsv`, `exportCsv` have no hits).
- **No REST export API** for BI; `POWERBI_SETUP.md` states this explicitly.

---

## 7. Evidence / source navigation

### 7.1 What is stored

Per source link: `{chunk_id, span_id?, start_offset, end_offset, quote, page|null}`, offsets into `documents.extracted_text`. Span-grounded records additionally carry `document_source_spans` rows with `ordinal`, `page`, `type` and a stable `span_key` under an `extraction_version`.

### 7.2 What is served

- `DocumentIntelligenceResource.evidence` — `{source_id: {quote, sources[]}}` for the current `pipeline_key`.
- `DocumentIntelligenceResource.sourcePages` — `{source_id: page}`, populated two ways:
  - evidence rows: only when `array_unique` over the sources' `page` values yields **exactly one** page;
  - legacy risk/deadline/entity rows: `EvidencePageLocator::locate()`, which returns a page **only** when `pages >= 2`, the text contains `\f`, `count(explode("\f")) === pages`, and the excerpt occurs on **exactly one** page. Any ambiguity returns null.

### 7.3 What the UI does with it

`SourceEvidence` (`CA/src/components/IntelligenceControls.tsx:68`) renders a `<details>` disclosure containing:
- `Source: <document name>`;
- `Page <n> · exact excerpt match`, when a page is known;
- the quote in a `<blockquote>`, or "No exact excerpt is available.";
- a `View document` hash link and an `Open original file` download button.

### 7.4 What does not exist

- **No in-document viewer and no highlighting of any kind.** Evidence is shown as a detached quote. The "View document" link navigates to the document page; it does not scroll to, or mark, the cited text.
- **No bounding boxes anywhere.** `ocr_results.metadata` is `json nullable` with a migration comment mentioning "bounding boxes, language detected, etc.", but the only writers are `ClaudeVisionOcrProvider` (`['source' => 'claude_vision']`) and `TesseractOcrProvider` (`['source' => 'tesseract', 'word_count' => n]`). `TesseractOcrProvider::parseTsv()` reads Tesseract's TSV and uses only columns 10 (`conf`) and 11 (`text`) — the `left/top/width/height` columns **are present in the input and discarded**. Recovering them would require re-running OCR, and would cover only OCR'd pages, never text-layer PDFs.
- **No character-offset → page-pixel mapping.** Offsets address `extracted_text`, which is a flat string with `\f` separators; nothing relates an offset to a position on a rendered page.

So for V2: offsets are reliable and already stored; pages are reliable but conservative; **bounding boxes are unavailable and cannot be obtained without re-OCR.** Text-match highlighting against `extracted_text`, anchored by the stored offsets, is the only viable mechanism.

---

## 8. Permissions and tenant isolation

### 8.1 Application layer

`DocumentPolicy::view()` — two boundaries, in order:
1. **Personal workspace:** `uploaded_by === user->id`, nothing else.
2. **Organization workspace:** `document.workspace_id === user.current_workspace_id` (outer boundary, carries an explicit comment that it was once missing), **then** role-vs-classification (inner boundary):

| Classification | Minimum roles |
|---|---|
| Public, Internal | Viewer, Analyst, Reviewer, Administrator |
| Confidential | Analyst, Reviewer, Administrator |
| Restricted | Reviewer, Administrator |

`approve` / `reject` / `reprocess` additionally require Administrator or Reviewer (Personal: owner).

`IntelligenceAccess` is the query-level counterpart: `workspace()` asserts membership in `current_workspace_id` (403), write operations require Analyst/Reviewer/Administrator outside Personal; `documents()` scopes by workspace and then by ownership (Personal) or `allowedClassificationsFor()` (Organization); `document()` additionally runs the `view` gate.

### 8.2 Data layer

Every intelligence table carries `workspace_id` with a cascade-delete FK. `document_evidence` and `document_chunks` are additionally scoped by `pipeline_key`. `EvidenceMerger` filters on `workspace_id` in every query. `DocumentKpi::saving()` enforces that a KPI definition and its document share the workspace, and `insertDerived()` checks the same invariant once per batch.

`DocumentIntelligenceResource` filters evidence by `document_id` **and** `workspace_id` **and** `pipeline_key`.

### 8.3 Power BI layer

RLS as described in §6.2.3, fail-closed: a role with no `powerbi_credentials` row makes the subquery return NULL, the equality is never true, and zero rows are returned. `powerbi_credentials` itself has RLS restricting a role to its own row. Table owner / superuser bypasses RLS, which is how the Laravel app is unaffected.

### 8.4 Implications for V2

Any new table must carry `workspace_id` with a cascade FK, be filtered by it in every query, and — if a Power BI view reads it — have RLS enabled with the `powerbi_workspace_scope` policy and a `powerbi_reader` grant. Any new API field must be served through a path that has already run `authorize('view')`. Any new endpoint must use `IntelligenceAccess` or the same gate.

---

## 9. Current chart / takeaway behavior

### 9.1 Charts

**Production:** `document_charts` + `document_chart_points`. Two writers:
- `GenerateInsightsJob` — normal route only; charts come from the model in the same call as insights and KPIs; existing charts are deleted first.
- `AnalyzeEmbeddedVisualsJob::mergeCharts()` — both routes; charts read out of embedded images by vision; **deduplicated by lowercased trimmed title** against existing charts; non-numeric points skipped.

> **Correction (clarification pass).** "No eligibility, scoring or comparability logic exists on the backend" is true of `main` and the audited tree, but **not** of the visualization branch, where `ChartCandidateBuilder` (521 lines) implements exactly that. Its real constants are recorded in contract §16, which the first pass got wrong by inventing its own.

**On `main` / the audited tree:** no eligibility, scoring or comparability logic. Nothing derives a chart from `document_kpis` or `document_evidence`.

**On the visualization branch:** `ChartCandidateBuilder` derives chart *candidates* (in the `analysis` payload) from accepted metric evidence, with three bases — `time_series`, `categorical`, `composition` — real comparability rules via `MetricObservation::groupKey()` (identical measurement family, currency, measure kind and basis), scale reconciliation but never currency conversion, and five named rejection counters (`insufficient_points`, `incomparable_periods`, `conflicting_values`, `not_a_composition`, `single_category`). Its docblock states the posture: *"DocIntel decides what is comparable; the provider is never asked for chart JSON"* and *"Nothing becomes a pie chart on the strength of looking like a breakdown."*

Note this does **not** change §1.5's finding: candidates land in the `analysis` API payload only. **No `document_charts` or `document_chart_points` rows are created**, so the route-dependence of persisted charts — and therefore of the dormant `power_bi_chart_points` view — is unchanged.

**Client-side resolution:** `CA/src/components/chartLayout.ts` is the single source of truth for rendering and overrides the backend's `type`:
- `type: 'pie'` with more than `MAX_PIE_SLICES` (6) points → renders as a **table**;
- `type: 'bar'` whose labels look sequential → promoted to a **line**: at least `MIN_POINTS_FOR_TREND_CHECK` (4) points, and ≥ `SEQUENTIAL_LABEL_MATCH_RATIO` (0.75) of labels matching `SEQUENTIAL_LABEL_PATTERN` (`Q1 FY25`, `2024`, `2024-01`, `Jan`…);
- horizontal bars when single-series and (≤ 8 points or max label length > `LONG_LABEL_THRESHOLD` 14);
- `chartGridSpan()` decides half vs full width.
Both overrides are flag-guarded (`ENABLE_TREND_OVERRIDE`, `ENABLE_PIE_OVERFLOW_GUARD`). `formatMeasure()` appends the document's own unit. The same module is used by the live dashboard and the Word export so they cannot disagree.

### 9.2 Takeaways

**Production takeaways are the five legacy string arrays** on `document_intelligence_summaries`: `key_findings`, `critical_risks`, `upcoming_deadlines`, `important_entities`, `recommended_attention`. Prompt v3 caps each at 3 short items and `executive_summary` at 2 sentences. They carry **no source_ids, no basis and no origin** — ungrounded prose, served identically to grounded content.

The V1.5 structured fields (`executive_assessment`, `material_findings`, `trends`, `tensions`, `questions`) *are* grounded (1–4 validated `source_ids`, `basis`) and capped at 1/4/2/2/3. `DocumentIntelligencePanel` renders them with `sources()`, which resolves each `source_id` through `intelligence.evidence`, then falls back to scanning the risk/deadline/entity/kpi arrays.

The intended `AnalysisTakeaway` model — `origin: 'synthesis'|'metric'|'trend'|'risk'|'obligation'`, non-empty `sourceIds`, optional `chartId` link, and `AnalysisSummaryNote.supported: false` for ungrounded prose — exists only on the frontend (§5.3).

### 9.3 Materiality — CORRECTED

> **Correction (clarification pass).** The first pass said "no materiality concept at all". That is true of `main` and of the audited backend tree, but **not** of the visualization branch, which contains a working materiality model. V2 extends it rather than introducing one.

**On `main` / the audited tree,** the only ordering signals are:
- `EvidenceBudget::forDocument()`'s five-arm `match` (§4.1) — unversioned, untested, inside a budget loop;
- `orderByDesc('confidence')` in the three paginated endpoints — a model-supplied number the extraction prompt itself disclaims;
- the per-field caps in the synthesis prompt and `ResponseValidator`.

**On the visualization branch,** `ImportantFindingsBuilder` implements a seven-tier usefulness model whose docblock makes the same argument this audit made independently as R12 — *"Extraction confidence is a poor headline signal: a row-level table figure is read with near certainty, which used to let routine metrics outrank a critical risk or an obligation falling due."*

```php
const TIERS = [                      // lowest number = most useful
  'critical_risk'       => 0,
  'high_risk'           => 1,   'upcoming_obligation' => 1,
  'dated_obligation'    => 2,
  'undated_obligation'  => 3,   'risk'                => 3,
  'metric'              => 4,   'fact'                => 4,
  'definition'          => 5,   'entity'              => 5,
  'other'               => 6,
];
const MAX = 8; const MAX_PER_STEM = 2; const MAX_PER_KIND = 3;
```

Properties already established there, which V2 must preserve:

- **Confidence is a tie-break inside a tier, never a tier signal.**
- `classify()` distinguishes `upcoming_obligation` (explicit calendar date, still ahead) from `dated_obligation` (explicit, past) from `undated_obligation` (relative or inferred — *"still an obligation; it just has no calendar date"*).
- A finding the document's own synthesis cited is **promoted exactly one tier** — "the repository's existing statement that the finding mattered… one tier is enough to let it overtake its neighbours without letting it jump the whole table."
- Items already shown in a chart or takeaway are excluded, so the most consequential items are not repeated.
- `unresolved` is explicitly *"an internal extraction state, never a reader's finding"*.

`TakeawayBuilder` adds a second, parallel budget: `MAX = 8` with per-origin quotas `synthesis 4 / metric 3 / trend 2 / risk 2 / obligation 1`, a `MIN_USEFUL_CHARS` floor of 25, and word-overlap de-duplication at 0.6. `ChartCandidateBuilder` adds a third: a `score()` function over basis, point count, synthesis citation and magnitude of change.

**So three independent budget-and-ranking mechanisms already exist**, each reasonable, none sharing a configuration or a version. That is the real finding — not absence, but **fragmentation**. V2's contribution is to unify them into one versioned, testable service without changing the decisions they currently make. See contract §5, §7, §9.

### 9.4 What is still genuinely missing

- No single versioned owner of weights, thresholds and caps: the numbers above are `private const`s in three classes.
- No forced-item concept — nothing guarantees a critical risk survives a cap; it only sorts first, and `MAX_PER_KIND = 3` can still exclude a fourth critical risk.
- No attention state, no coverage state as structured data (only the prose warning, §4.5).
- No deterministic verification of synthesis prose against cited records (R5, unchanged by the viz branch).

---

## 10. What can be changed additively

### 10.1 Safe — pure additions, no existing consumer affected

| Change | Why it is safe |
|---|---|
| New `jsonb` keys inside `document_evidence.data` | `$guarded = []`, cast to array; consumers read named keys. `identity()` reads a fixed field list, so new keys do not change identity. |
| New nullable columns on existing tables | No consumer selects `*` into a fixed shape; resources name fields. |
| New tables (records, tiers, briefs, verification, chart candidates) | Need `workspace_id` + FK + RLS when BI reads them (§8.4). |
| New keys on `documents.ai_pipeline` | Already a free-form jsonb bag read with `?? null` everywhere. |
| New top-level keys in `DocumentIntelligenceResource` — specifically `analysis` | The frontend already declares `analysis?: DocumentAnalysis \| null` and `normalizeAnalysis()` tolerates missing collections. |
| New `GET` endpoints under `/documents/{document}/…` | Must be registered **before** no conflicting literal segment; `/documents/search` and `/documents/query` precede `/documents/{document}`, so any new literal path needs the same care. |
| New fields on existing item resources | Additive; the TS interfaces are not exact-typed at runtime. |
| New columns appended to `power_bi_kpis` / `power_bi_chart_points` via `CREATE OR REPLACE VIEW` | Postgres allows appending at the end only — which is exactly the additive rule. |
| New Power BI views | Same `classification != 'Restricted' AND status = 'Ready'` filter, RLS, grants. |
| New config file `config/intelligence_v2.php` + a default-off flag | Mirrors `document_intelligence.evidence_spans`. |
| New `ai_prompts` version via a seeder | `AiPrompt::activate()` is the established mechanism; `PromptManager` resolves the active row. |

### 10.2 Safe only with care

| Change | Constraint |
|---|---|
| Bumping `pipeline_version` or `prompt_version` | Changes `pipeline_key` → the whole document re-extracts, which **costs provider calls**. Must be a deliberate, flagged migration, never a side effect. |
| Bumping `span_segmenter_version` | Invalidates every persisted span set; existing findings' `span_id`s stop resolving. |
| Changing `EvidenceBudget::forDocument()` ordering | It is the current de-facto materiality function; replacing it changes what synthesis sees. Must be flagged. |
| Populating `document_kpis.trend` / `trend_value` on the incremental route | Currently always null. Deterministic derivation is an improvement; inferred direction must be flagged and labelled. |

### 10.3 Must not change

- Any existing column name or type in `power_bi_kpis` / `power_bi_chart_points`.
- Any existing key in `DocumentIntelligenceResource` or the five item resources.
- The five legacy `document_intelligence_summaries` array columns and their camelCase API names.
- `due_date`'s meaning: a complete calendar date literally stated in the cited evidence.
- The `document_deadlines.date_type` CHECK, the `document_risks.severity` / `status` CHECKs, the `document_entities.entity_type` CHECK, the `confidence` 0..1 CHECKs.
- Workspace scoping on any query, and the RLS policy shape.

---

## 11. Risks and dependencies

### R1 — Provider-call budget is the hardest constraint
Extraction already costs one call per chunk plus splits and continuations; synthesis costs one plus a repair; vision costs more. `budget_max_usd`, `canReserve()` and `synthesisReservation()` enforce a per-document ceiling with separate holds for the current level, the next level and one repair. **Any V2 component that wants a provider call competes with synthesis recovery.** Everything V2 adds must be deterministic. (Contract: §Provider-call budget.)

### R2 — `pipeline_key` invalidation is expensive
`pipeline_key` = sha256 of workspace, document, text hash, `pipeline_version`, `prompt_version`, extraction model, grounding mode. Changing `pipeline_version` or `prompt_version` re-extracts **every document that is re-analysed afterwards**, at full provider cost. V2 must be able to run entirely on existing `document_evidence` rows under the existing key.

### R3 — Two routes, two output shapes
§1.5. V2 must define behaviour for `normal`-route documents, which have no `document_evidence` rows at all — only derived legacy rows with no offsets, no spans and no `pipeline_key`. (Contract: §Legacy-document behavior.)

### R4 — The `analysis` contract is already implemented on both sides (REVISED)
§5.3. Both the frontend type and the backend producer exist and agree; they are unmerged (§0.2). V2 **extends** `DocumentAnalysisComposer` and the `DocumentAnalysis` interface; it does not author them. Diverging from the interface means rewriting `DocumentIntelligencePanel`, `IntelligenceAnalysis`, `chartLayout`, `reportBuilder`, two frontend test files and `DocumentIntelligenceAnalysisTest`'s 38 backend tests. Conforming costs nothing.

### R5 — No numeric or entity verification exists
§4.4. Nothing today prevents the model writing "revenue rose 14% to $2.1bn" while citing a record that says 11% and $2.0bn. The `source_ids` check gives an appearance of grounding that the prose does not have. This is the sharpest correctness gap in the current system. (Contract: §Brief block structure, §Negative-claim guard.)

### R6 — Legacy summary arrays are ungrounded but indistinguishable
§3.4, §9.2. `keyFindings` and friends are plain strings in the same response as grounded structured items. They cannot be removed (backward compatibility) and cannot be retroactively grounded. V2 must label them rather than fix them.

### R7 — Bounding boxes are unavailable and unobtainable without re-OCR
§7.4. Tesseract's geometry is read and discarded; Claude Vision OCR never produced any; text-layer PDFs have no OCR pass at all. Highlighting must be text-match over `extracted_text`, anchored by stored offsets.

### R8 — Page attribution is deliberately conservative and often null
`EvidencePageLocator` and the evidence-row path both return null on any ambiguity, and `pagesKnown()` requires `type === 'PDF'` with an exact `\f` count. A large share of real documents will have `page: null`. V2's highlighting and chart/attention states must degrade cleanly rather than depend on a page.

### R9 — WITHDRAWN as a V2 risk (Power BI)
The first pass listed Power BI RLS coupling as a V2 risk. **Power BI is not implemented in production** (§6.0): no endpoint, no credential, no reader role that can log in, and `powerbi_enabled` gates nothing. V2 adds no Power BI view and no column, so none of the RLS coupling applies to it. The requirements are retained as future guidance in §6.2 for whoever activates the feature. **Power BI is not a V2 blocker and is not a Prompt 2 test target.**

### R10 — Period-only obligations are currently dropped
§3.2. V2's date roles will make them representable, which means documents re-analysed under V2 will show obligations that V1 silently discarded. That is a correctness improvement and an apparent behaviour change; it needs disclosing in the coverage/attention states rather than appearing as drift.

### R11 — `EvidenceBudget` is both the budget and the de-facto scorer
§4.1, §9.3. Extracting a real scorer means separating two concerns that currently share one loop. The 16 000-byte budget must keep bounding the payload regardless of what the scorer decides; a scorer that promotes more items must not be able to enlarge the request.

### R12 — Confidence is not a materiality signal
The extraction prompt says "Confidence is not evidence", yet `orderByDesc('confidence')` is the sort order on three endpoints and `confidence` is persisted on every derived row. The scorer must not inherit this; the existing endpoints' ordering should stay unchanged for compatibility.

### R13 — Soft deletes, re-analysis and staleness
`documents` uses soft deletes; `reanalyze()` supersedes synthesis chunks and sets `summary_stale`; `loadIntelligence()` hides a stale summary. Any new derived artefact needs the same staleness handling or it will be served against a superseded pipeline.

### R14 — WITHDRAWN as a V2 risk (Power BI)
Superseded by §6.0. There is no consumer to be compatible with, so there is no compatibility risk for V2 to carry. The views remain in the schema, dormant and untouched by V2.

### R15 — Branch divergence must be resolved before V2 starts (NEW, highest sequencing priority)
§0.2, §0.4. The visualization work is unmerged on both repos, and on the backend it diverges from the audit branch at `b537164`: the viz branch predates `ff37df8` (proactive chunk planning), so its diff *removes* `ProactiveChunkRisk.php`, the `2026_10_07_000002_add_docintel_attempt_timing` migration, `ProactiveChunkRiskTest` and a task doc. **A merge in the wrong direction silently reverts that work.** V2's design assumes `App\Services\Intelligence\*` exists, so the merge is a prerequisite, not part of V2. Resolve, verify both bodies of work are present, then branch.

### R16 — Three parallel ranking mechanisms, no shared configuration (NEW)
§9.3. `ImportantFindingsBuilder::TIERS` + caps, `TakeawayBuilder::MAX`/`QUOTAS`, and `ChartCandidateBuilder::score()` each rank and cap independently, as `private const`s in three classes. They do not disagree today, but nothing keeps them aligned, and none is versioned or configurable. V2's unification must preserve their current decisions — a refactor that changes what users see is a product change wearing a refactor's clothes.

---

## 12. The findings that survive the clarification pass

These five are unaffected by the branch correction and unaffected by the Power BI demotion. They are the substance of V2.

### 12.1 Period-only obligations are silently dropped — CONFIRMED, unchanged
§3.2. `EvidenceSchema::validateDate()` rejects `date_type = null` for `kind ∈ {deadline, obligation}`, so "payment is due in Q3 2026" is discarded rather than downgraded. The prompt instructs the model to emit exactly that shape for a partial period, and the validator then rejects it — prompt and validator disagree, and the record is lost.
Confirmed unchanged on the visualization branch. `ImportantFindingsBuilder::classify()` has an `undated_obligation` tier and notes that relative or inferred timing *"is still an obligation; it just has no calendar date"* — so the downstream layer is already willing to carry one. The loss is purely at the extraction gate.
**Contract:** §4.1–§4.5. Representation added; `due_date`'s "complete calendar date literally stated" guarantee preserved; legacy row written as `date_type = 'relative'` with no migration.

### 12.2 No deterministic verification of synthesis claims — CONFIRMED, unchanged
§4.4. `ResponseValidator::validateSummary()` checks that each `source_id` exists in what was sent. Nothing checks that a number, date, amount or name in the model's prose occurs in the records it cited. Verified unchanged by the visualization branch (`ResponseValidator` untouched).
This is the sharpest correctness gap in the system, and it is made sharper by the visualization branch, not softer: `TakeawayBuilder::fromSynthesis()` promotes `material_findings` and `trends` straight into the user-facing takeaway list on the strength of their `source_ids` alone. So unverified prose now has a more prominent surface than when it sat inside a summary blob.
**Contract:** §14 — ten deterministic checks, template fallback, no repair call.

### 12.3 Materiality is fragmented, not absent — REFRAMED
§9.3, §9.4, R16. Three independent ranking-and-capping mechanisms exist in `private const`s across three classes, plus `EvidenceBudget`'s `match` and `orderByDesc('confidence')`. None is versioned, configurable or unit-testable as a unit. No forced-item concept: a fourth critical risk can still be excluded by `MAX_PER_KIND = 3`.
**Contract:** §5, §7, §9 — one versioned service, forced-item rules, explained scores. Must preserve existing decisions (R16).

### 12.4 Synthesis output budget pressure — CONFIRMED, and now the binding constraint
§4.2, §4.3, R1. `synthesis_max_tokens` is 8192 for one call plus at most one bounded repair, with a four-level degradation ladder whose reservations are held separately. Any Brief block added to the synthesis schema competes for that output budget with `executive_summary` and `key_findings` — the two fields the repair call exists to rescue.
The visualization branch sharpens this too: because takeaways are now built from `material_findings` and `trends`, those optional fields have become load-bearing for the UI, while still being the first things dropped by `_optional_items_dropped` under pressure.
**Contract:** §19.4 — legacy fields keep priority, Brief blocks fall back to deterministic templates, and fixture case `22-legacy-field-priority` asserts it. This remains the one place V2 could degrade existing behaviour, so it needs measurement before the prompt changes.

### 12.5 Evidence highlighting has no in-document target — CONFIRMED, unchanged
§7.3, §7.4, R7, R8. Evidence is a detached `<details>` quote; there is no viewer and no highlighting. No bounding boxes exist anywhere: `TesseractOcrProvider::parseTsv()` reads the TSV and uses only the confidence and text columns, discarding `left/top/width/height`; `ClaudeVisionOcrProvider` never produced geometry; text-layer PDFs get no OCR pass at all. Recovering geometry needs re-OCR and would cover only OCR'd pages.
What *is* reliable: character offsets into `extracted_text`, stored on every evidence source, plus span IDs under an `extraction_version` on the span-grounding branch.
**Contract:** §15 — offset highlighting for the text view, text-match with `needle`/`occurrence`/`occurrences` elsewhere, degrading to `page_only` then `none`. No re-parse, no re-OCR.

---

## Change log

### 2026-10-07 — clarification pass

| # | Change |
|---|---|
| 1 | **Added §0** — audit provenance, branch containment, and the finding that the intelligence visualization work is unmerged on **both** repos (not in `main`, contrary to the stated premise). |
| 2 | **Corrected §5.3** — withdrew "no backend code produces `analysis`". `DocumentAnalysisComposer` and 10 sibling classes exist on `feat/docintel-intelligence-visualization`; the error came from comparing a frontend tree that had the work against a backend tree that did not. Documented the method failure. |
| 3 | **Corrected §9.1** — `ChartCandidateBuilder` implements chart eligibility, comparability and scoring. Recorded that it still writes no `document_charts` rows, so §1.5 stands. |
| 4 | **Corrected §9.3** — withdrew "no materiality concept at all"; replaced with the real `ImportantFindingsBuilder` tier table and the fragmentation finding. Added §9.4 for what is still missing. |
| 5 | **Qualified §1.5** — persisted-chart route-dependence stands; the UI symptom is mitigated. |
| 6 | **Demoted §6 (Power BI)** — added §6.0 with evidence of no live feed (no route, no credential, `NOLOGIN` role, `powerbi_enabled` gates nothing, `power_bi_status` is an eligibility label). Recast §6.2 as future guidance. Answered the deferred plan's own open question about `powerbi_enabled`. |
| 7 | **Risks** — revised R4; **withdrew R9 and R14** (Power BI); added **R15** (branch divergence, highest sequencing priority) and **R16** (three parallel ranking mechanisms). |
| 8 | **Added §12** — the five findings that survive, each with its confirmation status and contract reference. |

**Unchanged and re-confirmed:** §1–§4 (data flow, record shapes, date semantics, synthesis behaviour), §7–§8 (evidence navigation, permissions and tenant isolation), and findings R1, R2, R3, R5, R6, R7, R8, R10, R11, R12, R13.
