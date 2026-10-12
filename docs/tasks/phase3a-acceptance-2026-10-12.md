# Phase 3A acceptance cases (defined before implementation)

Baseline: `d1edf1e` on `fix/docintel-stabilization-revision-v1`. The current graph store, version fence, source API, native DOCX/XLSX nodes, PDF page nodes, and Tesseract word boxes already exist. The cases below test source relationships, not extraction success alone.

| Case | Ground truth and required result |
|---|---|
| Native table, two years and repeated numbers | Bind a value only to its complete cited cell. Its period must be the header covering that column; its metric must be the row label in the same table and row. Identical numbers in another cell must not be selected. |
| Adjacent units and unrelated tables | Units and periods must come from an explicit same-table caption/header relationship. A neighboring cell or other table cannot supply them. |
| DOCX merged and nested cells | Grid spans, vertical merges, nested table paths, and explicit header rows preserve distinct identity. A header must cover the value's grid column in the same table. Ambiguous merged-header paths remain unbound. |
| XLSX multiple sheets and native tables | Retain sheet, cell address, merge ownership, visibility, formula/cache origin. Hidden or formula cells cannot be upgraded to verified source values under the current policy. Table membership must use the native table range. |
| Multi-page PDF and OCR | Page identity is accepted when parser-known. No table, continuation, visual reading order, or coordinate claim without verified parser evidence. Repeated/hybrid passages retain channel and occurrence ambiguity. |
| Legacy/version/failure | Old evidence remains readable; changed source revisions hide old proof; failed graph publication leaves canonical evidence intact; revision ownership prevents stale activation. |
| Large reports | Provenance construction stays inside configured node/relationship limits and the extraction job's 120-second budget. Measure peak memory and elapsed time on a deterministic representative fixture. |

Acceptance measurement: count adversarial unsupported associations accepted as structural proof separately from supported associations rejected or lost. A positive result requires both counts, with fixture names and exact expectations. The complete backend suite must pass with one existing skip or fewer; no provider calls or production database writes. Format capabilities are scoped to the specific tested parser structures, never generalized to every PDF, DOCX, or XLSX.
