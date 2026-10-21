# Contract — image source regions

`GET /api/documents/{document}/sources/{sourceId}`

The existing source-reference route (`DocumentSourceReferenceController` → `SourceReferenceResolver`).
**This adds two fields to that response.** No route, no parameter and no existing field changed, so a
client that does not know about these fields behaves exactly as before.

## The two fields

```ts
data.textLocated: boolean
data.regions: Array<{ x: number, y: number, width: number, height: number }>
```

| Field | Meaning |
|---|---|
| `data.textLocated` | `true` only when stored OCR word boxes back **this** citation. Never inferred from `matchState`. |
| `data.regions` | Highlight rectangles, normalised `0..1`. Empty whenever `textLocated` is `false`. |

Both fields are always present, for every document type. They are only ever non-trivial for a `JPG`
or `PNG` source: for anything else `textLocated` is `false` and `regions` is `[]`.

### The orientation rule

**`regions` are relative to the image *after* EXIF orientation is applied** — the image as a browser
draws it, not as the camera stored it. A client renders the image normally (browsers auto-orient) and
multiplies each region by the rendered width and height. No client-side EXIF handling is needed, and
none should be attempted.

`ImageOrienter` applies the orientation before anything measures the image, so the stored
`geometry.rotation` of `0` is a statement of fact rather than a placeholder. The applied orientation
is kept on each anchor as `geometry.exif_orientation` for audit.

### Coordinates

`x`, `y` are the top-left corner; origin is the image's top-left. All four numbers are clamped to
`0..1` and rounded to 6 decimal places. One rectangle is emitted **per text line**, not per word: a
box per word reads as a redaction, and one box spanning two lines would cover text that was never
cited.

## When `textLocated` is true

All of the following must hold. Any one of them failing gives `textLocated: false`, `regions: []`.

1. The document is `JPG` or `PNG`.
2. The citation has a quote.
3. Word boxes are stored for the document's **current** `extracted_text` (the boxes are keyed by its
   SHA-256, so re-extraction retires the previous reading rather than letting it answer for text it
   never saw).
4. The quote matches **one** run of consecutive stored words, in order, whole words only. Matching
   folds case, punctuation and whitespace and allows a short edit distance per word (0 for words of
   ≤4 characters, 1 for ≤7, 2 above), because the quote comes from the `claude_vision` reading and
   the boxes come from tesseract's independent reading of the same pixels. **Words containing digits
   must match exactly** — `12.4` and `12.9` are different figures, not an OCR slip.
5. The quote is at least `ocr.image_location.min_matched_words` words long (default **2**). A
   one-word highlight on a photograph is too weak to trust; set the value to `1` to allow a uniquely
   matched single word.
6. The match is **unambiguous**. Two candidate runs mean the phrase occurs more than once on the
   image, and marking either would pick an occurrence the reader did not ask for — so neither is
   marked.

Word boxes below `ocr.image_location.min_word_confidence` (default **60**, tesseract's 0–100 scale)
are dropped before storage, so a low-confidence reading can never back a highlight.

## What these fields do *not* change

`matchState`, `capability`, `quality.*` and every other field keep their existing meaning. A located
highlight does **not** upgrade a claim about the citation: an image citation stays `capability:
TEXTUAL` with `quality.ocrUncertainty: "unverified_reading_order_and_glyphs"`. This contract adds a
place to draw a box; it does not add confidence.

## The optional stage behind it

Boxes are produced by the `image_text_location` processing stage (`LocateImageTextJob`), which is
**optional by construction**:

- It is in **no** required-stage list — not `DocumentRevisionPublisher::publish()`'s `$required`, not
  `normalStagesComplete()`'s. It can never hold a revision back or block publication.
- It records `completed` (with a reason) or `skipped`, **never `failed`**.
  `DocumentIntelligenceService::getDocumentFailure()` reports the latest failed row outside its own
  stage list as the document's failure stage, so a failed row here would make an unrelated document
  failure claim it was caused by OCR.
- `handle()` cannot throw, so there is no retry and no `failed_jobs` entry.
- It touches no credit or entitlement service. **A missing binary, a timeout or a failed reading
  charges exactly what a successful one charges: nothing.**
- It is dispatched outside the intelligence batch, so it cannot cancel, delay or fail a sibling
  stage.
- It is revision-scoped: the `processing_jobs` row is pinned to the revision whose text the boxes
  locate, and `SkipsUnchangedDocuments` is deliberately not used — an unchanged `file_hash` is no
  reason to skip, because the boxes belong to the revision's text, not to the file.

If tesseract is missing, fails or times out, the document still extracts, analyses and publishes
normally; `textLocated` simply stays `false`. A warning carrying the document id and the reason is
logged (`Image word-box location unavailable`).

## Config

See `config/ocr.php` → `image_location`, and `docs/IMAGE_TEXT_LOCATION.md` for the data-flow note and
the image-size impact.

| Key | Env | Default |
|---|---|---|
| `enabled` | `OCR_IMAGE_LOCATION_ENABLED` | `true` (kill switch) |
| `min_word_confidence` | `OCR_IMAGE_LOCATION_MIN_CONFIDENCE` | `60` |
| `max_dimension` | `OCR_IMAGE_LOCATION_MAX_DIMENSION` | `4000` |
| `timeout_seconds` | `OCR_IMAGE_LOCATION_TIMEOUT` | `45` |
| `languages` | `OCR_IMAGE_LOCATION_LANGUAGES` | `eng+swa` |
| `page_segmentation_mode` | `OCR_IMAGE_LOCATION_PSM` | `3` |
| `max_words` | `OCR_IMAGE_LOCATION_MAX_WORDS` | `5000` |
| `min_matched_words` | `OCR_IMAGE_LOCATION_MIN_MATCHED_WORDS` | `2` |

## Example

```json
{
  "data": {
    "state": "CURRENT",
    "matchState": "EXACT",
    "capability": "TEXTUAL",
    "textLocated": true,
    "regions": [
      { "x": 0.16, "y": 0.04, "width": 0.24, "height": 0.08 },
      { "x": 0.16, "y": 0.2,  "width": 0.14, "height": 0.08 }
    ],
    "quality": { "ocrUncertainty": "unverified_reading_order_and_glyphs" }
  }
}
```

Tests: `tests/Feature/ImageSourceContractFieldsTest.php` (field shape, no OCR),
`tests/Feature/ImageSourceRegionApiTest.php` (located, not located, retired by re-extraction),
`tests/Unit/ImageRegionLocatorTest.php` (matching and merging).
