# DocIntel — architectural challenge and root-cause investigation

- **Date:** 22 October 2026. **Repository:** `CA_BACKEND` @ `cbb8586`, branch `feat/docintel-v10-live-eval-prep`; frontend `CA` consulted read-only.
- **Scope:** independent investigation. **No application source, prompt, schema, migration or data was modified.** No paid inference. No deploy, push, merge or Intelligence V2 activation.
- **Evidence base:** the repository; the restricted local V10 live diagnostic and provider response (`storage/framework/testing/docintel-v10-evaluation/`, read in place, never copied); and **nine new offline experiments** driving the unmodified production classes. Experiment scripts live in the session scratchpad (`/tmp/claude-1000/-home-collins-boys/47a61c78-594b-4d6a-9831-e3826da703d4/scratchpad/`), not in the repository. No credential was read or printed.
- **Prior audits** (`docintel-independent-v9-audit.md`, `docintel-v10-offline-recovery.md`, `docintel-v10-live-evaluation-preflight.md`, `docintel-v10-northstar-live-result.md`) were treated as hypotheses. One of their central conclusions is **falsified** below (§2.6); several others are **independently confirmed**. Nothing was overwritten.

---

## Part 1 — Executive verdict

**Is the fundamental approach sound?** The *layering* is sound: extracted text → addressable source offsets → model claims → independent verification → evidence rows → deterministic analytics, with the trust boundary enforced before analytics. Keep that. **The division of labour inside it is wrong, and the evidence representation underneath it cannot represent the documents the product targets.** Those two things, not the model, produced the zero.

**Primary cause of the live V10 failure.** One role. The model was required to name the span proving the *governing entity* of each value, and it cited the value row instead — on **13 of 20** observations, and on **4 of 4** financial KPIs in the earlier V9 run. That is 17 of 17 attempts wrong across two independent runs. I replaced that single field with a server-derived governing-entity span, changed nothing else, and ran the **unchanged** live response through the **unchanged** production predicates: **0/20 eligible became 11/20 eligible** (Experiment 2). Adding deterministic enumeration of the already-cited table rows and a deterministic split of the printed column label into period + basis took it to **22 grounder-eligible observations and all four complete Q1–Q3 financial series** — more than the hand-written offline fixture achieved — with **no prompt change, no model re-generation, and no verification predicate weakened** (Experiments 3–4).

**Which kind of problem is this?** A combination, in this order of blame:

1. **Prompt-contract problem (dominant, fixable now).** The model is asked to solve four problems in one generation — find the fact, resolve the governing header, resolve the column relationship, and enumerate every period of a series. Three of those four are deterministic lookups the server can do better. The server already contains the code to do them (`pageHeaderSubject`, `governs`, `PdfTabularCellVerifier`); it just uses that code to *grade* the model instead of to *answer*.
2. **Verification-design problem (second, partly a correctness bug).** Three refusal classes are modelling errors, not proofs: a unitless count can **never** be analytically eligible however perfectly cited (Experiment 6); the proposal table is refused because concept and subject roles are inverted for header-governed tables (Experiment 5); and no prose monetary value is verifiable in **any** representation, including the one the V10 prompt mandates (Experiment 7).
3. **Evidence-representation problem (the ceiling).** Flat line-spans cannot represent a table. Two of Northstar's five tables are unverifiable under *every possible citation* (Experiment 8), the risk register is shredded across span boundaries, and whether a caption can prove a unit depends on its **capitalisation** (Experiment 9). This is why every fix so far has been a patch.
4. **LLM limitation (smallest).** Real but narrow: the model omitted Q1 everywhere and put basis into the period field. Both are recoverable server-side. Its *facts* were 20/20 correct and its *column alignments* were 20/20 correct.

**What I would change first.** Invert the citation contract: **the model proposes facts; the server resolves and proves their evidence.** Concretely, ship Stage 1 of §8 — server-side subject resolution, deterministic row enumeration, and deterministic period/basis label splitting — behind a flag, on the frozen live response plus the adversarial corpus. It is days of work, needs no new dependency, no new model call, and I have already measured its effect (0 → 22) and its false-acceptance rate on an adversarial set (0 of 9, §4.5).

**What I would stop building.** Stop adding refusal codes. There are **~30** distinct ones across `NormalKpiGrounder`, `PdfTabularCellVerifier` and `MeasurementIntegrity`, and the newest ones are visibly document-shaped: a `['quarterly','rate','received','cases']` stop-word list and a `/\btotal\s+proposed\b/` special case inside the cell verifier. Stop requiring the model to emit one observation per cell. Stop extending whitespace-delimiter table parsing — retire it in favour of a real cell model. And **stop treating "622 tests pass" and "rejection accuracy" as progress**: both were green while the product produced nothing.

**Two defects found in shipped code that need fixing regardless of architecture:**

- **The wrong-period false acceptance was only closed for PDFs.** `NormalKpiGrounder` calls `PdfTabularCellVerifier` only when `$document->type === 'PDF'`. For a DOCX — the format whose extractor already produces *real* table cells — a claim binding **Q1 2026 to the Q3 value** is accepted with four verified roles. Reproduced (Experiment 1b).
- **`pageHeaderSubject` lets a running page banner prove entity ownership.** In a group pack, the parent-company banner proves the parent owns a named subsidiary's table. Two mutually exclusive subject claims on the same cell both verify. Reproduced (Experiment 1c). This is currently masked only because the model never cites the banner.

---

## Part 2 — Causal analysis: how correct source values became unusable results

### 2.1 Inputs independently re-derived

`DocumentTextExtractor::extractPdfText()` on the fixture reproduces extracted-text SHA-256 `a4722cfdb3795de38553953d1c12521053a5368232bf3a0cbcd4a952712fa8da` and `SourceSpanBuilder` yields exactly **126 spans** — byte-identical to the live run. Every claim below is anchored to that span set. **Confirmed.**

### 2.2 The model's work was better than the scoreboard says

From the retained provider response: all 20 observations cite the exact value row; `unit_span_id` points at the caption `E027` (`"2. Financial performance\nFinancial results by quarter (KES million)"`); `period_span_id` points at the header row `E028` (`"Measure\tQ1 2026\tQ2 2026\tQ3 2026"`). Every one of its 20 emitted values was bound to the correct cell of the correct column, confirmed independently by the production cell verifier in Experiment 1, including the `≤ 90` ceiling with its operator preserved. It emitted no chart JSON, as instructed. **Confirmed.** The model is not the weak link in value or column reasoning.

