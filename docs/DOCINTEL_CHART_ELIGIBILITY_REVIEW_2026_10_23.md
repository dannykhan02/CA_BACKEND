# Independent Review — Document Intelligence and Chart Verification

Date: 2026-10-23
Scope: why the Nasdaq Q3 2026 run produced 0 analytically eligible observations from 193, and whether the architecture can work.
Method: read the deployed code in `app/Services/Intelligence`, `app/Services/Extraction`, `app/Jobs`. No database, PDF or screenshot access. Code citations are facts; everything else is labelled.

---

## 0. Headline

Your central hypothesis — "the verifier cannot consistently establish the relationships between these pieces of evidence" — is correct, but it understates the problem. It is not inconsistency. For **any PDF**, the incremental route's proof mechanism **cannot establish table relationships at all**. The 0/193 result is not a tuning failure, a model failure, or a feature-flag failure. It is the deterministic output of three facts in the code that compose into an impossibility.

The good news, stated up front so the rest is read in proportion: **you do not need a rewrite.** Your storage model already supports exactly what is missing, and already produces it for XLSX and DOCX. You are one extractor away, not one architecture away.

---

## 1. Proven root cause

### Fact 1 — PDFs never get table structure

Structured extraction emits typed nodes consumed downstream. Node types by extractor:

| Extractor | Format | Node types emitted |
|---|---|---|
| `Extraction/SpreadsheetTextExtractor.php:93,133` | XLSX | `worksheet`, `table`, `cell` |
| `DocumentTextExtractor.php:344` | DOCX | `paragraph`, `table`, `row`, `cell` |
| `Extraction/PdfHybridAssembler.php:63`, `DocumentTextExtractor.php:67` | **PDF** | **`page` only** |

PDF parsing is `smalot-page.v2` / `smalot-page-ocr.v1` — `smalot/pdfparser`, a text extractor with no table reconstruction. A PDF therefore yields page nodes containing a flat character stream. There is no cell, no row, no column, no bbox.

### Fact 2 — Without table nodes, `structural` capability is impossible

`Intelligence/SourceProvenanceReader.php:59-72` sets `capabilities.structural = true` for an observation only if one of its `value` anchors has an outgoing `source_relationships` edge of type `row_header`, `column_header` or `table_parent`.

Those three edge types are written in exactly one place: `Intelligence/StructuredProvenanceProducer.php:125-186`, which builds them from `table`/`row`/`cell` nodes. No table nodes ⇒ no such edges ⇒ `structural = false` for every observation in every PDF.

**Your own evidence confirms this exactly.** You report 171 relationships, all `contained_by`. `contained_by` is the only edge type `Intelligence/TextRoleProvenanceProducer.php:144` emits. Your graph is the pure text-role producer's output with zero structural contribution — which is what Fact 1 predicts.

### Fact 3 — With `structural = false`, table metrics are mathematically unprovable

`Intelligence/IncrementalMetricFieldVerifier.php:40-42` — when `structural` is false, the observation must clear `proseRelationshipRejection()` (`:88-124`). For each required role, that method accepts the relationship only if:

```php
$between = mb_substr($text, min($start,$from), max($end,$to) - min($start,$from));
if (mb_strlen($between) <= 240
    && preg_match('/[!?\n]|(?<!\d)\.|\.(?!\d)/u', $between) !== 1) { $related = true; }
```

The role anchor and the value must sit within 240 characters **with no newline and no sentence-terminating punctuation between them**.

In a financial statement, the period column header (`Three Months Ended September 30, 2026`) and the unit heading (`(in millions)`) are, by definition of being headings, on *different lines* from the value cell. A newline between them is guaranteed. The rule can therefore never be satisfied for a table-derived period, unit, subject or scale.

This is not a strict policy. It is a test that the document's own layout forbids passing.

### Fact 4 — Role binding is literal-exact, so `period` was never bindable in the first place

Before the relationship test, roles must exist at all. `TextRoleProvenanceProducer.php:104-117` binds a role only when the field's value occurs as an **exact substring, exactly once, inside the single stored quote** for that observation:

```php
$at = mb_strpos($quote, $needle);
if ($at === false || mb_strpos($quote, $needle, $at + 1) !== false) { continue; }
```

The quote for a value is its table row. The period lives in the header row, not the value's row. So `period` is unbindable in principle.

