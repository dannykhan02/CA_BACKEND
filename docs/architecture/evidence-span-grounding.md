# Evidence-span grounding

Implemented 2026-10-06 on branch `feat/docintel-evidence-spans`. Read
[incremental document intelligence](large-document-processing.md) first; this changes one
part of that pipeline and nothing else.

No push, deployment, production configuration change, production migration, flag enablement
or live provider call was performed. The feature flag defaults to off.

## The problem

Evidence grounding required the model's `quote` to be an exact substring of the slice it was
given. On PDF-extracted text that rejects sound evidence for formatting alone: a line break
inside a sentence, a run of spaces from a table layout, a non-breaking space, a curly
apostrophe, an en dash.

Observed production run, `adb-annual-report-2024.pdf`:

| | |
|---|---|
| Leaves | 12 (7 completed, 4 failed, 1 budget) |
| Splits | 3 |
| Records returned | 656 |
| Records accepted | 103 |
| Acceptance rate | 15.7% |
| `invalid_evidence` | 526 |
| `invalid_date` | 27 |
| `quote_not_found_in_source` | 526 |
| `explicit_date_missing_due_date` | 27 |

Worst leaves: `chunk:3` 110 returned / 1 kept, `chunk:2.1` 74 / 0, `chunk:2.0` 65 / 2,
`chunk:1.2` 57 / 0, `chunk:1.1` 35 / 0.

## The change

Grounding is not weakened. It is inverted.

```
old:  AI returns an evidence quote  ->  DocIntel searches for that quote in the source
new:  DocIntel labels source spans  ->  AI returns span IDs  ->  DocIntel retrieves the
                                                                 exact original text itself
```

Nothing is rescued by semantic similarity, embeddings or fuzzy matching. An ID is either one
the chunk supplied, in the right extraction version, within the configured limits, or the
record is rejected.

### Flow

```
extraction (native text or OCR, form-feed page boundaries)
  -> deterministic segmentation into evidence spans          SourceSpanBuilder
  -> span set persisted as offsets under an extraction version   document_source_spans
  -> chunks planned from whole spans                         ChunkPlanner::planFromSpans
  -> one provider call per chunk, source labeled [E001]...    AnthropicClient::extractChunk
  -> AI returns finding + evidence_ids
  -> strict ID validation                                    EvidenceSchema::ground
  -> DocIntel resolves the exact span text and location      EvidenceSpanSet::resolve
  -> finding stores exact source evidence + location          EvidenceMerger
  -> existing merge / reference resolution / synthesis unchanged
```

## Evidence span schema

`document_source_spans` holds **offsets only**. A span always resolves through
`documents.extracted_text`, so it cannot disagree with its source and a large document never
stores its own text twice.

| column | meaning |
|---|---|
| `id` | uuid |
| `workspace_id`, `document_id` | cascade-deleted with the document |
| `extraction_version` | 32-hex fingerprint, see below |
| `span_key` | `E001`, `E002`, …; zero-padded to at least three digits, widening with the span count |
| `ordinal` | 1-based position in document order; the locality rules use it |
| `page` | absolute page, or null when the page map is not known to be complete |
| `start_offset`, `end_offset` | half-open **character** offsets (not bytes), matching `document_chunks` |
| `type` | `sentence`, `section`, `heading`, `list_item`, `table_row` |

Unique on `(document_id, extraction_version, span_key)`; indexed on
`(document_id, extraction_version, ordinal)`.

The whole set for one version is written in a single transaction, so a reader sees all of it
or none of it.

## Extraction versioning

```
extraction_version = substr(sha256(sha256(extracted_text) | span_segmenter_version), 0, 32)
```

- Different extracted text, or a different segmenter, is a different version. Existing IDs
  are never silently remapped onto new text.
- The version is recorded on the document as `ai_pipeline.extraction_version`, and every
  record is validated against it. A mismatch rejects with
  `evidence_id_wrong_extraction_version`.
- `document_intelligence.span_segmenter_version` is the segmenter's own version. Bumping it
  invalidates every persisted span set rather than changing what an existing ID means.
- Re-extraction also changes the pipeline key (it already hashes the extracted text), so a
  re-extracted document plans a fresh pipeline. Old findings stay traceable because the
  resolved span text and its offsets are stored on the finding at merge time.

