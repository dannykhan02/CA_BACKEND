# Contract — XLSX cell window

`GET /api/documents/{document}/sources/{sourceId}/cell-window`

Route name `documents.sources.cell-window` (`routes/api.php:150`). **This documents the route as it
is.** It is not renamed, and it is not the `/sheets/{sheet}/window` shape an earlier plan sketched:
it was deliberately built on the existing `documents.sources.show` pattern, sharing its middleware
group, its policy check and its `throttle:60,1`.

Implemented by `DocumentSourceCellWindowController` → `SourceCellWindowReader` →
`XlsxCellWindowBuilder`.

## What it is for

The sheet viewer needs the cited cell **and its neighbourhood**, so a reader can see the row and
column labels that give the figure its meaning. It answers from the immutable structured extraction
pinned to the published intelligence revision. **It never opens the workbook file** — no
PhpSpreadsheet reader runs in the request, so there is no read filter to configure and no file I/O
to budget.

## Request

| | |
|---|---|
| Path `document` | the document's UUID; must be XLSX |
| Path `sourceId` | the citation's source id, e.g. `kpi:<uuid>`, URL-encoded |
| Query `expected_revision_id` | **required**, must be a UUID. The published intelligence revision the client resolved its citation against. |

There are no `cell`, `rows` or `cols` parameters. The cell comes from the citation's own anchor, and
the window size is fixed by the server (below), so a client cannot ask for a different cell than the
one it cited or widen the window.

Authentication: the same `auth:sanctum` group as the other document routes. Authorization: the
`view` ability on the document, checked twice — once on entry and again inside the transaction, on a
row read under a shared lock, so publication or ownership cannot change between the check and the
response.

## Caps, all server-fixed

| Cap | Value | Where |
|---|---|---|
| window radius | **3** cells each way → at most **7 rows × 7 columns** | `XlsxCellWindowBuilder::RADIUS` |
| response size | 65,536 bytes, checked twice (builder and reader) | `MAX_RESPONSE_BYTES` |
| one cell text field | 1,024 bytes | `MAX_FIELD_BYTES` |
| merged ranges reported | 16 | `MAX_MERGES` |
| stored extraction read | 8 MiB of `nodes` JSON, 4 KiB of `capabilities` | `SourceCellWindowReader` |
| node count | 100,000 | `SourceCellWindowReader` |

Exceeding a cap is a refusal, never a silent truncation of the data: `SOURCE_TOO_COMPLEX`. The one
exception is the window itself, which is clipped at the worksheet edges and says so in
`window.truncated`.

## Response `200`

`{ "data": { … } }` with:

| Field | Meaning |
|---|---|
| `documentId`, `sourceId`, `intelligenceRevisionId`, `sourceRevision` | server-verified identities; `sourceRevision` is the **extracted text's SHA-256** |
| `sheet.id` | the structural sheet id, `sheet:<n>` |
| `sheet.name` | the worksheet's name |
| `sheet.visibility` | the sheet's stored visibility (e.g. `visible`, `hidden`, `veryHidden`), or null |
| `sheet.dateSystem` | the workbook's date system, or null |
| `focus.coordinate`, `focus.row`, `focus.column` | the cited cell in A1 form and as 1-based indices |
| `window.startRow`, `endRow`, `startColumn`, `endColumn` | the inclusive bounds actually returned |
| `window.truncated` | `{top, bottom, left, right}` — true on an edge where the worksheet ran out before the radius did |
| `window.rows[]` | `{row, cells[]}`, every row in the bounds, in order |
| `mergedRanges[]` | merged ranges that touch the window, as `A1:B2` strings |
| `hiddenRows[]`, `hiddenColumns[]` | indices inside the window that are hidden or filtered out |
| `citation.matchState` | `EXACT` or `STRUCTURALLY_RESOLVED` |
| `citation.conflictStatus` | the stored conflict status, or null |
| `citation.resolution` | `EXACT_CELL_ANCHOR` or `OVERLAPPED_CELLS` — how the cell was reached (below) |
| `citation.coversMultipleCells` | true when the citation covers more than one cell |
| `citation.coveredCells[]` | every cell the citation covers, in document order, the focus first |

`coveredCells` is always populated, and on the anchor route it is the one focus cell. It is what a
reader may highlight. The **window** is centred on the first covered cell and is still radius 3, so
a citation wider than the window can name cells outside its bounds; a client compares each
coordinate with `window.startColumn`/`endColumn` and `startRow`/`endRow` rather than assuming
every covered cell is present in `window.rows`.

## How a citation is matched to a cell

Two routes, in this order. Both answer only from the extraction the revision pins.

**1. `EXACT_CELL_ANCHOR`.** A `cell`/`worksheet_cell` anchor of modality `xlsx`, reached as the
citation's own `location` or as one of its `value` role anchors, whose offsets are exactly the
cited range. `location` is only set when a cell anchor's `start_offset` and `end_offset` equal the
citation's (`SourceReferenceResolver.php:194-204`), so this route needs the quote and the cell to
be the same characters. The anchor's path, coordinate, range and sheet visibility are all checked
against the extraction node before anything is returned.

**2. `OVERLAPPED_CELLS`.** When no cell anchor owns the citation, the cited span is resolved to the
cells of one sheet that it covers **whole**, provided everything it covers outside them is
separator or whitespace characters (`StructuralSpanOverlap`, shared with the DOCX window).

This route exists because the model quotes what it was shown, and what it was shown is the
flattened projection: a row's cells joined with `" | "`, its rows with `"\n"`
(`SpreadsheetTextExtractor.php:159,180`). A quote of one cell in a four-column row therefore ends
`" |  |  |"`, eight characters past the cell, and a quote of one row carries the separators between
its cells. Those characters belong to the rendering, not to the document.

