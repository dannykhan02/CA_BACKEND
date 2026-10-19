# Contract — DOCX structure window

`GET /api/documents/{document}/sources/{sourceId}/docx-window`

Route name `documents.sources.docx-window` (`routes/api.php:152`). **This documents the route as it
is.** It is not renamed, and it is not the `/structure/window` shape an earlier plan sketched: like
the cell window, it was built on the existing `documents.sources.show` pattern, sharing its
middleware group, its policy check and its `throttle:60,1`.

Implemented by `DocumentSourceDocxWindowController` → `SourceDocxWindowReader` →
`DocxWindowBuilder`.

## What it is for

The Word viewer shows **DocIntel's own rendering of the extracted text** — the cited block and a
few of its neighbours, as structured data the client renders with semantic elements. It answers
from the immutable structured extraction pinned to the published revision and the
`source_anchors` rows of that revision's provenance set. It never opens the `.docx` file.

## Request

| | |
|---|---|
| Path `document` | the document's UUID; must be DOCX |
| Path `sourceId` | the citation's source id, URL-encoded |
| Query `expected_revision_id` | **required**, must be a UUID |

There are no `path`, `before` or `after` parameters. The target block is derived from the citation's
own character range, and the neighbour count is server-fixed, so a client cannot walk the document
by asking for arbitrary paths.

Authentication and authorization are as the cell window: `auth:sanctum`, the `view` ability checked
twice, the second time on a row read under a shared lock inside the transaction.

## Caps, all server-fixed

| Cap | Value | Where |
|---|---|---|
| neighbours | **3** blocks before and 3 after | `DocxWindowBuilder::RADIUS` |
| one block's text | 4,096 code points | `MAX_BLOCK_CHARS` |
| table columns | 16 | `MAX_COLUMNS` |
| cited range length | 4,096 code points | `SourceDocxWindowReader` |
| response size | 65,536 bytes | `MAX_RESPONSE_BYTES` |
| stored extraction read | 8 MiB of `nodes` JSON, 4 KiB of `capabilities` | `SourceDocxWindowReader` |
| node count | 100,000 | `SourceDocxWindowReader` |

## Offsets are **code points**, not UTF-16 units

Every `start` and `end` in this response counts **Unicode code points** from the beginning of the
revision's extracted text. The server slices with `mb_substr`, which is code-point based, and
verifies each returned block by re-slicing the text and comparing it to the stored projection — so
a mismatched unit would make the endpoint refuse, not mis-highlight.

This matters for any character outside the BMP. An emoji or an astral CJK character is **one** code
point here and **two** UTF-16 code units in a JavaScript string, so a client must not index
`string[i]`. The frontend slices with `Array.from(text)`, which iterates code points and therefore
agrees. `tests/Feature/DocumentSourceDocxWindowTest.php` carries 😀 inside its fixture paragraphs
for exactly this reason, and the partial-quote test marks a range after it.

## Response `200`

`{ "data": { … } }` with:

| Field | Meaning |
|---|---|
| `type` | `"docx"` |
| `documentId`, `sourceId`, `intelligenceRevisionId`, `sourceRevision` | what was read and what it is pinned to |
| `excluded[]` | what this rendering leaves out — see below |
| `anchor.blockId` | the structural path of the cited block, e.g. `body/p[7]` or `body/tbl[1]/tr[2]/tc[2]` |
| `anchor.blockType` | the block's kind, including `table_row` for a row-spanning citation |
| `anchor.id`, `anchor.sourceHash`, `anchor.text`, `anchor.range` | the verified provenance anchor that proves this block, its SHA-256 and its own range |

`anchor.sourceHash` is **the persisted proof anchor's hash, not a claim that every enclosing block
has its own stored hash.** For a partial paragraph citation the proof can be the raw text span
while `blockId` identifies the enclosing persisted structural node; both ranges are verified
against the same immutable revision.
| `anchor.quote` | the cited text, re-sliced from the revision's extracted text |
| `anchor.quoteRange` | `{start, end}` of the citation, in code points |
| `context.sectionHeading` | the enclosing heading block, or null |
| `context.before[]`, `context.after[]` | up to 3 blocks each side, in document order |
| `context.target` | the cited block |
| `table` | for a citation inside a table: `{id, focusRowId, rows[]}`; otherwise null |

### A block

`{id, type, text, range: {start, end}, headingLevel, listId, listLevel}`.

`type` is one of `paragraph`, `heading`, `list_item`, `cell`, `table_row`, `table`.
`headingLevel` is 1-6 for a heading and null otherwise. `listId` and `listLevel` are the stored
numbering identity; **the marker Word displayed is not stored and is never returned.**

A table row carries `{id, index, header, cited, cells[]}` with a **zero-based** `index`, and each
cell adds `column`, `columnSpan`, `verticalMerge` and `cited`. A nested table is refused rather than
flattened into its parent cell.

A cell with no stored projection is `text: ""` with `range: null`. It is **not** silently presented
as verified blank content: the client labels it "Text not recorded", or "Merged cell continuation"
when `verticalMerge` is `continue` (`DocxAdapter.tsx:30`). Horizontal grid spans are carried in
`columnSpan`; vertical merges are **described, not laid out** as Word row spans.

### How the window is chosen

| | |
|---|---|
| body paragraph | up to 3 blocks before and after. The nearest section heading is returned separately in `sectionHeading` and is **never duplicated** among the neighbours — the backward walk stops when it reaches it. |
| a list item | the walk stops at a different list or at a non-list block, so a list window never mixes two lists. |
| any body window | stops at a table rather than walking into one. |
| a table | the cited row, one row either side, and up to **two** explicit preceding header rows — **at most 5 rows** in total — and at most 16 cells per row. |

