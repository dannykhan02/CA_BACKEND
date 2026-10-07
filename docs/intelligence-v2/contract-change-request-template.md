# Intelligence V2 — contract change request

Copy this file to `docs/intelligence-v2/changes/CR-<nnn>-<short-slug>.md`, fill it in, and open it for review **before** writing code. The contract is [`intelligence-v2-contract.md`](intelligence-v2-contract.md); the architecture it was drawn from is [`current-architecture-audit.md`](current-architecture-audit.md).

> **Revised 2026-10-07 (clarification pass).** Power BI is out of scope for V2 (contract §18.1 P1, audit §6.0): it was never implemented in production, so there is no consumer to stay compatible with. §6 of this template is now a single declaration rather than a checklist. Two new sections cover what the pass surfaced: §6b (existing-implementation impact) and §9b (branch prerequisite).

A change request is required for **any** of the following, even when the code change is small:

- adding, removing or changing a provider call, or changing how many are made per document
- **touching Power BI at all** — any view, column, role, grant or RLS policy (contract §18.1 P1 forbids it outright, so a CR here is a scope change, not a tweak)
- changing what `ImportantFindingsBuilder`, `TakeawayBuilder` or `ChartCandidateBuilder` currently decide — their tiers, quotas, caps, eligibility rules, rejection codes or scores (contract T9)
- renaming, retyping or removing any existing API key, resource field or database column
- changing the `DocumentAnalysis` interface, or what `DocumentAnalysisComposer::referencedSourceIds()` returns
- widening or narrowing a CHECK constraint, or adding an enum value to an existing enum
- changing the meaning of `due_date`, `date_type`, `basis`, `confidence` or `severity`
- loosening any date-grounding rule (§1.2 V3, §4.2)
- changing a Tier 1 budget number, a forced-item rule, a scorer signal, a weight or a band
- changing anything that affects `pipeline_key`, `extraction_version` or re-extraction
- permitting a new `origin` / `assertion` / `attribution` combination
- weakening or skipping a verification check (§14.2)
- permitting an absence claim under any coverage state other than `complete` (§12)
- updating a conformance golden file (§22.5)
- changing feature-flag default values or flag-off behaviour (§20)

---

## CR-<nnn>: <one-line title>

| | |
|---|---|
| **Author** | |
| **Date** | |
| **Status** | `draft` / `in review` / `approved` / `rejected` / `superseded by CR-<nnn>` |
| **Approver** | |
| **Approved on** | |
| **Supersedes** | CR-<nnn>, or none |

---

### 1. Contract sections affected

List every section of `intelligence-v2-contract.md` this touches, by number and name. If the answer is "none", this is not a contract change and does not need a CR.

| Section | What changes |
|---|---|
| §<n> <name> | |

Invariants affected (V1–V7, T1–T7, B1–B6, C1–C5, P1–P7, A1–A6, D1–D5, L1–L6, F1–F5), or "none":

---

### 2. What is being changed

State the current contract text, then the proposed text. Quote the contract; do not paraphrase it.

**Current:**

> …

**Proposed:**

> …

---

### 3. Why

What problem does this solve? What breaks, or stays wrong, without it? Prefer an observed case — a document, a report, a customer question — over a hypothetical.

---

### 4. Alternatives considered

At least one, including "do nothing". Say why each was rejected.

| Alternative | Why not |
|---|---|
| Do nothing | |
| | |

---

### 5. Provider-call impact

> §19 of the contract: **V2 adds zero provider calls by default.** Anything that adds one needs explicit approval here.

- [ ] **No change.** This CR adds no provider request, changes no request count, and changes no request size.
- [ ] **Request size changes** (schema, prompt or payload grows or shrinks) — complete 5a.
- [ ] **A new provider request is required** — complete 5a **and** 5b. This requires explicit approval and must be called out in the review summary.

**5a. Size and budget**

| | Before | After |
|---|---|---|
| Stage affected | | |
| Input bytes / tokens (bound) | | |
| Output tokens requested | | |
| Effect on `synthesis_token_budget` consumption | | |
| Effect on `sourceReserveBytes()` | | |
| Effect on `synthesisReservation()` arithmetic | | |
| Effect on legacy-field production under truncation (§19.4) | | |

**5b. New request**