The window centre is the first covered cell; the rest are listed in `coveredCells`.

**Still `SOURCE_UNAVAILABLE`, deliberately:**

- no cell overlaps the cited span;
- one real character of document text — a letter, digit or punctuation, including the extraction's
  own `=== Sheet: x ===` line — sits inside the span but outside every covered cell;
- the span cuts a cell in half, so the citation is not a citation of that cell;
- the covered cells are not all on the same sheet (and not all children of the same worksheet node);
- the citation belongs to another revision, or any of the pinning checks below fail.

A citation covering more than 64 cells is `SOURCE_TOO_COMPLEX`, never a clipped list.

### A cell

A cell that exists in the extraction:

| Field | Meaning |
|---|---|
| `coordinate` | A1 form |
| `present` | `true` |
| `rawValue` | **the stored value**, as the file holds it |
| `displayValue` | **the formatted string**, as Excel would display it |
| `formula` | the formula **text**, or null |
| `cachedResult` | **the formula result the file had saved in it**, or null |
| `cachePresent` | whether the file carried a cached result at all — the honest way to read a null `cachedResult` |
| `numberFormat` | the stored number-format code |
| `cellType` | the stored cell type |
| `richText` | whether the stored value had rich-text runs |
| `rowVisible`, `rowVisibleAfterFilter`, `columnVisible` | stored visibility, or null when not recorded |
| `mergedRanges[]` | ranges this cell belongs to |

A coordinate with nothing stored is returned as `{"coordinate": "C7", "present": false}` and
**nothing else** — no empty string, no zero, and **no visibility claim**. "No cell was stored here"
and "a cell holding an empty string" are different facts and the response keeps them apart.

The 1,024-byte cap applies to **each returned scalar field** of a cell, not to the cell as a whole:
`rawValue`, `displayValue`, `formula`, `cachedResult` and `numberFormat` are each checked.

### The three values are three fields and are never merged

`rawValue`, `displayValue` and `cachedResult` are separate and a client must not fall back from one
to another silently. They disagree for ordinary, legitimate reasons: a date is a serial number
stored and a formatted date displayed; a percentage is `0.0725` stored and `7.25%` displayed; a
rounded display hides precision the stored value keeps.

**`cachedResult` is never recomputed.** It is whatever the file had saved, read through
PhpSpreadsheet's `getOldCalculatedValue()` at extraction time. `getCalculatedValue()`, which would
evaluate the formula, appears nowhere in this codebase — verified by grep over
`SpreadsheetTextExtractor` and `DocumentTextExtractor`. A workbook saved by a tool that writes no
cached values therefore yields `formula` text with `cachePresent: false` and a null `cachedResult`,
and the only honest thing to show is the formula and the absence of a result. **No cached result may
be presented as a fresh calculation.**

The fields are independent, so combinations a client might not expect are legitimate and must be
rendered rather than "fixed": `displayValue` can be null for a formula cell **even when
`cachedResult` is present**, because `display` comes from the cell's formatted value and
`cached_result` only from a formula cell's saved value
(`SpreadsheetTextExtractor.php:140,142-143`).

## Errors

Body is always `{"error": {"code": "<CODE>"}}`, except `404`, which is a bare abort.

| Status | Code | When |
|---|---|---|
| 401 | — | unauthenticated |
| 403 | — | the `view` policy denies the document |
| 404 | — | the `sourceId` does not resolve in the published view |
| 409 | `SOURCE_NOT_READY` | the document has no published intelligence revision |
| 409 | `STALE_SOURCE_REFERENCE` | `expected_revision_id` is not the published revision, or the resolved source belongs to another revision |
| 409 | `SOURCE_UNAVAILABLE` | the document is not XLSX; no published view; the revision row is missing or not `published`; or any of the reader's pinning checks fail (text hash, file hash, provenance policy, structured-extraction id, projection mismatch) |
| 409 | `SOURCE_AMBIGUOUS` | the citation names more than one cell *anchor*, or a coordinate appears twice in the extraction |
| 422 | `INVALID_SOURCE_REVISION` | `expected_revision_id` missing or not a UUID |
| 422 | `SOURCE_TOO_COMPLEX` | any cap above exceeded, a citation covering more than 64 cells, or the stored extraction is itself truncated (`nodes_truncated`, `cells` not `complete`) |
| 429 | — | `throttle:60,1` |

`SOURCE_TOO_COMPLEX` is 422 and the other domain refusals are 409 — that split is in the
controller's `refuse(...)` call, not incidental.

## What this endpoint never does

- It never opens the workbook, so it cannot see anything the extraction did not store. No
  processing job is dispatched and no outbound HTTP call is made — verified by grep for
  `dispatch`, `Http::`, `Bus::` and `Queue::` over the controller, reader and builder.
- It never recomputes a formula.
- It never merges the three values, and never substitutes one for another.
- It never widens the window on request.
- It never returns a cell from a sheet other than the cited one: a node whose `sheet` disagrees
  with the anchor's is a `SOURCE_UNAVAILABLE`, not a filtered-out row. On the overlap route the
  covered cells must themselves all be on one sheet.
- It never widens a citation to a cell it only partly covers, and never reads a cell's neighbour
  into the citation because the quote came close to it: the separators are the only thing it
  forgives, and `coveredCells` says exactly which cells it claims.
- **It never falls back to a newer extraction.** The row is fetched by
  `where('id', $revision->structured_extraction_id)` — the extraction the revision pins, by primary
  key — so an active extraction for a newer candidate is never substituted, and every identity
  (document, workspace, file hash, text hash, parser version, structure policy, format) must match
  as well.
- It never returns the original storage key or private path, never accepts an arbitrary sheet
  query, and never returns the whole workbook's cells.
