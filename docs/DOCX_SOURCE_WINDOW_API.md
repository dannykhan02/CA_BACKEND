# DOCX structural source inspection

## Audit

The source-reference path is `document_evidence.source_id` → published intelligence revision → `SourceReferenceResolver` → bounded source DTO. `source_anchors` stores source-owned paths, offsets, text and `source_hash`; the published revision pins both its provenance set and structured extraction. The existing source endpoint returns a 512-character quote preview, role anchors and relationships. It does not return a Word context window.

DOCX was **not only flattened text**: `DocumentTextExtractor::extractDocxStructured` already publishes the compatibility text together with ordered WordprocessingML nodes through `StructuredExtractionStore`. Its JSON nodes preserve paragraph paths, Unicode offsets, paragraph styles, body Heading1–Heading6 levels and section ownership, table/row/cell ownership, explicit header rows, grid spans, vertical merge information and footnote references. The immutable extraction is tied to file hash, text hash, parser version and structure policy. Pagination, layout, direct numbering ownership and list indentation were not preserved by parser v4.

The frontend already has an adapter registry and shared source states. PDF uses the existing authenticated PDF reader and text matching; JPG/PNG use the authenticated original image viewer without guessed citation geometry. XLSX uses a separately authorized, revision-pinned cell-window endpoint. The current checkout contained the completed XLSX adapter but its registry/manifest entries and workbook download label were absent; these small integration omissions are restored with existing compatibility tests.

## Architecture and schema decision

`GET /api/documents/{document}/sources/{sourceId}/docx-window?expected_revision_id={uuid}` follows the cell-window endpoint pattern. It authorizes the document, takes a shared document lock, checks the expected published revision, constructs its published view, and calls the existing `SourceReferenceResolver`. No resolver checks are bypassed or weakened.

`SourceDocxWindowReader` reads only the exact structured extraction pinned by that revision. `DocxWindowBuilder` locates the enclosing paragraph, heading or cell by the verified Unicode range. A row quote can select its canonical value cell when that role exists; otherwise a single row can be inspected using its raw quote anchor. Multiple rows or unsupported enclosing structures are refused. The reader then checks the persisted raw/native anchor's text, range, locator and `source_hash` before returning the window. Neighbor text is checked against the pinned extracted text as well.

No migration, new table, new dependency, backfill or second document representation is added. Parser **wordprocessingml-body.v5** adds explicit `numId` and `ilvl` as optional node metadata in the existing JSON. It leaves the compatibility text unchanged. Previously published v4 extractions remain usable; their list-like paragraphs remain paragraphs. Reprocessing through the normal pipeline creates a new revision with explicit list metadata. Source inspection itself never reprocesses documents.

## Payload

The response envelope remains `{ "data": ... }`. Field names follow the existing camelCase source/cell-window contracts. This shortened example uses a synthetic two-paragraph extracted text (`Termination` followed by the target paragraph). Its offsets and hashes are internally consistent; document, revision and anchor UUIDs are illustrative:

```json
{
  "data": {
    "type": "docx",
    "documentId": "22222222-2222-4222-8222-222222222222",
    "sourceId": "risk:33333333-3333-4333-8333-333333333333",
    "intelligenceRevisionId": "11111111-1111-4111-8111-111111111111",
    "sourceRevision": "84fb753c3c1ee8a21cfe8027206479b982da956b05dbcc370dfa2e9de567e1d0",
    "anchor": {
      "blockId": "body/p[2]",
      "blockType": "paragraph",
      "id": "44444444-4444-4444-8444-444444444444",
      "sourceHash": "7807b68dbb7e04a101160a66f19799885cb41568047d9e1a598b71e48dca25e2",
      "text": "thirty days written notice",
      "range": { "start": 64, "end": 90 },
      "quote": "thirty days written notice",
      "quoteRange": { "start": 64, "end": 90 }
    },
    "context": {
      "sectionHeading": {
        "id": "body/p[1]", "type": "heading", "text": "Termination",
        "range": { "start": 0, "end": 11 },
        "headingLevel": 1, "listId": null, "listLevel": null
      },
      "before": [],
      "target": {
        "id": "body/p[2]", "type": "paragraph",
        "text": "The employer may terminate this agreement by giving thirty days written notice.",
        "range": { "start": 12, "end": 91 },
        "headingLevel": null, "listId": null, "listLevel": null
      },
      "after": []
    },
    "table": null
  }
}
```