Deliberately not a version-control system: old span rows are kept, never rewritten, and
nothing attempts to migrate an ID from one version to another.

## Segmentation rules

`SourceSpanBuilder` is deterministic and never rewrites, normalizes or corrects source text.

1. Lines are split on `\n` and `\f`; `\f` increments the page. **A span never crosses a page
   break.**
2. Each line is classified: table row, list item, heading, or prose. A blank line, a page
   change or a classification change ends a block.
3. - **Table row** → one span per row, so a row label stays with its values.
   - **List item** → one span per marker, including wrapped continuation lines.
   - **Heading** → one span; a heading keeps only *short* dependent content (type `section`)
     and never swallows a paragraph.
   - **Prose** → split at sentence boundaries, then grouped to reach a usable size.
4. Sentence boundaries are filtered in PHP rather than by regex lookbehind, so a period
   belonging to a decimal, a currency amount, an initial (`U.S.`) or a known abbreviation
   (`No.`, `Mr.`, `approx.`, `Fig.`, month names, …) is not a boundary. `$12.4 billion` and
   `2025-03-31` stay whole.
5. A sentence longer than `span_hard_max_chars` is split at a semicolon, else a comma not
   followed by a digit, else whitespace. Never inside a word or a number.
6. A unit below `span_min_chars` is merged with its neighbour when the result stays within
   `span_max_chars`, on the same page, and neither side is a table row or a list item.

Targets: `span_min_chars` 50, `span_max_chars` 400, `span_hard_max_chars` 1200. Structure
wins over size: a 38-character table row stays one span.

**Segmentation is lossless except for whitespace.** Concatenating every span reproduces every
non-whitespace character of the source, and this is asserted in the tests.

### Measured on a representative 40-page annual-report fixture

Local, provider-free (`ChunkPlanner` + `SourceSpanBuilder`, no API calls):

| | |
|---|---|
| Document | 39,062 chars, 40 pages |
| Spans | 520 — 200 sentence, 200 table_row, 80 list_item, 40 section |
| Span length | min 38 / median 51 / mean 74 / max 162 chars |
| Labeled input overhead | **+10.2%** bytes over the raw slice |
| Per-record output | 406 → 343 bytes, **−15.5%** |
| System prompt | 2,657 → 3,811 bytes (one cached prefix per run) |
| Response schema | 1,120 → 1,185 bytes |

The +10.2% is the real, unavoidable cost of labeling: `[E001]\n` plus the blank line is about
8 bytes per span, and the median span is 51 characters. It is largest on table-heavy text,
where rows are short and deliberately not merged. The lever, if it ever matters, is
`span_min_chars` — raising it produces fewer, larger spans.

## Table handling

Pipe- or tab-delimited rows, and columnar rows (three or more columns separated by runs of two
or more spaces, with at least two value columns, where a value column is one ending in a
figure), each become one span. A label column stays attached to its values:

```
[E081] Revenue              6.4         5.9
[E082] Total assets         42.1        39.8
```

Prose that merely mentions several figures is single-spaced and stays prose.

**Limitation:** no structure is invented. The extractor's own representation is preserved
exactly. Where PDF text extraction has already collapsed a table into ambiguous text, the
span is that ambiguous text. No AI table parsing was added.

## OCR / scanned content

OCR output reaches `extracted_text` through the same path as native text, with `\f` page
joins, so span IDs work on it unchanged. The exact OCR characters the model saw are what come
back — noise, spacing and mis-recognitions included. Span IDs fix quote *reproduction*
mismatch; they do not and cannot fix OCR accuracy.

## Prompt contract

The request replaces `source_text` with `evidence_spans` plus `max_evidence_ids`:

```
[E001]
The Bank approved 37 operations during 2024.

[E002]
Total commitments reached $12.4 billion,
compared with $10.1 billion in 2023.
```

The schema drops `quote` and adds `evidence_ids` (`minItems` 1, `maxItems`
`max_evidence_ids`). Instructions tell the model to use only IDs present in this slice, never
to invent or renumber one, never to quote source text as evidence, to cite the smallest set
that supports the record, and not to combine distant or unrelated spans.

A truncation continuation now lists `evidence_ids` for what was already extracted instead of
short quotes — cheaper and unambiguous.