**Your role distribution proves it:** Value 124, Metric 56, Subject 28, Scale 3 — **`period`: absent entirely.** Zero period bindings in 211. And `IncrementalMetricFieldVerifier.php:27-30` requires the `period` role whenever `data['period']` is non-empty. You report 190 observations with period values and **190** `period_source_mismatch` occurrences. The numbers match to the unit.

Two further binding killers in the same method:

- `:101` — `if ($document->type === 'XLSX' || str_contains($quote, ' | '))` skips role binding **entirely** for any pipe-delimited flattened row. If your PDF span serialization uses `" | "`, binding is skipped wholesale.
- The match is byte-literal. `USD millions` never matches a caption reading `(in millions)`. `Q3 2026` never matches `3Q26`.

### The contradiction this creates

This reframes your §6. You filed the inconsistent period/unit formatting as a *grouping* risk. It is actually a *proof* blocker, and the pipeline causes it:

> You ask the model to emit clean, normalized identities (`Q3 2026`, `USD millions`) because normalization is required for comparison grouping. The binder then requires verbatim document text. **Every normalization the model performs to satisfy identity destroys the provenance binding.** The two requirements are in direct opposition, and the model cannot satisfy both.

This is the single most damaging design flaw I found, and unlike Facts 1–3 it is cheap to fix (§6, Tier 1).

### Fact 5 — The table verifier you were counting on never ran

§4 of your brief rests on "we have a separate PDF table verifier, so absence of explicit row/column edges does not prove table verification is impossible." For this run, it does.

`Intelligence/PdfTabularCellVerifier` is reachable only from `Intelligence/NormalKpiGrounder.php:56`, which is called only from `Jobs/GenerateInsightsJob.php:219` — the **normal** route. Your run was incremental (`Jobs/MergeDocumentEvidenceJob.php:154`). The same is true of `RowExpander` (`GenerateInsightsJob.php:166`, and additionally requires the flag *and* PDF type) and `EvidenceResolver` (`NormalKpiGrounder.php:37`).

On the incremental route there is exactly **one** provenance mechanism — the text-literal one — and no table-aware backstop of any kind.

Related asymmetry worth knowing: `MeasurementIntegrity.php:33` sets `ownership_enforced` only when `pipeline_key` starts with `normal:`. On the incremental route your entity-ownership and population checks are **silently skipped**. Your strictest route is strict about different things than you think.

### Fact 6 — The flag cannot be the explanation

You asked me not to assume it is. It provably is not. `server_evidence_resolution` appears at `GenerateInsightsJob.php:165`, `NormalKpiGrounder.php:36`, `PdfTabularCellVerifier.php:152`, `RowExpander.php:17` — all normal-route, none reachable from the incremental merge. None of them writes `data['source_fields_verified']`, which is the *only* field `MeasurementIntegrity` consults under `field_verification_policy = incremental.v1`. Toggling the flag cannot change 0/193 on this route. Your development evaluations that showed recovery were almost certainly exercising the normal route — which is why they looked promising and did not transfer.

### Fact 7 — You already detect the structure you then discard

`AI/Incremental/SourceSpanBuilder.php:113,128` already classifies spans as `table_row`. Your 367 source spans include table-row typing. That typing feeds chunk planning (`ChunkPlanner.php:30`, `ProactiveChunkRisk.php:13`) and the normal-route verifier — but `TextRoleProvenanceProducer` never consults it to emit header edges.

**You detect table rows, then throw the information away immediately before the one stage that needs it.** This is the cheapest available intervention.

### Reading your rejection table correctly

Your reason counts over-count, so do not read them as nine distinct problems. `concept_source_mismatch` at 224 exceeds your 193 observations because the same defect is appended twice: once as `grounding_rejection` by `IncrementalMetricFieldVerifier` and again by the `incremental.v1` policy block in `MeasurementIntegrity.php:110-118`. Collapsed, your nine reasons are **one** defect with three faces:

1. no `period` role can be bound (190)
2. no `unit` role can be bound (192)
3. no structural edges exist, so the relationship fallback rejects everything else

All three are Facts 1–4. One cause.

---

## 2. A. Is the architecture fundamentally sound?

**The principle is sound. The representation is not.**