`anchor.sourceHash` is the persisted proof anchor's hash, **not a claim that every enclosing block has its own stored hash**. For a partial paragraph citation, the proof can be the raw text span while `blockId` identifies the enclosing persisted structural node. Both ranges are verified in the same immutable revision.

For tables, `table` contains `id`, `focusRowId`, and at most five ordered rows. Each row has `id`, zero-based `index`, `header`, `cited`, and `cells`. Cells carry the block fields plus `column`, `columnSpan`, `verticalMerge`, and `cited`. A cell with no stored projection has `text: ""` and `range: null` and is labeled “Text not recorded” or “Merged cell continuation”; it is not silently represented as verified blank content.

## Bounds, errors and performance

- Up to three nearby blocks before and after the target; the nearest section heading is separate. The preceding section heading is not duplicated among neighbors. Body windows stop at tables; list windows stop at a different list or a non-list block.
- Table windows contain the cited row, one row on either side, and up to two explicit preceding header rows. At most 16 columns/cells per row.
- At most 4,096 Unicode characters per returned block/quote, 64 KiB encoded response, 8 MiB stored node JSON, 4 KiB capabilities JSON and 100,000 nodes. Exceeding the budget returns `422 SOURCE_TOO_COMPLEX` instead of truncating evidence silently.
- `409 SOURCE_UNAVAILABLE` for missing/corrupt anchors, malformed locators, projection/hash mismatches, absent pinned extraction, file mismatch and unsupported structural sources. Existing `SOURCE_AMBIGUOUS`, `SOURCE_NOT_READY` and `STALE_SOURCE_REFERENCE` semantics are retained. A wrong expected revision uses `409 STALE_SOURCE_REFERENCE`; a missing/malformed expected UUID uses `422 INVALID_SOURCE_REVISION`.
- Document policy, authentication, 403/404 isolation and the existing 60/minute route throttle remain in force.
- Requests decode bounded persisted JSON; they do not open the DOCX ZIP or parse XML. The browser receives only the window. No AI calls or processing jobs are triggered.

## UX and limitations

Paragraph citations have quiet neighboring text, a section label, a left rule and exact Unicode-aware quote emphasis. Heading citations use heading markup. Explicit list items retain source order and nesting level in semantic lists; Word's bullet glyphs, numbering starts/restarts and inherited style numbering are not reconstructed, and the UI says markers were not recorded. Tables use a small real HTML table with explicit column headers and a distinct cited cell/row. The viewer is keyboard-scrollable and has loading, safe failure, permission, stale revision, retry and unsupported-view fallback states. Late responses cannot replace a newly selected source.

There is no Word pagination, editing, layout fidelity, font/color reproduction, images, comments, headers/footers, floating objects or tracked-change visualization. Deleted text remains excluded by the existing extractor. Footnotes are stored but this viewer does not open standalone footnote citations. Unsupported wrapper constructs without a stored body projection and nested tables in the selected table window fail safely. Horizontal grid spans are retained; vertical merges are described, not laid out as Word row spans. Very long blocks and large stored structures are refused within the budgets above.

The general DOCX capability model does not promise an exact/approximate highlight or zoom based solely on a source path. Only a successfully validated DOCX window displays the highlight. PDF and image implementations are unchanged; XLSX implementation is unchanged apart from restoring dispatch and its existing full-workbook label.