### Date clarification (the 27 `explicit_date_missing_due_date` rejections)

The span-mode prompt states the contract the validator already enforces: `due_date` is a
complete `YYYY-MM-DD` date and is allowed only when `date_type` is `explicit`; `March 2024`,
`FY2025`, `Q3 2026`, `mid-2026`, `by year end` are not explicit dates and must be recorded as
`relative`/`inferred` with the wording kept in `value` and `due_date` null; a partial period is
never completed by assuming a day, month or fiscal-year end.

This is a prompt clarification only. **No date validation was weakened or changed**, and the
legacy prompt is byte-identical to before, so existing pipelines and their prompt cache are
untouched. Recommendation: apply the same clarification to the legacy prompt as a separate
change with a `prompt_version` bump, so its cache and A/B baseline are not disturbed mid-test.

## Validator changes

`EvidenceSchema::validate($result, $text, ?EvidenceSpanSet $spans, ?string $expectedVersion)`.
With `$spans` null the legacy path is byte-for-byte unchanged.

In span mode, per record:

1. `evidence_ids` present → else `missing_evidence_ids`
2. list of strings → else `evidence_ids_wrong_type`
3. trimmed, upper-cased, de-duplicated preserving order; empty after that →
   `missing_evidence_ids`
4. count ≤ `max_evidence_ids` → else `too_many_evidence_ids`
5. exists in this extraction version → else `unknown_evidence_id`
6. was in *this chunk* → else `evidence_id_outside_chunk`
7. chunk's extraction version matches the span set → else
   `evidence_id_wrong_extraction_version`
8. multiple IDs within `evidence_span_locality` ordinals (0 disables) → else
   `invalid_evidence_span_combination`

Then DocIntel resolves. Schema, `confidence`, `kind`, date and deadline rules are all
unchanged and still enforced.

### Claim-to-evidence limitation

**Valid IDs prove the cited source exists. They do not prove the finding follows from it.**
That was equally true of a verbatim quote, and it is unchanged here. No second verification
call and no entailment subsystem were added. Structured-value, confidence and date rules are
the deterministic checks that remain.

## Resolution and persistence

After validation each accepted record carries:

```json
{
  "label": "Total commitments",
  "value": "$12.4 billion",
  "evidence_ids": ["E002"],
  "evidence": [{ "span_id": "E002", "page": 14, "start_offset": 1052,
                 "end_offset": 1188, "type": "sentence", "text": "<exact source text>" }],
  "quote": "<exact source text, derived by DocIntel>"
}
```

`quote` is the compatibility representation, derived by DocIntel from the referenced spans and
never taken from the model. A model-supplied `quote` is ignored entirely. Multiple spans are
joined in source order for `quote` only; internally each stays separate.

`EvidenceMerger` turns each cited span into its own source link:
`{chunk_id, span_id, start_offset, end_offset, quote, page}`. It no longer searches the chunk
text for span-based records, so formatting cannot reject anything there either. Legacy records
keep their existing substring lookup as defense in depth.

## Backward compatibility

- Stored findings are **not** rewritten. Nothing historical is invalidated or migrated.
- `document_evidence.data.quote` and `document_evidence.sources[]` keep their shape;
  `span_id` is an additive key.
- The API response (`DocumentIntelligenceResource`) is unchanged apart from that additive key,
  so no frontend change is required. `src/types.ts` was updated to document it as optional.
- `EvidenceMerger`, `ContextResolver`, `EvidenceBudget`, `DocumentIntelligenceResource`,
  `docintel:benchmark` and the exports all read `quote` and keep working for both record
  shapes.
- Both shapes can coexist within one merge, which is covered by a test.
- Chunk, evidence and run tables are unchanged. The only schema addition is the new span
  table.

## Feature flag

```
DOCINTEL_EVIDENCE_SPANS_ENABLED=false     # config('document_intelligence.evidence_spans')
```

Independent of AI-credit configuration; no billing, credit, plan or pricing behaviour was
touched.

- **Off:** current behaviour, unchanged.
- **On:** analyses planned *after* the change use span grounding.

The grounding mode is part of the pipeline key, so flipping the flag starts a new pipeline
instead of mixing two record shapes inside one, and a running pipeline keeps the mode it was
planned with (`ai_pipeline.grounding`) even if the flag changes under it. The legacy
small-document four-job path is not affected by the flag at all.