### 2.3 The single decisive field

`subject_span_id` is the value row on all 20. The claimed scope is `"Northstar Distribution Ltd"`, which the value row does not print, so `containsWords()` fails and the role rejects before any relationship test. The proving span exists and is trivially locatable: **every page begins with a running banner** (`E025` on page 3: `"NORTHSTAR DISTRIBUTION LTD  |  FICTITIOUS BOARD PACK  |  Q3 2026\tPage 3"`), and the grounder *already* has a rule (`pageHeaderSubject`) that accepts the page's first span. **Confirmed defect in the contract, not in the model's facts.**

The V10 prompt's rule 19 asks the model to "cite … the genuine governing entity or population header when scope is absent from the numerical row," and warns that proximity is not proof. It made no difference: V9 made the identical error on 4/4 financial KPIs, V10 on 13/20. **Two runs, 17/17 wrong on this role.** This is the empirical basis for moving the role server-side rather than re-wording the instruction a third time.

### 2.4 Blockers hidden behind the first rejection

The live diagnostic records only the first decisive rejection per row. Running every check to completion (Experiment 1) shows five rows (indexes 8, 9, 11, 12, 14 — the bare-quarter operational actuals) **also** fail period resolution. Fixing the subject alone would therefore not have recovered them; the audits' first-rejection table understates the work. **Confirmed; new.**

### 2.5 The other two refusal classes are not proofs

- **`period_ambiguous` ×3.** The model set `identity.period = "Q3 target"`, mixing period and basis. The source column label *is literally* `"Q3 target"`, and the model separately set `value_basis = target`. Splitting the printed label deterministically yields period `Q3` + basis `target`, and all three verify (Experiment 3). **A parsing responsibility was delegated to the model and then punished.**
- **`concept_row_mismatch` ×4.** `PdfTabularCellVerifier` requires the concept's words to appear in **cell 0, the row label**. In the proposal table the concept (*proposed funding*) is the **column header** and the row label is the **subject** (*fleet reliability intervention*). The verifier has the two roles inverted for this table shape; the model's output was semantically correct. Re-orienting them makes all four eligible with full cell proof (Experiment 5). **Confirmed verifier defect.**

### 2.6 Where the V9 audit was wrong

`docintel-independent-v9-audit.md` concluded that the one-KPI-per-metric output contract was "necessary and nearly sufficient," and that fixing it would yield four complete series. V10 fixed exactly that: the model emitted 20 standalone observations instead of 7. **The result was still zero**, because the citation error it had classified as a secondary gate affecting 4 rows recurred on 13 of 20 and became the dominant blocker. The prediction is **falsified by the live run**. The lesson is methodological: the audit inferred what *would* ground from hand-written citations, which is precisely the inference the live run exists to test.

### 2.7 Two structural limits no model behaviour can clear

- **Caption-dated breakdown tables.** Northstar's "Q3 revenue by region" and "Q3 operating costs by function" tables put categories in the column headers and the period only in the caption. I tried **both** possible period citations for three cells: citing the header row gives `period_column_ambiguous`, citing the caption gives `column_header_unverified` (Experiment 8). **No citation exists that works.** Six-plus gold facts and the only two composition charts the document supports are permanently unreachable.
- **Unitless counts.** `MeasurementIntegrity` requires `unitVerification ∈ {verified, dimensionless}` for `analyticalEligible`, and `dimensionless` is granted only to `metric_type ∈ {ratio, index}`. A count has no unit to cite, so complaints, shipments and headcount are **permanently ineligible** with perfect citations (Experiment 6). Three of Northstar's four operational series can never chart.

### 2.8 What a user would actually have seen

V10 discards model chart JSON, so: 20 numerically correct KPI cards, each labelled by `measurementPresentation.ts` as *"Unit unverified / Source unverified / Not eligible for analytical chart"*, plus the model's 5 narrative insights (persisted by `GenerateInsightsJob`), zero charts, zero comparisons. `importantFindings` is 0 because `ImportantFindingsBuilder` iterates `DocumentEvidence` rows and there were none. The product does not go blank — **it presents 20 correct figures as untrustworthy.** For a professional reviewer that is worse than silence: the doubt is misattributed to the document.

(`takeaways: 0` in the diagnostic is confounded — the harness did not run the summary job — so I draw no conclusion from it.)

### 2.9 Reasonable hypotheses and unknowns

- *Hypothesis:* the flat `[E###]`-labelled rendering (`EvidenceSpanSet::render()`) actively degrades citation quality by replacing a visually aligned page with 126 bracketed fragments and an ID-bookkeeping task. Consistent with the evidence; **not tested** (would need a paid A/B).
- *Unknown:* whether a larger model would pick the banner. Untested by design — and the fix should not depend on it.
- *Unknown:* real-corpus acceptance rates. One document.

---

## Part 3 — Architecture comparison

Criteria: *can it actually clear the measured blockers*, false-acceptance risk, new dependencies, PHP/Laravel fit, inference cost, and whether it reduces or relocates complexity.

| | A — repair V10 | B — server-side evidence resolution | C — structure-first | D — multi-stage AI | E — hybrid per-type | F — **B then C** (recommended) |
|---|---|---|---|---|---|---|
| Clears the 13 subject rejections | Only if the model complies; 17/17 says it won't | **Yes, measured: 0→11** | Yes | Probably | Yes | **Yes** |
| Clears Q1 omission / series | No (model must emit) | **Yes, measured: 4/4 series** | Yes | Partly | Yes | **Yes** |
| Clears `concept_row_mismatch` | No | **Yes (role re-orientation)** | Yes | No | Yes | **Yes** |
| Clears caption-dated breakdown tables | **No — structural** | No | **Yes** | No | Yes | **Yes, at stage 2** |
| Clears unitless counts | No | No (integrity-layer fix, orthogonal) | No | No | No | Fix separately |
| New runtime dependency | none | **none** | poppler geometry (**already installed**) or a Python sidecar | none | sidecar + per-type code | **none, then poppler** |
| Inference cost | same | **lower** (model emits fewer, simpler claims) | lower | **2–4× per document** | varies | **lower** |
| False-acceptance risk | unchanged | server rules must be adversarially tested — measured 0/9 | depends on region detection | compounding across stages | highest surface | controlled, staged |
| Complexity | **grows** — more codes, more patches | **shrinks** the model contract | moves complexity into a parser, with a real data model | grows a lot | grows most | net reduction |
| PHP/Laravel fit | native | native | poppler native; Docling needs a sidecar | native | sidecar | native first |

