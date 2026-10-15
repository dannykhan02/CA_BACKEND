# Excel cited-cell window API

`GET /api/documents/{document}/sources/{sourceId}/cell-window?expected_revision_id={uuid}`

Requires the existing verified Sanctum session and document `view` policy. The
query parameter is required and must be a UUID from the published intelligence
response. The route is limited to 60 requests per minute.

The server compares that UUID with the document's current published pointer,
resolves `sourceId` using the existing source resolver, and reads the
`structured_extraction_id` pinned to that revision. The extraction must match
the document, workspace, original-file hash, extracted-text hash, parser and
structure policy. An active extraction for a newer candidate is never used as
a fallback. No workbook is opened or formula recalculated on this read path.

## Successful response

`200 { "data": { ... } }` contains:

| Field | Meaning |
| --- | --- |
| `documentId`, `sourceId`, `intelligenceRevisionId`, `sourceRevision` | Server-verified document/reference/revision identities; `sourceRevision` is the extracted-text SHA-256. |
| `sheet` | `id` (`sheet:N`), exact worksheet `name`, stored `visibility` and `dateSystem` when recorded. |
| `focus` | Verified cited `coordinate`, numeric `row` and one-based `column`. |
| `window` | Inclusive `startRow`, `endRow`, `startColumn`, `endColumn`, edge `truncated` flags and ordered `rows`. Each row contains ordered `cells`. |
| `mergedRanges`, `hiddenRows`, `hiddenColumns` | Merge ranges intersecting the window and hidden row/column indices observed in stored cell metadata. A blank cell has no visibility claim. |
| `citation` | Existing `matchState` and `conflictStatus`; a cell window does not resolve evidence conflicts. |

Each recorded cell has `coordinate`, `present: true`, `rawValue`,
`displayValue`, `formula`, `cachedResult`, `cachePresent`, `numberFormat`,
`cellType`, `richText`, `rowVisible`, `rowVisibleAfterFilter`,
`columnVisible` and `mergedRanges`. These are distinct stored fields.
`displayValue` can be null for a formula even when a cached result exists.
No cached result is described as a fresh calculation. A coordinate absent from
the extraction is `{ "coordinate": "D2", "present": false }`; the API does
not invent a value or visibility for it.

The window radius is three rows and columns (at most 7 × 7 cells), clipped at
Excel grid edges. A `truncated` flag describes that edge clipping. A source
whose structural nodes were truncated is refused instead of being displayed
as a complete window. Additional limits: 8 MiB of stored node JSON read,
4 KiB of capabilities JSON, 1,024 bytes per returned scalar field,
16 intersecting merge ranges and 64 KiB for the full JSON response.
Exceeding a limit is a typed refusal.

## Refusals

| HTTP | Code / reason |
| --- | --- |
| 401, 403, 404 | Existing authentication, document policy and missing document/reference behavior. |
| 409 | `SOURCE_NOT_READY`, `STALE_SOURCE_REFERENCE`, `SOURCE_AMBIGUOUS` or `SOURCE_UNAVAILABLE`. This includes non-XLSX documents, missing/invalid cited cells, identity mismatches and missing pinned extraction. |
| 422 | `INVALID_SOURCE_REVISION` for a missing/malformed expected UUID; `SOURCE_TOO_COMPLEX` for truncated or oversized structured data. |
| 429 | Existing route throttle. |

Typed refusals use `{ "error": { "code": "..." } }`. The response never
includes the original storage key, private path, arbitrary sheet query, or
the full workbook's cells.