Documents whose text produces no spans (whitespace only) fall back to legacy planning.

## Diagnostics

Validation diagnostics gain `evidence_grounding_mode` (`legacy_quote` | `span_reference`) and
the seven span rejection reasons above, all counted under the existing `invalid_evidence`
class so aggregate counts continue to reconcile. Legacy reasons, including
`quote_not_found_in_source`, are unchanged.

`Document intelligence validation summary` now logs `evidence_grounding_mode`. As before, the
log carries identifiers, timings, counts and USD amounts only — never source text, quotes,
prompts or responses. Asserted by test, including that a rejected ID string is not logged.

## Performance instrumentation

```
php artisan docintel:pipeline-report {document} [--json]
```

Read-only, provider-free, built entirely from rows the pipeline already writes
(`processing_jobs`, `document_chunks`, `document_ai_runs`, `document_evidence`). No new
observability subsystem.

Reports: total runtime; scan, extraction, incremental-analysis, merge and synthesis spans;
provider call count (total, extraction, failed); input/output/cache tokens; cost; slowest and
median provider call; output tokens per accepted record; roots, final leaves, splits, retries,
source spans, leaves by status and failure class; records returned/accepted/rejected,
acceptance rate, rejection classes and reasons; and a grounding section with chunks by mode,
span-reference vs legacy-quote sources, `quote_not_found_in_source`, span-ID rejections
broken out by reason, and other validation rejections.

## Expected API-call behaviour

**One provider call per chunk, exactly as before. Never one per evidence span.** Spans are
references *inside* an existing chunk call; a chunk typically carries 100–250 of them.

`ChunkPlanner::planFromSpans` sizes chunks by the labeled form the provider actually receives,
and a binary search picks the smallest per-chunk budget that still packs into the fewest
partitions the labeled weight requires — so rounding each span up and carrying whole spans as
overlap cannot cost an extra call. A test asserts the chunk count equals
`ceil(labeled_weight / target)` at five different target sizes.

The honest residual: the labeled source is ~10% larger, so a document sitting within ~10% of a
partition boundary needs one more chunk than it did. For a 12-leaf document that is roughly one
extra root call. Against that, eliminating `quote_not_found_in_source` should remove failed
leaves and the splits they caused.

Expected direction, to be confirmed by measurement, not assumed:

- input tokens: slightly up (span labels, longer system prompt — cached)
- output tokens: down (no repeated source quotes; −15.5% per record measured on the fixture)
- retry/split calls: down (quote mismatch no longer fails leaves)
- total cost: **unknown until measured.**

## Latency

No concurrency was tuned and no serialization was changed. `concurrency` (2 per document) and
`provider_gate.max_inflight` (2 globally) are untouched.

The 15-minute ADB wall clock cannot be attributed from the data in the task. The report
command now separates the candidates, and running it on both arms of the A/B answers it:

- `timing_ms.provider_total` vs `timing_ms.total` → how much is provider latency at all
- `timing_ms.provider_slowest` / `provider_median` → per-call latency
- `timing_ms.incremental_analysis` vs `provider_total` → queue waiting and serialization: with
  concurrency 2 and 12 leaves, 12 sequential-ish calls at 30–60s each is already 3–6 minutes
- `chunks.splits` / `chunks.retries` → re-sent work, each one a fresh full-size call
- `timing_ms.synthesis`, `timing_ms.merge`, `timing_ms.extraction` → the non-extraction stages

The structural suspicion worth testing first: 12 leaves + 3 splits = 15 full-size calls at
global in-flight 2. That is a concurrency *ceiling*, not a bug, and raising it is a separate,
measured decision — not something to do to hide 84% record rejection.

## Tests

`php artisan test` — **857 tests, 856 passed, 1 skipped, 0 failures** (PostgreSQL + pgvector).
Pint clean on every file touched. `git diff --check` clean. Frontend `typecheck`, `vitest`
(19 files, 90 tests) and production build all pass.

New, 76 tests:

- `tests/Unit/SourceSpanBuilderTest.php` (20) — sentences, short and long paragraphs, wrapped
  line breaks, Unicode punctuation and NBSP, money/dates/abbreviations never split, lists,
  table rows (columnar and pipe), prose-with-figures not mistaken for a table, headings with
  and without dependent content, numbered headings vs numbered list items, page boundaries, no
  merge across a page break, null pages when the map is unknown, OCR-like text preserved
  verbatim, stable repeated IDs, ID width growth without collision, whitespace-only input,
  overlong sentence split safely, and whitespace-only losslessness.
- `tests/Unit/SpanChunkPlannerTest.php` (8) — chunk count equals the fewest partitions at five
  targets, no boundary inside a span, every span planned once without overlap, overlap carried
  as whole spans, input hash describes the raw slice, an oversized span is never cut, pages
  carried from the first and last span, empty set plans nothing.
- `tests/Unit/EvidenceSpanValidationTest.php` (19) — schema asks for IDs and never a quote,
  instructions forbid quoting and inventing IDs, valid ID resolves exact text, the
  compatibility quote is DocIntel's not the model's, multiple IDs in source order, duplicate
  and mis-cased IDs normalized, unknown ID, invented ID shapes, ID outside the chunk, wrong
  extraction version, too many IDs, missing and wrongly typed IDs, locality on and off, all
  schema/confidence/date rules still enforced, a wrong model quote rejecting nothing,
  diagnostics reconciling with no source text, all-invalid response, empty response, and
  legacy mode untouched.
- `tests/Feature/EvidenceSpanPipelineTest.php` (26) — one persisted span set per version and
  its reuse, chunk boundaries are span boundaries, every span covered, token sizing, the
  prompt sends labeled spans and no `source_text`, a completed chunk stores resolved evidence,
  formatting differences reject nothing, an all-invalid chunk fails without splitting, a
  foreign ID rejected while valid siblings are kept, wrong extraction version, a split keeps
  span boundaries and source order, a one-span chunk is never split, continuation lists
  references not quotes, merge persists exact span text with location, multi-span evidence
  keeps separate sources, legacy records still merge, both shapes coexist, the API still
  exposes a quote and now carries span locations, provider calls stay chunk-level, diagnostics
  logged without source text, the report command reconciles, extraction flows through merge
  into synthesis, partial-coverage semantics survive a failed leaf, an all-invalid document
  needs review, and flipping the flag starts a new pipeline.

No live provider call was made. Every test fakes HTTP, and `Http::preventStrayRequests()` is
global.

## A/B validation procedure (the ADB report)

Run both arms on **the same document and the same configuration**, in a non-production
environment, with explicit approval for live provider calls.

Do not compare against the numbers in this document's problem statement as the "before" arm —
re-measure the legacy arm on the same build so the only difference is the flag.

```bash
# Arm A, legacy quote grounding
DOCINTEL_EVIDENCE_SPANS_ENABLED=false
php artisan config:clear
# upload adb-annual-report-2024.pdf, wait for a terminal status
php artisan docintel:pipeline-report <document-a> --json > /tmp/arm-a.json

# Arm B, span reference grounding. Same model, same extraction_max_tokens,
# same concurrency, same max_inflight, same budget ceiling.
DOCINTEL_EVIDENCE_SPANS_ENABLED=true
php artisan config:clear
# upload the same file again (a second workspace, or re-analyze a copy)
php artisan docintel:pipeline-report <document-b> --json > /tmp/arm-b.json
```

Hold constant: extraction and synthesis model, `ANTHROPIC_EXTRACTION_MAX_TOKENS`,
`chunk_max_tokens`, `chunk_overlap_tokens`, `concurrency`, `provider_gate.max_inflight`,
`DOCINTEL_MAX_DOCUMENT_COST_USD`, OCR settings, and whether AI credits are enabled.

Compare, field by field from the two JSON reports:

| metric | report field |
|---|---|
| total runtime | `timing_ms.total` |
| provider call count | `provider.calls`, `provider.extraction_calls` |
| input tokens | `provider.input_tokens` |
| output tokens | `provider.output_tokens` |
| provider cost | `provider.cost_usd` |
| root chunks | `chunks.roots` |
| final leaves | `chunks.final_leaves` |
| splits | `chunks.splits` |
| retries | `chunks.retries` |
| records returned | `records.returned` |
| records accepted | `records.accepted` |
| acceptance rate | `records.acceptance_rate` |
| failed leaves | `chunks.by_status`, `chunks.failure_classes` |
| Partial vs Complete | `document.result`, `document.partial` |