Extracting model-proposed observations and verifying them independently against the source is the right design, and it is what credible systems in this space do. Do not abandon it. Your revision pinning (`hash('sha256', extracted_text)` compared via `hash_equals`), separate per-field proof, anchor/edge/binding storage, and a hard eligibility gate are genuinely good and better than most products in this category.

The defect is precise and narrow: **you demand proof of two-dimensional relationships against a one-dimensional representation.** A table's meaning lives in row/column geometry. You discard the geometry at parse time, keep a flat string, and then ask the verifier to prove geometric facts from character offsets. The verifier is not failing. It is correctly reporting that the evidence it was given cannot support the claim.

So, to your framing in §8: you are not merely "reconstructing relationships afterward" inefficiently. For PDFs you are attempting to reconstruct information **that was destroyed at parse time** — and your verifier is honest enough to refuse. The refusal is the system working. The information loss upstream is the bug.

**Preserve:** anchors/relationships/bindings schema (it already whitelists `row_header`, `column_header`, `table_parent` at `SourceProvenance.php:15,18`); source-revision and extraction-version pinning; field-level proof separation; the eligibility gate; `MeasurementParser`/`PeriodParser` as deterministic components; the rule that re-verification needs no paid inference.

**Replace:** PDF provenance built from raw character offsets; literal-substring role binding; the 240-character proximity rule.

**Add:** a geometry-based PDF table reconstruction stage.

Your §8 proposal — a financial observation as an atomic relationship between value cell, metric row, period column, unit heading, entity, basis and provenance — is **correct, and it is already what `StructuredProvenanceProducer` builds for XLSX and DOCX.** You are not proposing a new architecture. You are proposing to finish the one you have.

---

## 3. B. Are the verification standards reasonable?

The *list* of checks is close to right. The *proof generation* is what fails. Keep the policy; fix the evidence.

**Essential — these genuinely prevent misleading financial charts. Do not relax any of them:**

- value ↔ cell identity (which cell is this number)
- period attribution (the single most dangerous failure; mislabelling Q3 2025 as Q3 2026 inverts a conclusion)
- unit and scale (millions vs thousands vs units is a 1000× error)
- reporting entity / segment scope (your §6 case of Market Services revenue carrying a `Nasdaq, Inc.` scope is exactly the dangerous class — it produces a chart that is plausible and wrong)
- accounting basis (GAAP vs non-GAAP; mixing them in one series is indefensible)
- sign convention (parenthesised negatives, expenses shown positive)

**Unnecessarily restrictive, or simply wrong:**

- **The 240-char/no-punctuation proximity rule.** Arbitrary in both directions. Impossible for tables (Fact 3), and simultaneously *too lax* for prose: two unrelated facts in one long sentence pass it. It is not a weaker version of a good check; it is not measuring relatedness.
- **Requiring the `metric` role to match the model's label verbatim.** A row reads `Financial Technology`; a good label is `Financial Technology revenue`. Your 56/193 metric bindings are mostly this. Penalising the model for producing a *better* label than the raw text is backwards.
- **Double-appended rejection reasons.** Not a correctness bug, but it makes your diagnostics overstate the problem's breadth and sent this investigation looking for nine causes.
- **`ownership_enforced` gated on route** (`MeasurementIntegrity.php:33`). Either entity proof matters or it does not. Route should not decide.

To your direct question — do professional systems require this degree of field-level proof? For *automated publication of financial charts*, yes, and arguably more: serious systems also run arithmetic consistency checks (do segments sum to the total?) which you do not, and which are cheaper and stronger than most of what you do run. See §5.

---

## 4. C. How should document structure be handled?

Yes — reconstruct structure before metric extraction, for tables. Per format:

**XLSX** — already correct. `SpreadsheetTextExtractor` emits real cells; `StructuredProvenanceProducer` builds real edges. Keep. For merged cells, store the merge range and propagate the anchor cell's value to covered coordinates with the merge recorded in provenance.

**DOCX** — already has `tbl`/`tr`/`tc` (`DocumentTextExtractor.php:344`). The structure is there; the gap is that `DocxTableCellVerifier` and `DocxWindowBuilder` are normal-route only.

**Native PDF** — the real work, and the only genuinely new component you need. `smalot/pdfparser` will not give you dependable word geometry. Use word-level bounding boxes (`pdftotext -bbox-layout` from poppler is deterministic, free, fast and likely already a system dependency; PyMuPDF if you want a library) and reconstruct cells by clustering word x-ranges into columns, plus ruling-line detection where lines exist. For an official earnings release this is a well-behaved problem: the tables are machine-generated, consistently aligned, and usually ruled.