Notes on the ones I am rejecting:

- **A alone is not viable.** Its only lever on the dominant blocker is asking the model more nicely. That has failed twice, and it cannot touch §2.7 at all.
- **D (multi-stage AI) buys the least per unit of cost.** Every blocker I measured is a *deterministic lookup*; paying for extra generations to perform lookups is strictly worse than performing them. A second call also doubles latency (the single V10 call already took 25.9 s). The one place a second bounded call may earn its keep is OCR'd/scanned documents, where no geometry is trustworthy.
- **E (per-type pipelines) is premature and is partly the status quo's disease.** The codebase already has `XlsxCellWindowBuilder`, `DocxWindowBuilder`, `SourceDocxWindowReader`, `PdfTabularCellVerifier` — four type-specific stacks that **do not share a cell model**, which is exactly why the DOCX false acceptance exists. The fix is one shared cell model, not more branches.
- **C alone is not enough and not free.** Experiment 9b shows geometry recovers cells perfectly *given correct table regions*, and that naive region detection over-splits wide wrapped-cell tables. It is the right ceiling-raiser, but it should land after B, because B is what converts the existing model output into product.

---

## Part 4 — Experiments and evidence

All nine ran offline against the real fixture and the frozen live response, using the **unmodified** production classes (`NormalKpiGrounder` private predicates via reflection, `PdfTabularCellVerifier`, `MeasurementIntegrity`, `MeasurementParser`, `PeriodParser`, `ValueBasisEvidence`, `SourceSpanBuilder`, `EvidenceSpanSet`). The driver mirrors `NormalKpiGrounder::record()`'s control flow and omits only its two database writes; nothing was persisted. **Generalisation limit shared by all of them: one real document plus five synthetic adversarial controls.** No experiment below is a proposal — each was executed and its printed output is the result quoted.

### Experiment 1 — replay the unchanged live response, run every check to completion

*Hypothesis:* the recorded first-rejections are complete. **Result:** reproduced exactly — **13** `subject_source_mismatch`, **3** `period_ambiguous`, **4** `concept_row_mismatch`, 0/20 eligible. For all 8 financial rows, *every other role passes*, including structural cell proof (`cell="51.0" col="Q3 2026"`). **Hypothesis refuted for 5 rows:** indexes 8, 9, 11, 12, 14 also fail period resolution. *Alternative explanation considered:* driver infidelity — excluded, because the first-rejection vector matches the live diagnostic row for row.

### Experiment 1b — is the wrong-period false acceptance closed outside PDF?

*Hypothesis:* the V10 safety fix covers all table-bearing formats. **Setup:** one table, pipe-serialised as `extractDocxText` produces, claims `Q1 2026 = 51.0` and `Q2 2026 = 51.0` where 51.0 is the Q3 cell.

| document type | `Q3 2026 = 51.0` | `Q1 2026 = 51.0` | `Q2 2026 = 51.0` |
|---|---|---|---|
| PDF | ELIGIBLE (correct) | `value_column_mismatch` | `value_column_mismatch` |
| **DOCX** | ELIGIBLE | **ELIGIBLE — false acceptance** | **ELIGIBLE — false acceptance** |

**Hypothesis refuted.** `NormalKpiGrounder.php:38` gates the cell verifier on `type === 'PDF'`; for DOCX `$tableProof` is `null`, so the row passes on `containsValue()` (one occurrence of `51.0` in the row) plus a header that merely *contains* the words `Q1 2026`. *This is the exact defect class the brief says must not be reintroduced — it was never closed outside PDF.* It is doubly avoidable: `extractDocxStructured()` already emits real `cell` nodes with `grid_column`, `grid_span` and `vertical_merge`, and the grounder ignores them.

### Experiment 1c — does a running page banner prove entity ownership?

*Setup:* banner `ACME HOLDINGS LTD | GROUP PACK | Q3 2026`; the only table is captioned `ZENITH FREIGHT LTD STANDALONE REVENUE`. Subject cited exactly as rule 19 asks.

| subject citation | claimed scope | verdict |
|---|---|---|
| page banner (parent) | Acme Holdings Ltd | **ELIGIBLE — false acceptance** |
| table caption (true owner) | Zenith Freight Ltd | ELIGIBLE (correct) |

Both mutually exclusive owners verify on the same cell. **Confirmed defect in shipped code** (`pageHeaderSubject`, `NormalKpiGrounder.php:~250`). Any server-side subject resolution must be *narrower* than this rule, not merely automatic — see Experiment 4.

### Experiment 2 — server-derived subject, nothing else changed

*Hypothesis:* the subject role is the dominant blocker and is server-resolvable. **Setup:** identical candidates, identical predicates; `subject_span_id` replaced by the first span on the value row's page. **Result: 0/20 → 11/20 eligible** — all 8 financial observations plus the three Q3 operational actuals. The remaining 9 are the 3 `"Q3 target"` periods, the 2 bare-`Q2` actuals, and the 4 proposal rows. **Hypothesis supported.** *Limit:* this used the permissive banner rule; Experiment 4 replaces it.

### Experiment 3 — recover Q1 from the row the model already cited

*Hypothesis:* given one cited (value row, header row) pair, the whole row is deterministically verifiable, so per-cell model emission is unnecessary. **Setup:** for each of the **7 distinct pairs** the model cited, split both rows with the production `splitCells()`, pair cell *i* with header cell *i*, split the printed column label into period + basis, and verify each through the unchanged predicates.

**Result: 24 cells enumerated, 18 grounded.**

| row | cells grounded | series obtained |
|---|---|---|
| Revenue `E029` | 3/3 | **Q1 42.0 · Q2 46.0 · Q3 51.0** |
| Operating costs `E030` | 3/3 | **Q1 33.0 · Q2 36.0 · Q3 41.0** |
| Operating profit `E031` | 3/3 | **Q1 9.0 · Q2 10.0 · Q3 10.0** |
| Operating margin `E032` | 3/3 | **Q1 21.4% · Q2 21.7% · Q3 19.6%** |
| On-time deliveries `E048` | 2/4 | Q3 actual 87% + Q3 target 95% |
| Customer complaints `E049` | 2/4 | Q3 actual 133 + Q3 ceiling `≤ 90` |
| Shipments completed `E047` | 2/4 | Q3 actual 14,900 + Q3 target 14,500 |