| | |
|---|---|
| Stage | |
| Calls per document (typical / worst case) | |
| Model and `max_tokens` | |
| Estimated cost per document (USD) | |
| Effect on `budget_max_usd` and `canReserve()` | |
| Effect on `ProviderGate` in-flight pressure | |
| What happens when the call fails — and why that is not a new failure mode for synthesis recovery | |
| Why this cannot be done deterministically | |

---

### 6. Power BI impact

> Power BI is out of scope (contract §18.1 P1). The expected answer is the first box.

- [ ] **No Power BI change.** This CR creates or alters no view, no column, no role, no grant and no RLS policy, and does not modify `PowerBiIsolationTest` or `PowerBiRlsTest`.
- [ ] **This CR touches Power BI** — then it is a **scope change**, not a contract tweak. State: why V2 needs a dormant integration it was scoped to leave alone; whether the licence now exists; whether the live grant drift in `POWERBI_DEFERRED_PLAN.md` item 4 has been reconciled; and who has approved re-opening the integration. Then follow audit §6.2's future guidance in full.

### 6b. Existing-implementation impact

> New section. The visualization work (`App\Services\Intelligence\*`) already implements chart eligibility, takeaway generation and a materiality tier model. A CR must say what it does to them.

- [ ] Touches none of them.
- [ ] Touches one or more — complete the table.

| Class | What changes | Current value → new value | Does any user-visible ranking or selection change? |
|---|---|---|---|
| `ImportantFindingsBuilder` (`TIERS`, `MAX`, `MAX_PER_KIND`, `MAX_PER_STEM`, `classify()`) | | | |
| `TakeawayBuilder` (`MAX`, `QUOTAS`, `MIN_USEFUL_CHARS`, `DUPLICATE_OVERLAP`, `MIN_GROUNDED`) | | | |
| `ChartCandidateBuilder` (`MAX_CATEGORIES`, `MAX_SERIES`, tolerances, `score()`, rejection codes) | | | |
| `MeasurementParser` / `PeriodParser` | | | |
| `DocumentAnalysisComposer` (`MAX_CHARTS`, `referencedSourceIds()`, status gate) | | | |

- [ ] Contract **T9** still holds: for the existing `DocumentIntelligenceAnalysisTest` corpus, ranking and selection are unchanged — or the difference is stated above and approved
- [ ] The 38 existing tests in `DocumentIntelligenceAnalysisTest` and the cases in `IntelligenceNormalizationTest` still pass **unmodified**, or each modification is justified here
- [ ] Any newly referenced `source_id` is registered in `referencedSourceIds()`, so its evidence is still served (A7)

---

### 7. Existing-API impact

- [ ] **Additive only.** New keys; no existing key renamed, retyped, made nullable/non-nullable, or removed; no enum value removed.
- [ ] **An existing key changes** — breaking; list every frontend consumer below and state the migration.

| Response / resource | New key | Type | Nullable |
|---|---|---|---|

Consumers checked (`CA/src`): `DocumentIntelligencePanel`, `IntelligenceAnalysis`, `IntelligenceControls` / `SourceEvidence`, `chartLayout`, `reportBuilder`, `DeadlinesPage`, `MatterDetailPage`, `DocumentConnections`, `types.ts`:

- [ ] Checked; impact described below (or "none")

Does this diverge from the committed `DocumentAnalysis` interface in `CA/src/types.ts`? If yes, list every frontend file and test that must change, and say why conforming was not possible.

---

### 8. Database impact

- [ ] No migration.
- [ ] New nullable columns only.
- [ ] New table(s).
- [ ] Something else — describe.

- [ ] No column dropped, renamed or retyped (D1)
- [ ] No CHECK constraint widened or narrowed (D2)
- [ ] New columns nullable, with no default that changes existing-row semantics (D3)
- [ ] `EvidenceMerger::identity()`'s field list unchanged, or the change is deliberate and its effect on evidence identity and row duplication is described (D4)
- [ ] Every new table carries `workspace_id` with a cascade FK and is filtered by it in every query (D5)
- [ ] Reversible `down()`, or a stated reason it cannot be

---

### 9. Re-extraction and versioning impact