**Scanned PDF** — you already store Tesseract word boxes (`ImageWordBoxStore`, `TextRoleProvenanceProducer::ocrWordRegions`). The *same* geometry→cells clustering algorithm runs on OCR boxes. One algorithm, two input sources. This is strong leverage and a reason to build the geometry path rather than a text-heuristic path.

**Complex layouts and merged cells** — accept partial success, emit a confidence score, route low confidence to review (§7) rather than silent rejection.

**Honest limitations of structure-first.** It will not solve everything, and these are the cases that will bite:

- multi-row and spanning column headers (`Three Months Ended` spanning two year columns) — must produce a header *path*, not a single header
- footnote superscripts fused to numbers (`1,481(2)`)
- parenthesised negatives — must be parsed as sign, or every expense chart inverts
- tables continuing across pages with headers repeated, or not repeated
- indent-encoded row hierarchy (`Total revenues` vs its indented children) — needed to avoid double-counting a subtotal and its components in one chart
- rotated/landscape pages and multi-column page layouts

None of these is a reason not to do it. All of them are reasons to scope the first version to ruled or cleanly whitespace-aligned financial tables, measure coverage honestly, and send the rest to review.

---

## 5. D. How much should you trust AI?

Your current allocation is close to inverted, and it is costing you money.

**Deterministic code should own** (never an LLM): geometry → cells; number parsing; sign and scale; unit propagation from caption to cells; period parsing from header text; arithmetic cross-checks; cell↔anchor identity.

**The LLM should own**: naming and labelling; mapping a row label to a canonical metric identity; basis classification (GAAP/non-GAAP); judging which facts matter; narrative and caveats; resolving genuine linguistic ambiguity.

**The verifier should own**: confirming the LLM's *labels* against the cell's actual row/column/caption text — not reconstructing the relationship the parser should have supplied.

**Would a stronger model fix this? No.** This is the clearest conclusion in the review. No model output can pass a verifier whose proof mechanism cannot express table relationships. Haiku, Sonnet and Opus all produce exactly 0/193 here. Do not spend a cent on model upgrades to address this failure; it would be the most expensive possible way to make no difference.

And note the economic absurdity of the current arrangement: **you pay an LLM to re-derive, from flattened text, the row/column/unit relationships that a deterministic parser would have handed you for free — and then reject its output for lacking the proof the parser would have supplied.** Fixing the parser removes inference cost and raises yield simultaneously.

**Where model reasoning is genuinely necessary:** deciding that `Capital Access Platforms` and `Capital Access Platform` are one segment; that `non-GAAP operating expenses` is not the same quantity kind as `operating expenses` (your §6 case); which of 193 facts deserve a chart; and writing the honest explanation when data is missing or contradictory. That is real work, and it is where your AI budget belongs.

---

## 6. E. An evidence-backed chart pipeline

### The inversion

Today: **LLM mints facts → system tries to prove them → proof fails → reject.**

Instead: **parser mints cell-derived candidate facts with provenance built in → LLM labels and selects → verifier checks labels against cell context.**

Under the second design, provenance is **constructive, not reconstructive**. Proof is not something you attempt to recover later; it is a by-product of how the fact was created. A fact cannot exist without its cell reference, because the cell reference is what produced it. This single change is why the architecture starts working, and it is the deep answer to your §8 question.

### Stage 1 — Layout extraction (deterministic)

```
DocumentLayout
  pages[] → blocks[] → tables[]
    table: { id, page, bbox, caption_ref, section_ref,
             header_rows[], header_cols[], ruling_lines }
    cell:  { table_id, row, col, text, bbox, char_span, is_header }
```

Cached by `(file_hash, parser_version)` — you already have this discipline in `StructuredExtractionStore`.

### Stage 2 — Fact minting (deterministic)

Every numeric cell becomes a `TableFact`:

```
TableFact {
  value_cell_ref, raw_text, numeric, sign, scale, currency,
  row_path[],            // header path, outer→inner (handles indent hierarchy)
  col_path[],            // header path (handles spanning headers)
  unit_ref,              // caption or column heading cell
  entity_ref,            // section heading / table caption cell
  basis,                 // from caption/row text: GAAP | non-GAAP | unknown
  footnote_refs[],
  provenance { page, bbox, char_span, table_id, row, col }
}
```