**The model never emitted a single Q1 figure; code recovered all four.** All four complete three-quarter series — the exact output the hand-cited offline fixture produced — come from the unchanged live response. The 6 refusals are the bare-`Q1`/`Q2` operational cells, correctly refused for an unproven year. All three `"Q3 target"` labels resolve. *Alternative explanation considered:* the split is Northstar-tuned — partly true, and that is the point of Stage 2: the splitter belongs in a real cell model, not in a regex.

### Experiment 4 — adversarial controls on server-side subject resolution

*Hypothesis:* automatic subject derivation introduces false acceptances. **Rule under test (deliberately narrower than the shipped one):** the governing entity is the **nearest preceding entity-naming caption on the page**, falling back to the page banner; a guard reports when more than one distinct entity governs the page.

| case | claim | verdict | truth |
|---|---|---|---|
| D1 two companies, one page | scope = Zenith (true owner) | ELIGIBLE | correct |
| D1 | scope = Acme (banner) | `subject_source_mismatch` | correctly refused |
| D2 banner parent, caption subsidiary | scope = Acme | `subject_source_mismatch` | correctly refused |
| D2 | scope = Zenith | ELIGIBLE | correct |
| D3 repeated value + unrelated adjacent table | scope = Acme | ELIGIBLE | correct |
| D4 column says Q3 **2025**, banner says Q3 2026 | period = Q3 2026 | `period_column_ambiguous` | correctly refused |
| D4 | period = Q3 2025 | ELIGIBLE | correct |
| D5 value taken from the wrong column | period = Q3 2026, value 80.0 | `value_column_mismatch` | correctly refused |
| D5 | period = Q2 2026, value 80.0 | ELIGIBLE | correct |

**5 true accepts, 4 true rejects, 0 false acceptances, 0 false rejections.** The nearest-caption rule **fixes** the Experiment 1c defect: on D2 it refuses the parent claim the shipped rule accepts. *Limit:* five synthetic cases, Latin-script `Ltd|PLC|Inc` suffix detection, no OCR noise, no cross-page tables.

### Experiment 5 — is `concept_row_mismatch` a proof or a role inversion?

| orientation | result |
|---|---|
| as emitted: concept = *proposed funding*, subject = row label | `concept_row_mismatch` ×4 |
| concept = row label, subject = *service-recovery budget* | `subject_source_mismatch` ×4 |
| **concept = row label, subject = derived entity** | **ELIGIBLE ×4** |

All four proposal amounts verify with full cell proof and a verified `KES million` unit (via the reassembled wrapped header). **The refusal encoded an assumption that the concept lives in the row label; in a header-governed table it lives in the column.** *Confirmed defect.* Note it was already being patched around: `PdfTabularCellVerifier` contains a `/\btotal\s+proposed\b/` caption special case for exactly this table.

### Experiment 6 — can a unitless count ever be analytically eligible?

Driving `MeasurementIntegrity::assess()` directly with all four roles proven and a matching source revision:

| observation | eligible | unit state |
|---|---|---|
| revenue 51.0 KES million | **yes** | verified |
| operating margin 19.6% | **yes** | verified |
| customer complaints 133, no printed unit | **no** | `unit_unverified` |
| shipments completed 14,900 | **no** | `unit_unverified` |
| employees 123 | **no** | `unit_unverified` |
| complaints 133 with model-supplied unit *cases* | **no** | `unit_unverified` |

**No.** `dimensionless` is reachable only for `metric_type ∈ {ratio, index}`. **This is evidence validity confused with analytical eligibility**: a count is a legitimate dimensionless quantity, and the right test is "is the quantity kind proven," not "was a unit string cited." Honest accounting of Experiment 3: of its 18 grounded cells, **18 are analytically eligible** only after re-counting — the 4 count cells ground but stay ineligible, while the 4 proposal rows from Experiment 5 join. Net: **22 grounder-eligible, 18 analytically eligible, 12 of them forming 4 complete series.**

Also worth flagging: Northstar's page-7 glossary *defines* the missing unit — *"Customer complaints — Number of cases received in quarter."* `governs()` requires the same page, so it is unusable.

### Experiment 7 — the prose path contradicts its own prompt

`containsValue()` strips `[,%\s]` and requires `is_numeric`:

| value as written | matched in the executive paragraph `E014`? |
|---|---|
| `51.0` | yes |
| `19.6%` | yes |
| `133` | yes |
| `51.0m` | **no** |
| `KES 51.0m` | **no** |
| `$1.2bn` | **no** |
| `2.4m` | **no** |

End to end, for a prose-sourced monetary fact: `"KES 51.0m"` → `source_mismatch`; `"51.0m"` → `source_mismatch`; bare `"51.0"` → `unit_source_mismatch`, because the paragraph says *KES 51.0m*, never *KES million*. **No prose monetary fact in this document is verifiable in any representation** — while V10 rule 18 instructs *"Keep the original unit and scale; never convert a written value,"* i.e. mandates the form the verifier rejects. A separate systematic class: `ValueBasisEvidence` rejected a prose count as `value_basis_ambiguous` because the paragraph mentions *target*, *proposed* and *forecast* somewhere in its 900 characters. For contracts and narrative reports this will fire constantly. **Confirmed; new.**

### Experiment 8 — are the breakdown tables verifiable at all?

| cell | period cited as header row | period cited as caption |
|---|---|---|
| North region revenue 21.0 | `period_column_ambiguous` | `column_header_unverified` |
| Logistics cost 17.0 | `period_column_ambiguous` | `column_header_unverified` |
| Q3 total revenue 51.0 | `period_column_ambiguous` | `column_header_unverified` |

**No citation exists that works.** Independently confirms the V9 audit's finding and establishes it as a hard structural ceiling: a verifier that knows only "header row above, value row below" cannot represent a table whose columns are categories and whose period is in the caption.

### Experiment 9 — flat spans versus a real cell grid, same frozen PDF

**9a — what the span model does to a table.** Page 5's risk register becomes: `E062 "R-01\tDelivery reliability"`, `E063 "deterioration"`, `E064 "High\tQ3 on-time rate 87%; 95%"`, `E065 "target\nFleet maintenance and\ndispatch redesign"` — wrapped cells from different columns of one logical row scattered across four spans of three different `type`s. The proposal header splits mid-parenthesis (`E072 "Initiative\tProposed funding (KES"` / `E073 "million)\nAccountable owner"`), which is why `PdfTabularCellVerifier::columns()` carries a wrapped-heading reassembly hack. **The patch exists to compensate for the representation.**