The five questions, and what answers them:

1. **Does `quote_not_found_in_source` disappear as a major failure mode?**
   `grounding.quote_not_found_in_source` should be 526-ish in arm A and **0** in arm B.
   `grounding.span_id_rejections` is the replacement failure mode; if it is large, read
   `grounding.span_id_rejection_reasons` — `unknown_evidence_id` means the model is inventing
   IDs (a prompt problem), `evidence_id_outside_chunk` means it is citing across chunks,
   `invalid_evidence_span_combination` means `evidence_span_locality` is too tight.
2. **Does acceptance rise materially above 15.7%?** `records.acceptance_rate`. Below ~60% in
   arm B, do not ship: read the rejection reasons first.
3. **Does output-token volume fall?** `provider.output_tokens` and, because record counts will
   differ, `provider.output_tokens_per_accepted_record` — that ratio is the fairer comparison.
4. **Does runtime improve?** `timing_ms.total`, with `timing_ms.provider_total` and
   `chunks.splits` to say *why*.
5. **Does cost improve or stay controlled?** `provider.cost_usd`. Expect input up ~10% and
   output down; the sign of the total is genuinely unknown. Also compare cost per accepted
   record.

Sanity checks before trusting either arm: `chunks.roots` should differ by at most one or two
(span labeling must not multiply calls); `provider.extraction_calls` must be on the order of
`chunks.final_leaves`, never of `chunks.source_spans`; `grounding.chunks_by_mode` must show a
single mode per arm.

Repeat each arm at least twice. Provider latency varies enough that a single pair of runs
cannot settle the runtime question.

## Remaining risks

1. **Model compliance is unproven.** No live call was made. The model may invent IDs, cite
   across chunks or over-cite. All three are rejected and counted, so the A/B will show it —
   but a high `unknown_evidence_id` rate in arm B would mean more prompt work.
2. **One extra root call near a partition boundary**, from the measured ~10% labeled-input
   overhead.
3. **Segmentation heuristics.** Classification is deterministic but heuristic. A figure-dense
   columnar prose line can be read as a table row (one span instead of a sentence group, which
   is benign); an unpunctuated short numbered list item can be read as a heading (affects the
   type label and continuation absorption only).
4. **Span granularity shifts what a finding cites.** A short paragraph may be one span, so a
   citation can be a little broader than a hand-picked quote. The offsets are exact either way.
5. **Table ambiguity is inherited**, not introduced, from the extractor.
6. **One more table on the write path.** ~2,700 rows for a document the size of the ADB
   report, written once per extraction version in one transaction.
7. **The claim-to-evidence gap is unchanged** (see above).
8. **The ADB wall-clock attribution is still unmeasured.** The instrumentation exists; the run
   has not been done.

## Known limitations

- No AI table parsing; no invented table structure.
- OCR accuracy is untouched.
- Spans cover every non-whitespace character but the inter-span whitespace itself is not sent
  to the model, so pure-layout whitespace is not citable. Nothing citable is lost.
- `evidence_span_locality` is an ordinal-distance rule, not a semantic one.
- The legacy prompt did not receive the date clarification (deliberately — see above).
- Historical findings are not backfilled with span IDs.
- The span table has no retention policy; it is cascade-deleted with its document.

## Environment variables

| variable | default | meaning |
|---|---|---|
| `DOCINTEL_EVIDENCE_SPANS_ENABLED` | `false` | new; enables span grounding for analyses planned afterwards |

Config keys added (no env var, repository convention):
`span_segmenter_version` `1`, `span_min_chars` `50`, `span_max_chars` `400`,
`span_hard_max_chars` `1200`, `max_evidence_ids` `3`, `evidence_span_locality` `12`.

No existing variable changed meaning. No billing, credit, plan or pricing configuration was
touched.

## Status

**READY FOR CONTROLLED TESTING.**

Ready in the sense that the flag is off, the legacy path is unchanged and fully green, the
schema addition is additive and reverses cleanly, and the measurement to make the decision
exists. It is **not** ready to enable: the A/B above has not been run, and no live provider
call has confirmed that the model returns usable span IDs.