No LLM involved. Every field traces to a cell.

### Stage 3 — Identity normalization (the fix for your §6 contradiction)

Store **both** forms, always:

| | verbatim | canonical |
|---|---|---|
| period | `Three Months Ended September 30, 2026` | `{basis: quarter, start: 2026-07-01, end: 2026-09-30}` |
| unit | `(in millions)` | `{currency: USD, scale_exp: 6}` |
| entity | `Nasdaq, Inc.` | resolved entity node id |

Provenance binds against **verbatim**. Grouping and comparison use **canonical**. Your current pipeline has only one slot and the two requirements fight over it; two slots end the fight permanently.

### Stage 4 — LLM annotation

Send the LLM *compact structured tables*, not raw text, and ask for labels and selection — not values. It returns `{fact_id, canonical_metric, basis, significance}`. It never mints a number, so it can never invent one.

### Stage 5 — Verification

- label plausibility: does the claimed metric correspond to `row_path`? (string/semantic check against real row text)
- period: canonical period parsed from `col_path` deterministically
- unit: present and resolved from `unit_ref`
- entity: resolved from `entity_ref`
- basis: consistent across every fact in a series
- **arithmetic corroboration**: do segment values sum to the stated total? do YoY deltas match a stated change column? This is cheap, deterministic, independent of the LLM, and the strongest signal available in a financial document. You do not currently do it, and it would be a real differentiator.

### Stage 6 — Eligibility and ambiguity

Eligible = cell-derived + canonical period + resolved unit + resolved entity + known basis + arithmetic checks not violated.

Ambiguity should **quarantine with a specific question** ("two columns both parse as Q3 2026 — which applies?"), never silently reject. Your current system's failure mode is 193 silent rejections, which is why it is unactionable for the user *and* for you.

### Stage 7 — Partial failure and recovery

Per-**table**, not per-document. One unparseable table must not zero a document. Your current all-or-nothing coverage marking (one saturated chunk ⇒ whole document incomplete) has the same flaw.

Because Stages 1–3 and 5 are deterministic, retries are free: re-verification must never require paid inference. That property holds today — keep it.

### Worked example: proving `1,481` is Q3 2026 net revenue

The brief's key question. Under the design above, the proof chain is constructive:

```
cell(row 3, col 2) text "1,481"  bbox[412,288,448,299] p.6
  ├─ column_header → cell(row 0, col 2) "Three Months Ended September 30, 2026"
  │                    → PeriodParser → {quarter, 2026-07-01 .. 2026-09-30}
  ├─ row_header    → cell(row 3, col 0) "Total revenues less transaction-based expenses"
  │                    → LLM label "net revenue" → checked against this text
  ├─ table_parent  → table#4
  │    ├─ caption  → "(in millions, except per share amounts)" → {USD, 10^6}
  │    └─ section  → "Nasdaq, Inc. Condensed Consolidated Statements of Income"
  │                    → entity Nasdaq, Inc. consolidated; basis GAAP
  └─ arithmetic    → segment revenues sum to 1,481 ✓
```

Nothing in that chain trusts an LLM's assertion that `1,481` is Q3 2026 net revenue. The period comes from the column the cell is in. The unit comes from the caption of the table the cell is in. The entity comes from the section the table is in. The LLM contributes one thing — the name `net revenue` — and that is checked against the row label it claims to describe. The arithmetic check corroborates independently.

Contrast with today: the model asserts the same fact, the system looks for the literal string `Q3 2026` inside the row's quote text, does not find it, and rejects. The fact was right. The proof method was incapable.

---

## 7. F. When automated verification fails

**Yes, build the review workflow — but after the parser fix, not instead of it.** Built today it would present all 193 observations for manual confirmation, which is worse than Power BI, not better. Built after Tier 2, it handles a genuine minority of hard tables and becomes a feature.

Design rules I would hold firmly:

- **Users correct the *mapping*, never the *value*.** Let them confirm the header row, period columns, unit heading and entity. Never let them edit a number. The moment a user can type a value, your provenance story is gone and you are a spreadsheet with extra steps.
- **Show the detected grid overlaid on the page image.** Correcting a table you can see takes seconds; correcting an abstract field list takes minutes and gets done wrong.
- **Visually distinct charts, permanently.** A badge that survives export, screenshot and API response — not a tooltip. `user-reviewed` must never silently become `verified`.
- **Append-only audit.** `table_mapping_override { file_hash, table_id, parser_version, user_id, created_at, before, after }`. Never mutate; supersede. Invalidate on `parser_version` change, since a new parser may make the correction wrong.
- **Make corrections generalize.** Fingerprint the layout (issuer + table shape + header text) and reuse a confirmed mapping on next quarter's release. Recurring reports are most of real usage. This turns review from a per-document tax into a one-time setup that improves margin over time, and it is a genuine competitive moat — it is the thing Power BI makes the user redo every time.

---

## 8. G. Commercial viability

**Viable — and the correct architecture is the cheaper one.** This is not an accuracy-versus-cost tradeoff, which is the most important commercial point in this review.

Current economics per 17-page document: ~4 minutes, 5 Haiku extraction chunks (one saturated), Sonnet synthesis — output: zero charts. You are paying full inference price for a product failure.

After the inversion: deterministic table parsing costs CPU milliseconds and zero inference. Sending the LLM a compact structured table serialization instead of five saturated raw-text chunks should **reduce** token spend materially, while raising yield from zero. Latency improves for the same reason.

Real risks, honestly: maintaining a table parser across document diversity is ongoing work, and it is the kind that never fully ends. Mitigate by scoping v1 narrowly (ruled and cleanly aligned financial tables), measuring coverage per document class, and routing the remainder to review rather than silently degrading.

On expectations: "hundreds of observations and no charts" is the single worst output your product can emit. It spends the user's money, their time and their trust, and returns nothing they can use. Partial charts with honest, specific caveats are strictly better than zero charts with a generic one — and cheaper to produce than what you are producing now.

---

## 9. H. What I would do next, ranked

### Tier 0 — Falsification first. Hours, no production change, no paid inference.

Do these before committing to anything. Each has a stated prediction; a miss means I am wrong.

1. **Dump the active `structured_extractions.nodes` for `01a16829-dfd8-72bb-b82f-d6abd2cf34d7` and list distinct node types.** Predict: `page` only. *Falsifies Fact 1 if any `table`/`cell` appears.* — SQL only.
2. **Count `evidence_role_bindings` where `role = 'period'` for that document.** Predict: 0. *Falsifies Fact 4 if non-zero.*
3. **For 10 rejected observations, test whether `data['period']` occurs literally in the bound value quote.** Predict: 0/10. *Falsifies Fact 4 if any match.*
4. **Re-run `IncrementalMetricFieldVerifier::verify()` on the stored evidence with `server_evidence_resolution=true`.** The verifier reads stored rows and calls no model, so **this is free**. Predict: counts unchanged, 0 eligible. *This settles the flag question at zero cost* — do it first, it is the cheapest high-information test you have.
5. **Check whether any XLSX or DOCX document in production has ever produced a persisted chart, and whether any PDF has.** Predict: XLSX/DOCX yes, PDF never. *This is the strongest single external confirmation of the whole diagnosis and is one query.* If PDFs have produced charts before, my analysis is incomplete and you should tell me.
6. **Run `pdftotext -bbox-layout` on the Nasdaq PDF and inspect whether column x-ranges cluster cleanly.** Predict: yes. *This is the feasibility test for Tier 2 — run it before funding Tier 2.*

### Tier 1 — Minimal, high impact, low risk. Days.

7. **Split verbatim and canonical storage for period/unit/subject; bind roles on verbatim.** Ends the normalize-vs-bind contradiction (Fact 4). Highest value per line of code in this list.
8. **Bridge `SourceSpanBuilder`'s existing `table_row` typing into `TextRoleProvenanceProducer`:** for a value in a `table_row` span, emit `column_header` and `row_header` edges to the nearest preceding header-row span and the row's label cell. This makes `capabilities.structural` true and bypasses the impossible proximity rule (Fact 3) **using detection you already have** (Fact 7). *Risk: delimiter-split column alignment is weaker than geometry — gate on confidence and keep mismatch-rejects-hard. This is a bridge to Tier 2, not a substitute.*
9. **Stop double-appending rejection reasons** (`MeasurementIntegrity.php:110-118` vs `grounding_rejection`). Diagnostics-only, but you just spent an investigation on inflated counts.
10. **Make coverage and failure per-table, not per-document.**
11. **Remove the route-dependence of `ownership_enforced`** (`MeasurementIntegrity.php:33`).