**9b — geometry recovers the cells.** `pdftotext -bbox-layout` (poppler — already a dependency; `preflightPdf()` shells out to `pdfinfo`) gives word-level x/y boxes. Clustering rows by y and columns by x-interval, **given correct table regions**, recovers every table exactly:

```
page 4, y 100–230                     page 5, y 350–480
Operational indicator |Q1    |Q2    |Q3    |Q3 target     Initiative                      |Proposed funding (KES|Accountable owner
Shipments completed   |12,500|13,700|14,900|14,500        ·                               |million)             |·
On-time deliveries    |92%   |90%   |87%   |95%           Fleet reliability intervention  |1.20                 |Head of Operations
Customer complaints   |84    |96    |133   |≤ 90          Warehouse staffing stabilization|0.75                 |People & Operations
Employees (quarter-end)|112  |118   |123   |Not set       Complaint triage tooling        |0.45                 |Customer Experience
                                                           Total proposed                  |2.40                 |Chief Operating Officer
```

The risk register recovers as a clean 5-column grid with wrapped cells landing in the right columns. **9c — region detection is the open problem.** A deterministic auto-detector (maximal runs of rows sharing a consistent multi-column banding) found **16 regions across 7 pages**; **every recovered cell value was correct**, including the page-7 glossary (8×2) and the page-7 reconciliation table, but it **over-split** wide wrapped-cell tables (the risk register into 2, the proposal header away from its body, page 2's issues table into 2). Over-splitting is a *safe* failure — cells stay correct — but it loses header-to-body association, which is the thing you need. **Conclusion: table-structure recognition is cheap and deterministic; table-region/layout detection is the part that justifies a dependency** (Docling's layout model, or an improved continuation-merge rule validated on a real corpus).

---

## Part 5 — External research

Internet access was available. Where I could not open a primary source I say so. Nothing from DocIntel was sent to any external service; no source PDF, span text, diagnostic or credential left the machine.

### Documented capability (verified against primary sources)

