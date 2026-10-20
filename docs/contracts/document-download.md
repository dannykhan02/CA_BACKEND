# Contract: `GET /api/documents/{document}/download`

Streams a document's original file. `DocumentDownloadController::show`.

Success is a file stream with `Content-Disposition` naming the stored display name, not the
UUID-on-disk key (`DocumentDownloadFileNameTest` pins the extension rules).

## Errors

| Status | Code | When |
|---|---|---|
| 401 | — | unauthenticated |
| 403 | — | the `view` policy denies the document |
| 404 | — | the id does not resolve, **or** a key is recorded and the object is missing on the `documents` disk |
| 404 | `NO_ORIGINAL_STORED` | no key is recorded on the row — no original file was ever stored |
| 409 | — | `status` is not `Ready` or `Needs Review`: not downloadable **yet** |

The `NO_ORIGINAL_STORED` body carries both envelopes used on this API:

```json
{
  "success": false,
  "message": "No original file is stored for this document.",
  "errors": [],
  "error": { "code": "NO_ORIGINAL_STORED" }
}
```

Three things about the error table are deliberate:

- **It is not 409.** 409 on this endpoint already means *not downloadable yet* — a state that clears
  itself when processing or approval finishes. A row with no original never becomes downloadable, so
  wording it as 409 would tell a client to retry something that cannot succeed. Both frontend callers
  of this endpoint already word it that way:
  `frontend/CA/src/components/workspace/pdfRuntime.ts:302` — *"The original document is not ready for
  download. Try again after processing finishes."* — and
  `imageSource.ts:46` — *"This document is not ready to display."*
- **The two 404s are told apart by `error.code`, not by status or message.** A bare 404 here carries
  `"message": "Resource not found."`: `abort(404, 'The underlying file could not be found.')` throws
  a `NotFoundHttpException`, and the handler for it in `bootstrap/app.php` hardcodes that message and
  discards the one passed in. So an unknown id and a missing object are already indistinguishable,
  and presence of `error.code` is the only available discriminator. **Treat an `error.code`-less 404
  as possibly a storage anomaly, never as "this document has no file".**
- **The status gate runs first.** A document that is both not-`Ready` and has no key answers 409, not
  `NO_ORIGINAL_STORED`, because the status is the more actionable fact.

## Frontend: `NO_ORIGINAL_STORED` must be checked before the generic 404 message

Both callers also already word a plain 404 as a storage problem worth escalating:

- `pdfRuntime.ts:301` — *"The original document file could not be found. The source reference may
  still be valid, but the file is unavailable. Contact your workspace administrator."*
- `imageSource.ts:44` — *"The original image could not be found."*

That is the right message for the anomaly and the **wrong** message for a seeded row that never had
a file: it tells a reader to escalate a non-problem. So a client must branch on `error.code` before
falling through to its 404 message, and prefer `hasOriginal` to not offer the download at all. The
backend guard alone stops the 500; it does not by itself make the message honest.

## `hasOriginal`

`DocumentResource` and `DocumentListResource` expose `hasOriginal: bool`.

It means **a storage key is recorded on the row** — nothing more. It is `Document::hasRecordedOriginal()`,
which is `is_string($file_path) && trim($file_path) !== ''`: the same method the download guard and
`documents:audit-originals` use, so the three cannot disagree about which documents have an original.

**It does not mean the bytes resolve.** A recorded key whose object is missing still reports `true`,
on purpose: that is a storage anomaly for `documents:audit-originals` and alerting to surface, and
collapsing it into `hasOriginal: false` would hide it behind a benign UI state.

The resources never call the disk to answer it. One `exists()` per row would be an N+1 of round trips
against R2 on every render of the document list; `DocumentHasOriginalFieldTest` puts a spy in place of
the `documents` disk and fails if listing documents touches storage at all.

**Use it to not offer a download** for a document that never had a file, rather than sending a reader
into an error. It is not a promise that a download will succeed — handle the 404s above regardless.