### Tier 2 — The actual fix. Weeks.

12. **Geometry-based PDF table reconstruction** emitting `table`/`row`/`cell` nodes with bboxes and char spans. Keep smalot for prose; use geometry for table regions. Unify with existing Tesseract word boxes so scanned PDFs are served by the same code. **This is the one piece of genuinely new engineering the diagnosis requires.**
13. **Invert fact minting** (§6): parser proposes cell-derived facts, LLM labels. Reduces tokens, makes proof constructive.
14. **Add arithmetic consistency checks.** Cheap, deterministic, strong, and a real differentiator.
15. **Unify the normal and incremental routes** onto one verification policy. Two routes with different strictness and different reachable verifiers is how `PdfTabularCellVerifier` came to be trusted for a run it could not participate in (Fact 5).

### Tier 3 — Only if Tier 1–2 fall short.

16. Review workflow plus layout-fingerprint reuse (§7).
17. **Full rewrite: not justified, and I want to be unambiguous about this.** Your anchors/relationships/bindings schema already whitelists `row_header`, `column_header` and `table_parent` (`SourceProvenance.php:15,18`). `StructuredProvenanceProducer` already builds them correctly. `SourceProvenanceReader` already consumes them. The entire structural path exists, is tested (`tests/Unit/Services/StructuredExtractionTest.php`), and works — for XLSX and DOCX. **What is missing is a PDF extractor that emits table nodes.** Rewriting the architecture would mean rebuilding the parts that work in order to reach the part that is absent.

---

## 10. Facts / inferences / unknowns

**Facts verified in the deployed code** (citations above): PDF structured extraction emits only `page` nodes. `capabilities.structural` requires `row_header`/`column_header`/`table_parent` edges. Those edges are only built from table nodes. Without them, table metrics must pass a rule requiring no newline between value and header. Role binding is exact-substring within a single quote, and skipped entirely for `' | '` quotes. `PdfTabularCellVerifier`, `RowExpander` and `EvidenceResolver` are normal-route only. `server_evidence_resolution` appears only on normal-route paths. `ownership_enforced` is route-dependent. `SourceSpanBuilder` already types `table_row`.

**Inferences** (consistent with your reported evidence, not directly observed): that your run's graph is pure `TextRoleProvenanceProducer` output (your 171/171 `contained_by` matches its sole edge type); that the 190 `period_source_mismatch` occurrences are caused by zero period bindings (your role table shows no period role, and the counts coincide); that your earlier positive development evaluations exercised the normal route; that the Nasdaq tables are well-aligned enough for geometric reconstruction.

**Unknowns requiring your verification**: whether the Nasdaq PDF has ruling lines or is whitespace-aligned only (changes Tier 2 difficulty, settled by test 6); whether the saturated chunk dropped whole tables (independent of eligibility, but affects completeness claims); whether PDF span quotes actually contain `' | '` (would mean role binding is skipped wholesale, making even Fact 4 generous); actual per-document token cost (no billing access); whether XLSX/DOCX documents do produce charts in production (test 5 — the highest-information unknown on this list).

---

## 11. Direct answer

**Can your approach realistically work?** Yes. Extract-then-verify is the right architecture for trustworthy document intelligence, and your storage and revision-pinning design is better than most. Keep it.

**What is preventing it from working today?** PDFs are parsed to flat text with no table structure, so the provenance graph cannot represent the row/column/caption relationships your verifier demands proof of. The verifier then correctly refuses to certify claims it cannot prove. Every observation in a PDF financial table fails, deterministically, for this one reason. The feature flag is irrelevant to it. A better model cannot affect it.

**What would I build differently?** One thing, in two parts. Reconstruct PDF tables geometrically so cells, rows, columns and captions exist as first-class nodes — you already have the schema, the producer and the consumer for this, and they already work for XLSX and DOCX. Then invert the flow so the parser mints facts with provenance attached and the LLM only labels them. Proof then becomes a by-product of construction rather than an archaeology problem, costs drop because you stop paying inference to recover discarded structure, and the strict standards you already wrote start passing honestly instead of failing uniformly.

You have not over-engineered this, and you have not wasted the effort. You built a rigorous verifier and an honest gate, then fed them a representation that cannot carry the evidence. Fix the representation.