- [ ] `pipeline_key` unaffected. No document re-extracts.
- [ ] `extraction_version` / `span_segmenter_version` unaffected. No span set is invalidated.
- [ ] One of them **is** affected — state the provider cost of re-extracting the affected population, how many documents that is, and who approved the spend.

Version strings bumped (§9.4):

| Version | Bumped? | What recomputes | Provider calls |
|---|---|---|---|
| `materiality.version` | | | must be 0 |
| `values.parser_version` | | | must be 0 |
| `brief.template_version` | | | must be 0 |

---

### 9b. Branch prerequisite

> New section. Audit R15: the visualization work is unmerged on both repos, and on the backend it diverges from `ff37df8` in a way that can silently revert proactive chunk planning.

- [ ] This CR is being written against a tree that **has** `App\Services\Intelligence\*` merged
- [ ] `ProactiveChunkRisk.php`, `2026_10_07_000002_add_docintel_attempt_timing`, `ProactiveChunkRiskTest` and `SpanChunkPlannerTest` are all still present (i.e. the merge did not revert them)
- [ ] Frontend and backend are at comparable points — any cross-repo claim in this CR was checked against both at the **same** merge state

Tree this CR assumes (repo → branch → HEAD):

---

### 10. Legacy-document impact

How does this behave for each population (§21)?

| Population | Behaviour |
|---|---|
| `incremental` route, current `pipeline_key` | |
| `normal` route (adapter, no evidence rows, no offsets) | |
| Superseded `pipeline_key` | |

- [ ] No backfill job, no re-processing, no provider call for any legacy document (§21.4)

---

### 11. Feature-flag behaviour

- [ ] Flag-off output is byte-identical to today: new keys **absent**, not null; new routes unregistered (§20.2)
- [ ] Flipping the flag does not change `pipeline_key` and does not re-extract (F1)
- [ ] Flipping off hides output and leaves it in place; flipping on serves it again without recomputation unless a version changed (F3)
- [ ] A new flag, if any, is declared in `config/intelligence_v2.php` and defaults **off**

New or changed flags:

| Flag | Default | What it gates |
|---|---|---|

---

### 12. Safety and correctness review

- [ ] No new way for a model-supplied string to become evidence text, an offset, an identifier or a number
- [ ] Every number and date in any new generated text is verified against cited records (§14.2)
- [ ] No new path can emit `assertion: absent` outside §12
- [ ] No new path can hide a forced Tier 1 item, a coverage shortfall or a truncation (T4, §11.4, C4)
- [ ] No AI-written content becomes unlabelled: `ai_generated` and `verification` present (§13.6)
- [ ] No weight, threshold, day count or pattern list is introduced outside `config/intelligence_v2.php` (§9.1)
- [ ] Workspace scoping unchanged on every query; every new read path runs `authorize('view')` or `IntelligenceAccess`
- [ ] Logging stays metadata-only: no evidence, source text, prompt or response in any log line
- [ ] Untrusted-input posture unchanged: document text is data, never instructions

---

### 13. Conformance fixtures

- [ ] No fixture changes.
- [ ] New case(s) added — list them.
- [ ] Existing golden file(s) updated — **§22.5: list each file, the contract section that justifies it, and what changed.** A golden-file update with no contract section cited is a rejection.

| Fixture path | New / updated | Contract section | What changed |
|---|---|---|---|

New unit tests (§9.6), if the scorer, a signal, a forced rule or the parser changed:

---

### 14. Rollout and rollback

**Rollout:**

**Rollback:** what is the single action that reverts this, and what is left behind afterwards?

- [ ] Rollback is a flag flip
- [ ] Rollback needs a migration — describe
- [ ] Rollback leaves data behind — describe what, and confirm it is inert

---

### 15. Open questions

Anything the approver must decide, rather than review.

---

### 16. Approval

- [ ] Contract sections updated in `intelligence-v2-contract.md` **in the same change**, so the contract and the code never disagree
- [ ] §5 provider-call impact explicitly acknowledged by the approver
- [ ] §6 confirms no Power BI change, or the approver has explicitly re-opened that integration as a scope change
- [ ] §6b existing-implementation impact reviewed, and T9 confirmed or its difference approved
- [ ] §9b branch prerequisite confirmed
- [ ] §7 breaking changes explicitly acknowledged by the approver (if any)

**Approver notes:**
