# DocIntel real-document evaluation intake

22 October 2026. **No authorized real document is registered yet.** This workflow prepares private cases for the existing evidence-first gate; it does not enable evidence resolution, call an AI provider, or establish a real-document performance result by itself.

## Choose six initial documents

Use files you are authorized to inspect and retain locally. A useful first set is: (1) a standard financial PDF; (2) an irregular or multi-entity financial PDF; (3) a native DOCX report with a table and merged headers; (4) a DOCX contract or prose-heavy report; (5) a multi-sheet XLSX workbook; and (6) an XLSX workbook with merges, formulas, or counts. Label every case with its actual structure; do not count one file as coverage for a structure it does not contain. A scanned PDF with no usable text should be recorded as unsupported rather than given guessed source offsets.

The tracked `tests/Fixtures/intelligence-v2/evidence-first-corpus.json` remains the fictional Northstar and synthetic inventory. Its real-document count describes that tracked replay, not the private cases shown by `python3 tools/evidence_corpus.py list`. Real originals, extraction snapshots, annotations, provider envelopes, and run results live only under ignored `storage/framework/testing/`. The intake tool copies original bytes, records SHA-256, parser version, extracted-text hash and source-span revision, and makes the case files private (`0600`). Do not put real documents or restricted provider responses in Git.

## Register each file

From `CA_BACKEND`, choose **one** input category for each document:

- `real_recorded_model`: an unchanged, previously recorded **V10** provider envelope exists. This category measures extraction and verification. The recorded response must come from an authorized prior run of this exact source and prompt; record that provenance. No new inference is performed.
- `real_human_observations`: a reviewer writes candidate KPI observations for the backend. This category measures verification only. Its report always records **zero model observations**.
- Synthetic fixtures remain in the tracked regression suite and never enter either real category.

```bash
python3 tools/evidence_corpus.py intake \
  --id YOUR-LOWERCASE-CASE-ID \
  --source /absolute/path/to/authorized/document.pdf \
  --category real_human_observations \
  --authorization 'Permission record or owner approval reference' \
  --provenance 'Publisher, source location, acquisition date, and version' \
  --reviewer 'Human intake reviewer'
python3 tools/evidence_corpus.py list
```

Repeat for the other five documents, using `.docx` or `.xlsx` as appropriate. A case ID cannot be reused to replace its original. The original remains at `storage/framework/testing/docintel-evaluation-corpus/<id>/original.<ext>`; the generated `snapshot.json` contains exact extracted text, spans, native nodes where available, and parser capabilities. Inspect both the original and the snapshot before annotating. An empty scan or truncated/unstable extraction needs a documented unsupported outcome; do not invent a source reference.

## Annotate independent truth

Edit the private `<id>/truth.json` before examining replay predictions. Set `reviewer`, `reviewed_at` (`YYYY-MM-DD`), and `review_status` to `human_verified`. Add independently checked `source_facts` for recorded-model cases, including important facts the model may omit. Each fact needs `id`, `metric`, exact printed `value`, `unit`, `period`, `basis`, governing `entity`, metric `population`, `analytical_eligible`, and `source_references`. For an absent or ambiguous field use JSON `null` and a matching `<field>_status` such as `missing` or `ambiguous`; use `dimensionless`, `count_unprinted`, or `not_applicable` only when supported by the source. Never infer the reporting year from the filename.

Add one `claims` entry for **every** recorded model observation or human candidate being judged. It needs the same semantic fields and references, plus `expected_decision` (`accept` or `reject`) and `analytical_eligible`. A rejected claim also needs `expected_refusal_reason`, written in plain language. Recorded-model claims use zero-based `candidate_index`; human-prepared claims use `candidate_id`. Link a claim to independent source gold with `source_fact_id` where applicable. A recorded response with zero observations can have an empty `claims` list while retaining reviewed `source_facts`, so omissions remain measurable. Record `expected_chart_membership` and `expected_comparison_membership` as lists of objects containing `claim_ids`; leave them empty when no membership has been reviewed. Missing truth is reported as missing, never as success.

Each `source_references` entry needs `role` (for example `value`, `period`, `basis`, `unit`, `entity`, `caption`, or `concept`), exact `span_id`, Unicode-character `start_offset`, `end_offset`, and exact `quote` from `snapshot.json`. A PDF reference needs `page`; an XLSX reference needs `sheet`, and an XLSX value reference needs its exact native `node_id`. Add `node_id`, `table`, `row`, `column`, and `cell` for other structures where known. The validator checks quotes against the frozen extracted text, offsets against the cited span, and native nodes where supplied. Review the governing relation yourself; matching offsets alone do not prove a row/header/entity relationship.

Prepare candidates separately from truth:

```bash
# Backend-only candidate file: {"origin":"human_prepared_observations","claims":[...]}
# Each candidate needs id, label, value, unit, period, concept, scope,
# metric_type, basis, and source_context with genuine span IDs.
python3 tools/evidence_corpus.py attach --id YOUR-LOWERCASE-CASE-ID \
  --file /absolute/path/to/human-candidates.json \
  --provenance 'Candidate author and preparation date'

# Or, for a previously recorded V10 model-response envelope:
python3 tools/evidence_corpus.py attach --id YOUR-LOWERCASE-CASE-ID \
  --file /absolute/path/to/recorded-provider-envelope.json --response \
  --prompt-version 10 \
  --provenance 'Original run ID, prompt revision, model, and recording date'

python3 tools/evidence_corpus.py validate --id YOUR-LOWERCASE-CASE-ID --draft
python3 tools/evidence_corpus.py review --id YOUR-LOWERCASE-CASE-ID
python3 tools/evidence_corpus.py validate --id YOUR-LOWERCASE-CASE-ID
```

`review` seals the independent truth hash after checking the original, snapshot, candidate input, references, decision labels, and category. Change a reviewed source, candidate or truth file by creating a new case version. Keep the authorization record available to reviewers; the manifest stores its reference, not a blanket permission claim.

## Run the offline gate

Use the existing named disposable local PostgreSQL cluster at `storage/framework/testing/docintel-v9-pg`, bound to `127.0.0.1:55449`, with an empty `docintel_v9_eval` database. The PHP test independently checks cluster directory, host, port, database name, and empty user/document tables. It runs inside a transaction. It refuses a production database. Use the existing local database setup instructions for this evaluation cluster; no migration or production data access is part of intake.

```bash
python3 tools/evidence_first_gate.py --real-case YOUR-LOWERCASE-CASE-ID --repeat 2
```

Run all six cases separately. Results are private JSON under `storage/framework/testing/docintel-evidence-first-gate/`, with off/on decision counts, source links, exact cell proof, eligibility, reviewed false acceptance/rejection denominators, server expansion, charts, comparisons, API mapping count, and latency. Recorded-model and human-prepared counts remain separate. Unreviewed predictions remain **unknown**. The existing default `python3 tools/evidence_first_gate.py --repeat 2` still evaluates the fictional Northstar and synthetic controls.

The generic adapter uses the existing PDF, DOCX and XLSX extractors. Its recorded-response path is V10-only; a V9 response or a format whose extraction exceeds normal-route span bounds requires a separately reviewed adapter or an explicit unsupported outcome. No real-document replay has run yet, so format-specific correctness and generalization remain unmeasured.