- **Docling** — [technical report, arXiv:2501.17887](https://arxiv.org/pdf/2501.17887); [supported formats](https://docling-project.github.io/docling/usage/supported_formats/); [`DoclingDocument` concepts](https://docling-project.github.io/docling/concepts/docling_document/). Its PDF pipeline pairs an RT-DETR layout model with **TableFormer** for table-structure recognition, which predicts logical rows/columns and labels cells as column header, row header or body, and is documented to handle partial/missing borders, empty cells, spans and hierarchical headings. MIT-licensed, runs locally on commodity hardware. Formats include PDF, DOCX, XLSX, PPTX, HTML, images and XBRL XML.
  **I verified the cell data model in source** — [`docling_core/types/doc/items/table/table_data.py`](https://raw.githubusercontent.com/docling-project/docling-core/main/docling_core/types/doc/items/table/table_data.py):
  ```python
  class TableCell:  bbox, row_span=1, col_span=1,
      start_row_offset_idx, end_row_offset_idx,
      start_col_offset_idx, end_col_offset_idx,
      text, column_header=False, row_header=False, row_section=False
  class TableData:  table_cells, num_rows, num_cols, orientation  # + computed grid
  ```
  **This is the intermediate representation DocIntel is missing** — and note DocIntel's own `extractDocxStructured()` already produces its DOCX equivalent (`grid_column`, `grid_span`, `vertical_merge`, `explicit_header`) and `extractPdfStructured()` explicitly declares `'tables' => 'unavailable', 'geometry' => 'unavailable'`. The gap is PDF-only.
- **Unstructured** — [table extraction guide](https://unstructured.readthedocs.io/en/latest/best_practices/table_extraction_pdf.html); [hi-res partitioner reference](https://docs.unstructured.io/api-reference/workflow/nodes/transform/partitioner-high-res). Requires `strategy='hi_res'` + `infer_table_structure=True`, and returns tables as **`text_as_html`** on element metadata. Table inference is **off by default** for PDF/image/xls inputs via auto-partition and the API; the `fast` strategy does no OCR or layout detection. *Assessment: HTML-string output is a step down from a typed cell model — you would be re-parsing HTML to get offsets and spans. Not the right fit for a system whose whole point is exact provenance.*
- **Marker** — [`marker-pdf` on PyPI](https://pypi.org/project/marker-pdf/); [releases](https://github.com/datalab-to/marker/releases). Code Apache-2.0, but **model weights under a modified OpenRAIL-M licence restricting larger commercial use**; Marker 2.0 reports 76.0% on olmOCR-bench in balanced mode. **Licensing is a real commercial blocker for DocIntel** and third-party sources disagree on older terms — verify with Datalab before any evaluation. (A third-party review also cites an open Docling issue #3698 on irregular merged rows/columns; I could not open that issue, so treat it as unverified secondary reporting.)

### Transferable lessons on grounding and attribution

- Industry and research practice converges on **"the model proposes, the system verifies"**: decompose into atomic claims, then independently check each against its span rather than trusting the model's own citation. A practitioner analysis estimates frontier-model inline citations are wrong for roughly **5–15%** of claims because models pick a *plausibly related* span rather than the supporting one ([ZeroEntropy](https://zeroentropy.dev/concepts/citation-extraction/)), and calls the pattern laundering hallucinations through the citation step ([TianPan](https://tianpan.co/blog/2026/04/23/rag-citations-post-hoc-rationalization)). [FullCite](https://arxiv.org/html/2606.07130) reports that **post-hoc span alignment** materially improves evidence-identification F1 over model-generated citations. **DocIntel's 17/17 failure rate on the hardest role is the predicted outcome, not an anomaly** — the general finding is 5–15% on ordinary spans; DocIntel demands a *governing-header* citation, which is the hardest class.
- The same sources recommend **deterministic checks first for numbers, comparators and span integrity**, with model/NLI judgement kept separate and measured against human labels, because entailment models are weakest exactly on numerical and partial-support claims. **DocIntel already does the deterministic part well; it is the allocation of roles that is wrong.**
- Finance-specific evaluation work exists but I could only read abstracts/related work, so treat the figures as requiring local validation: [FinGround](https://arxiv.org/html/2604.23588v1) reports table-cell attribution without structure-aware chunking producing ~23% dangling citations and detectors missing 43% of computational errors; [FAITH](https://arxiv.org/pdf/2508.05201), [FinLFQA](https://arxiv.org/pdf/2510.06426), [RelationalFactQA](https://arxiv.org/pdf/2505.21409) and [ViTaB-A](https://arxiv.org/pdf/2602.15769) are candidate evaluation designs for a DocIntel benchmark; the ViTaB-A related work independently notes that prompted citation generation still yields unverifiable references. [The Extractive–Abstractive Spectrum](https://arxiv.org/pdf/2411.17375) makes the point most relevant to §6: some true claims are entailed only by a *union* of sources, so a single-span proof standard will always false-reject a real class of valid facts — which is precisely DocIntel's cross-page glossary case.

### Compatibility assessment

- **Zero-dependency path (Stages 1–2):** poppler is already installed and already shelled out to (`pdfinfo` in `preflightPdf()`); Tesseract is already used by the optional image stage. `pdftotext -bbox-layout` therefore adds **no new operational dependency**, and Experiment 9b shows it is sufficient for structure recovery.
- **Docling path (Stage 3, only if a real corpus shows region detection needs it):** Python, so a containerised sidecar with a narrow HTTP contract (bytes in, typed cells + page/bbox/char-offset out). MIT licensing is clean. Costs: a second service to deploy and version, model weights (~hundreds of MB), CPU-seconds per page, and a parser-version dimension added to the existing `source_revision` invalidation logic — `StructuredDocument` already carries `parserVersion`, so the concept exists.
- **Marker: do not adopt** without legal review of the weights licence.
- **Hosted document-AI APIs** (Google Document AI, Azure Document Intelligence) have mature typed cell models with `rowIndex`/`columnIndex`/`rowSpan`/`columnSpan`, but send customer documents to a third party and add per-page cost. Not evaluated further; flagged as the fallback if self-hosting is rejected.

---

## Part 6 — Recommended architecture

**The smallest architecture I believe is reliable.** One sentence: **keep every layer you have, move three jobs out of the model into deterministic code, and give PDFs the cell model that DOCX and XLSX already have.**

```
                                 ┌─ DOCX  → native cells (exists today, UNUSED by grounding)
  document ─ extraction ─────────┼─ XLSX  → native cells (exists today, USED)
                                 ├─ PDF   → ① CellGrid from poppler geometry  (NEW, no new dependency)
                                 └─ scan  → OCR → ① CellGrid, flagged low-confidence

  CellGrid + prose spans
      │
      ├─► ② model prompt: facts only — concept, value as printed, basis, and ONE anchor
      │       (a cell id, or a prose span id). No subject span. No unit span. No period span.
      │       No per-period enumeration. No chart JSON.
      │
      ├─► ③ EvidenceResolver (NEW, deterministic): from the anchor alone, resolve
      │       row label · column label · caption · governing entity · section unit ·
      │       footnote/glossary definition — each with exact offsets — and REFUSE on ambiguity
      │
      ├─► ④ RowExpander (NEW, deterministic): enumerate every sibling cell of an anchored row
      │       and resolve each independently — the series comes from the document, not the model
      │
      └─► ⑤ verification (EXISTING predicates, unchanged) ─► DocumentEvidence(kind=metric)
                └─► MetricCollector ─► MeasurementIntegrity ─► charts · comparisons · API
                        (all EXISTING and UNCHANGED)
```

### Component responsibilities and the evidence boundary

| component | owns | must never |
|---|---|---|
| ① `CellGrid` | the typed cell model: `page, row, col, row_span, col_span, is_column_header, is_row_header, text, start_offset, end_offset, bbox` — mirroring Docling's `TableCell` so a later swap is a backend change, not a contract change | normalise, correct or re-order source text |
| ② model | *semantic discovery only*: what the document is claiming, in its own words, with **one** anchor | be trusted for any structural relationship, or asked to enumerate a series |
| ③ `EvidenceResolver` | every field-role span, with offsets, derived from structure | guess; **ambiguity must refuse, with the ambiguity named** |
| ④ `RowExpander` | multi-period coverage | expand across a row whose header is not fully resolved |
| ⑤ verification | the proof standard; the existing predicates survive intact | be relaxed to raise acceptance |

**The trust boundary does not move.** Nothing becomes evidence without exact offsets re-read from `extracted_text`, a structural cell proof, and a matching `source_revision`. The change is *who proposes the proof*, not *whether it is required*.

### Three proof tiers — this is the substantive change to the verification model

Today there is one standard, so a correct fact with an imperfect citation disappears. Replace it with three tiers, each with a distinct product meaning:

1. **Source-linked.** The value occurs at exact offsets in the document. *Use:* display, search, click-to-source, narrative citation. *Earned by:* nearly everything the model emits. All 20 live observations qualify.
2. **Structurally verified.** Tier 1 **plus** a resolved cell with its row label, column label and governing entity, or a prose span whose sentence carries the concept. *Use:* source-linked findings, tables, exports, facts the user may quote. *Earned by:* 22 of the live observations under §4.
3. **Analytically eligible.** Tier 2 **plus** a comparable identity: proven quantity kind (**count included** — fix Experiment 6), unambiguous period granularity, consistent basis, consistent scale. *Use:* charts, deltas, reconciliations. *Earned by:* 18 under §4, 12 of them forming 4 series.

Equally important: **a refusal must be displayed as a document-specific limitation, not as doubt about the number.** "Q2 figure present; year not printed in this table" is useful. "Source unverified" on a figure the system has verified to a character offset is a lie about the product's own confidence.

### Fallback behaviour

- No `CellGrid` for a PDF region (scan, exotic layout) → fall back to today's line-span path, tier 2 unreachable for that region, tier 1 retained, and say so in coverage.
- `EvidenceResolver` ambiguous (two entities govern a page, two columns match a period, merged cell spanning candidate headers) → refuse with the named ambiguity. Experiment 4 shows this refuses correctly on D1/D2/D4/D5.
- OCR'd text → tier 1 only by default, with a per-page OCR confidence recorded; tier 3 requires an explicit per-workspace opt-in.
- Model omits a period the grid contains → `RowExpander` supplies it. Model invents a period the grid lacks → refused, as today.

---

## Part 7 — Keep, change, remove

### Keep unchanged

| component | why |
|---|---|
| `SourceSpanBuilder` offsets, `EvidenceSpanSet::resolve()`, span-version hashing | offsets re-read from `extracted_text`; evidence cannot drift. Reproduced byte-identically. Sound. |
| `PdfTabularCellVerifier` cell/column/operator/value arithmetic | the parts that compare a cell to a claim are correct — Experiments 1, 3, 4 all rely on them. Only its *inputs* and its concept-orientation assumption are wrong. |
| `MeasurementParser`, `PeriodParser`, `ValueBasisEvidence` core, `source_revision` discipline | correct and load-bearing. |
| `MetricCollector`, `ChartCandidateBuilder`, `AnalyticalComparisonBuilder`, `KpiResource`, `DocumentResource.charts[].sourceOrigin` | **do not touch.** They consume `DocumentEvidence(kind=metric)`; any correct producer feeds them unchanged. Their zero output was correct given zero input. |
| `model_unverified` chart provenance and the frontend's limitation surfaces | honest labelling, built right. |
| `extractDocxStructured` / `extractXlsxStructured` cell models, `StructuredDocument` invariants | already the right shape; `StructuredDocument` even validates that every node's text equals its offsets. |
| The V10 safety fix requiring structural proof for every PDF `table_row` | **do not weaken.** Extend it to DOCX (below). |

### Change

| component | change | evidence |
|---|---|---|
| **V10 prompt contract** | drop `subject_span_id`, `unit_span_id`, `period_span_id` and per-period enumeration; keep one anchor id, concept, value-as-printed, basis | §2.3, §2.6, Exp. 2–3 |
| **`NormalKpiGrounder`** | accept server-resolved roles from `EvidenceResolver`; keep every predicate | Exp. 2 |
| **`NormalKpiGrounder.php:38`** | gate the cell verifier on *"has a cell model"*, not `type === 'PDF'`; use the DOCX cells that already exist | **Exp. 1b — live false acceptance** |
| **`pageHeaderSubject`** | replace with nearest-governing-entity resolution + multi-entity guard | **Exp. 1c — live false acceptance;** Exp. 4 shows the replacement refuses correctly |
| **`PdfTabularCellVerifier` concept test** | accept concept in the row label **or** the column header, assigning the other to subject | Exp. 5 |
| **`MeasurementIntegrity`** | `analyticalEligible` should require a proven **quantity kind**, not a cited unit string; admit `count` as dimensionless | Exp. 6 |
| **`containsValue`** | accept the printed form (currency prefix, scale suffix, operator) that V10 rule 18 mandates | Exp. 7 |
| **`ValueBasisEvidence`** | scope basis-keyword detection to the cited cell/column, not a 900-character prose span | Exp. 7 |
| **`governs`** | admit caption-governed and cross-page definitional spans (glossary units) through an explicit, bounded relationship instead of a page-equality test | Exp. 9a, §2.7 |
| **`NormalRouteProvenance::eligible`** | the 500-span / 60k-char rendered cap silently disables all field citations at roughly **35,000 characters** of this document's density (126 spans ≈ 8,759 chars). Make the ceiling explicit and reported, not silent. | measured |
| **`NorthstarGoldScorer` and the eval harness** | generalise into a corpus-driven scorer with denominators (§8) | §2.6 |

### Remove / retire

| component | why |
|---|---|
| `SourceSpanBuilder::isTableRow` whitespace-delimiter guessing **as a verification input** | keep it for prose segmentation; retire it as the basis for cell proof. It is what made the DOCX gap and the `splitCells` delimiter matching necessary. |
| `PdfTabularCellVerifier::columns()` wrapped-heading reassembly, the `['quarterly','rate','received','cases']` stop-word list, the `/\btotal\s+proposed\b/` caption special case | three document-shaped patches that a cell model makes unnecessary |
| The requirement that the model emit one observation per cell | Exp. 3: code does it better and for free |
| The requirement that the model cite a governing entity header | 17/17 failures; server-resolvable |
| Model-authored chart JSON | already discarded in V10. Finish the job: remove the V9 persistence path once V9 is retired. |
| "N tests pass / rejection accuracy" as a progress metric | §Part 1. 622 passing tests coexisted with zero product output. |

---

## Part 8 — Implementation and evaluation plan

Four stages. **Each is falsifiable before the next is built.** Stage 1 is the proof-of-concept that could kill the whole recommendation.

### Metrics that should govern development (replacing test counts)

Report every one with an explicit denominator, and mark each as **observed** or **estimated**:

| metric | denominator | gate |
|---|---|---|
| Factual precision | emitted observations | ≥ 0.98 |
| **False acceptance** | adversarial claims injected | **0, non-negotiable** |
| Tier-2 coverage (structurally verified) | hand-labelled source facts in the corpus | ≥ 0.70 |
| Tier-3 coverage (analytically eligible) | hand-labelled *comparable* facts | ≥ 0.60 |
| False rejection | hand-labelled facts that are true but refused | ≤ 0.15, each with a named reason |
| Important-information recall | facts a human reviewer marks material | ≥ 0.80 |
| Semantic correctness | accepted observations (subject/unit/period/basis each checked) | ≥ 0.95 |
| Chart & comparison correctness | generated charts/comparisons | 1.00 |
| Latency / cost | per document | ≤ 35 s, ≤ $0.01 |
| Generalisation | documents in the corpus other than Northstar | every gate met on ≥ 80% |

A system that rejects everything fails coverage and false-rejection. A system that accepts bad claims fails false acceptance and dies immediately. Neither can look successful.

### The test corpus to assemble first (12–15 documents, one week of work)

Northstar is one relatively clean, synthetic, tab-extracting PDF; `test_5page.pdf` is 224 characters of synthetic prose; `embedded-emf.docx` is an image fixture. **That is not evidence for a general architecture.** The documents in `storage/app/private/documents/` were **deliberately not opened** — provenance and authorisation are unclear. Assemble, with hand-labelled gold facts and deliberate negatives:

1. a real board pack with multi-page tables and repeated headers; 2. a group report with **two or more reporting entities**; 3. a **scanned** financial PDF (OCR path); 4. a DOCX financial report with native tables; 5. a multi-sheet XLSX with merged cells and hidden rows; 6. a commercial contract (obligations, no KPIs); 7. a pure-prose policy document — **expected output: nothing, confidently**; 8. a report with transposed tables (periods down the rows); 9. a table with footnote-governed units and a glossary; 10. a multi-column/newsletter layout (reading order); 11. a document with a deliberate internal contradiction; 12. one with a value repeated across unrelated tables. Plus the five synthetic adversarial cases from Experiment 4, as permanent regressions.

### Stage 1 — server-side evidence resolution (the falsifying proof-of-concept)

- **Build:** `EvidenceResolver` (governing entity, column label, caption unit, with a multi-entity guard) + `RowExpander` + deterministic period/basis label splitting. Behind `DOCINTEL_SERVER_EVIDENCE_RESOLUTION`, default off. No prompt change yet.
- **Run against:** the frozen live V10 response, the frozen V9 response, the 14-observation offline fixture, and the adversarial corpus.
- **Acceptance:** ≥ 20 grounder-eligible and 4/4 complete financial series from the **unchanged** live response (measured: 22 and 4/4); **0** false acceptances on the adversarial corpus (measured: 0/9); the V9 accepted observation stays accepted; the reproduced wrong-period synthetic stays rejected.
- **This stage falsifies the recommendation if:** eligibility rises but any adversarial claim is accepted, or the resolver needs per-document tuning to reach the numbers above.
- **Rollback:** flag off. No schema change, no data migration.

### Stage 2 — close the two live false-acceptance defects

- Extend structural-proof-or-refuse to DOCX using the existing `extractDocxStructured` cells; replace `pageHeaderSubject` with Stage 1's resolution; fix the concept/subject orientation; admit proven `count` as dimensionless; fix `containsValue` and basis scoping.
- **Acceptance:** Experiments 1b and 1c become permanent regression tests and fail closed; prose monetary facts verify in their printed form; **report the count of newly rejected rows on the corpus** — a DOCX acceptance drop is a safety gain, not a regression, and must be labelled as such.
- **Rollback:** each change independently revertable; the DOCX tightening is the only one that can reduce acceptance, so ship it with the count published.

### Stage 3 — `CellGrid` for PDFs, from poppler geometry

- Typed cells mirroring Docling's `TableCell`, with exact `start_offset`/`end_offset` into `extracted_text` so existing provenance keeps working. Improve region detection's continuation-merge rule (Experiment 9c's known weakness). Dual-run against the line-span path and diff.
- **Acceptance:** both Northstar breakdown tables produce verified composition data (today: impossible under any citation — Experiment 8); region detection recovers ≥ 90% of hand-labelled corpus tables with **zero** wrong cell assignments; no observation that grounds today stops grounding.
- **This stage is falsified if** region detection cannot clear 90% without per-document tuning → then, and only then, evaluate a Docling sidecar on the same corpus with the same gates.
- **Rollback:** `CellGrid` is additive; the line-span path stays as fallback for a full release.

### Stage 4 — simplify the model contract, and only then a paid run

- New prompt: facts + one anchor. Then **one** paid run per corpus document under the existing `NorthstarV10RequestGuard` spend discipline.
- **Acceptance:** all gates above; cost ≤ $0.01/document; fewer output tokens than V10's 7,033 (the contract is smaller).
- **Rollback:** prompt versions are DB rows; V9/V10 remain selectable.

---

## Part 9 — Critical uncertainties

1. **Generalisation is the biggest unknown, by far.** Nine experiments, one real document, five synthetic controls. Every quantitative claim here is about Northstar. The corpus in §8 is a prerequisite for believing any of it, including my own recommendation.
2. **Whether a simplified contract improves model behaviour is untested** and requires paid runs. I have deliberately made Stages 1–3 independent of it: they improve the *existing* response.
3. **Region detection on real-world layouts is unmeasured.** Experiment 9c's over-splitting is a known weakness on wide wrapped-cell tables; whether an improved merge rule suffices, or a layout model is required, is exactly what Stage 3 decides.
4. **OCR and scans are entirely unmeasured here.** No scanned fixture exists. Geometry from OCR is noisier and may not support tier 3 at all.
5. **Which prompt version production actually runs is DB state I cannot read.** The repository's `DatabaseSeeder` activates **v3**, and V4–V10 are all seeded `active => false` (V10 is not even in the seeder list). If a production environment runs v3 or v7, there are no `source_context` citations at all, so `NormalKpiGrounder` returns `missing_source_reference` for everything and **the entire field-role verification stack currently produces nothing in that environment.** Confirm the live `ai_prompts` state before planning migration.
6. **Human judgement is required for "important-information recall" and materiality.** No automated metric substitutes; budget reviewer time.
7. **The multi-entity guard's entity recognition is a regex** over `Ltd|Limited|PLC|Inc|LLC`. Non-Latin scripts, Kenyan/other local entity suffixes, trading names and departments are unhandled. This needs real documents before it can be trusted as a safety guard.
8. **Legal review needed** on Marker's weights licence if it is ever evaluated; and a data-residency decision before any hosted document-AI API is considered.
9. **Unknowable without instrumentation:** the real distribution of document shapes in the product's actual traffic. Everything in Part 8 assumes financial tables matter most. Log table-shape and refusal-code distributions in production before investing in Stage 3.

---

## Recommended direction, and the smallest experiment that could prove me wrong

**Direction: Approach B now, Approach C next — "the model proposes, the server proves."** Keep the layering, keep every verification predicate, delete three of the model's four jobs, and give PDFs the cell model DOCX and XLSX already have. Do not rewrite the pipeline; do not add a dependency until a real corpus proves geometry insufficient; do not add another refusal code.

**The evidence, in one line each.** The model's facts were right (20/20) and every one of its 20 values resolved to the correct cell, so this is not an LLM-capability problem. One role it was asked to resolve failed 17/17 across two runs, and replacing just that role server-side moved 0/20 to 11/20 with nothing else changed. Enumerating the rows it had already cited produced all four complete Q1–Q3 series it never emitted, reaching 22 grounder-eligible observations — more than the hand-cited fixture — with no predicate weakened. On an adversarial corpus the server-side rules produced 0 false acceptances and 0 false rejections, while the *shipped* rules they replace accept a parent company's banner as proof of a subsidiary's figures, and accept a wrong-period claim outright on any DOCX. Two Northstar tables are unverifiable under every possible citation, and whether a caption can prove a unit depends on its capitalisation — so the ceiling is the representation, not the prompt. External practice agrees: model-supplied citations are wrong 5–15% of the time even on ordinary spans, and post-hoc span alignment beats asking the model.

**The smallest experiment that could prove me wrong.** Build `EvidenceResolver` + `RowExpander` behind a default-off flag — no prompt change, no new dependency, no model call — and run it over the frozen V10 live response, the frozen V9 response, and the twelve-document corpus with the five adversarial controls as permanent regressions. **I am wrong if either of two things happens:** (a) eligibility rises but **even one** adversarial claim is accepted — then server-side resolution is trading false rejection for false acceptance and the model must keep the role; or (b) reaching ≥20 eligible observations on the corpus requires document-specific tuning of the resolver — then I have merely relocated the patching problem, and the honest answer is a real document-understanding dependency (Approach C with Docling) rather than more deterministic PHP. Both outcomes are visible within one Stage-1 cycle, before any large implementation work.