A block with no `text` is skipped rather than returned empty, so an empty paragraph between two
real ones does not consume one of the three neighbour slots.

### `excluded[]`

Stable snake_case keys; the client supplies the wording. Present values, each verified against
`DocumentTextExtractor` rather than assumed — only `word/document.xml` and `word/footnotes.xml` are
ever opened:

| Key | Why it is excluded |
|---|---|
| `headers_and_footers` | those parts are never read; the extraction's capabilities say `unavailable` |
| `comments` | `word/comments.xml` is never opened |
| `endnotes` | `word/endnotes.xml` is never opened |
| `images` | only `w:t` text is kept, so a drawing contributes nothing — not even the alt text in `wp:docPr/@descr` |
| `field_codes` | `w:instrText` is dropped by name |
| `tracked_change_deletions` | `w:del` and `w:moveFrom` are dropped by name |
| `list_markers` | numbering is stored as id and level, never as the rendered marker |
| `footnote_text` | footnote bodies are extracted as their own `footnote:<id>` nodes, but this window walks body children only. Reported **only when the document actually has footnotes.** |

**`text_box_text` is not a key, and must never be added without re-measuring.** A text box lives
inside a body run, and the extractor returns the text of every descendant `w:t`, so a text box's
words *are* extracted into the containing paragraph and shown. Listing them would tell a reader
part of their document is missing while it is on screen.

`headers_and_footers`, `tracked_change_deletions` and `list_markers` are derived from the
extraction's own capabilities map, so a later parser that starts reading headers, or stops
discarding deletions, stops claiming to exclude them automatically. The rest are facts about the
reader for this parser version. Proof for each entry:
`tests/Unit/Services/DocxExcludedContentTest.php`.

The field is **additive** — it was inserted beside the existing top-level keys and nothing moved.
A client that does not know it is unaffected, which
`test_existing_response_fields_are_unchanged_by_the_additive_excluded_list` pins.

## Parser versions and compatibility

The current parser is **`wordprocessingml-body.v5`**
(`DocumentTextExtractor.php:436`). v5 added explicit `numId` and `ilvl` as optional node metadata
inside the existing JSON and left the compatibility text unchanged.

**Previously published v4 extractions remain usable.** Their list-like paragraphs stay
`paragraph` rather than becoming `list_item`, because the numbering metadata was never stored for
them — pagination, layout, direct numbering ownership and list indentation were not preserved by
v4. Reprocessing through the normal pipeline creates a new revision with the explicit list
metadata; **source inspection itself never reprocesses a document.** Pinned by
`test_older_extraction_without_list_metadata_still_supports_paragraphs`
(`tests/Feature/DocumentSourceDocxWindowTest.php`).

Footnote bodies are stored as their own `footnote:<id>` nodes, but **this viewer does not open a
standalone footnote citation** — the window walks body children only, which is why
`footnote_text` appears in `excluded`.

## Never returned, under any circumstances

- **No page number.** Word has no pagination until something renders it, and a rendered page is not
  the author's page. `w:lastRenderedPageBreak` is not read, no page field exists in this response,
  and no code path computes one.
- **No HTML and no raw XML.** Every text field is plain text sliced from the extracted text and
  verified against the stored projection. The client renders it as text nodes.
- **No conversion.** No LibreOffice, no `.docx`→HTML step; the rendering is DocIntel's own and the
  client must say so.
- **No file access and no work triggered.** The request decodes bounded persisted JSON; it never
  opens the DOCX ZIP or parses XML, dispatches no processing job and makes no outbound HTTP call —
  verified by grep for `dispatch`, `Http::`, `Bus::` and `Queue::` over the controller, reader and
  builder. The browser receives only the window.
- **No layout fidelity of any kind:** no pagination, no fonts or colours, no images, no comments,
  no headers or footers, no floating objects, no tracked-change visualisation, and no
  reconstruction of Word's bullet glyphs, numbering starts or inherited style numbering. Explicit
  list items keep source order and nesting level only, and the client says the markers were not
  recorded.

## Errors

Body is `{"error": {"code": "<CODE>"}}`, except `404`.

| Status | Code | When |
|---|---|---|
| 401 | — | unauthenticated |
| 403 | — | the `view` policy denies the document |
| 404 | — | the `sourceId` does not resolve in the published view |
| 409 | `SOURCE_NOT_READY` | no published intelligence revision |
| 409 | `STALE_SOURCE_REFERENCE` | `expected_revision_id` is not the published revision, or the resolved source belongs to another revision |
| 409 | `SOURCE_UNAVAILABLE` | not DOCX; no published view; revision row missing or not `published`; the citation's range or preview does not re-slice to the stored text; no provenance anchor proves the block; or any pinning check fails |
| 409 | `SOURCE_AMBIGUOUS` | two equally exact enclosing blocks, or an ambiguous match state |
| 422 | `INVALID_SOURCE_REVISION` | `expected_revision_id` missing or not a UUID |
| 422 | `SOURCE_TOO_COMPLEX` | any cap above exceeded, or the stored extraction is truncated (`body_order` not `complete`, `nodes_truncated`) |
| 429 | — | `throttle:60,1` |

## Verification status

Read and written against the code on `origin/main` at the time of this branch. **The backend test
suite is UNVERIFIED locally** — Postgres does not start on the development WSL, so
`DocumentSourceDocxWindowTest` and `DocxExcludedContentTest` run on GitHub Actions only. `php -l`
and `vendor/bin/pint --test` pass on every file this branch touches.
