# Scanned PDF text location

**Status:** implemented on `feat/scanned-pdf-text-location`. Not deployed, not executed anywhere.

Extends `docs/IMAGE_TEXT_LOCATION.md` to the pages of a PDF that have no usable text layer, so a
citation on a scanned page can be highlighted in the source viewer. **Text extraction does not change:
it stays on `claude_vision`. This stage writes geometry only.**

## Investigation (how the image feature works, and what a PDF citation returned)

| Question | Answer |
|---|---|
| Where is the stage? | `LocateImageTextJob` → `ImageTextLocator` → `TesseractWordBoxRunner`, dispatched from `OcrPageBatchJob::dispatchImageTextLocation()` after `extracted_text` is committed. |
| How are anchors stored? | `source_anchors` rows, `kind='ocr_region'`, `modality='ocr'`, pixel `geometry` (`system=image_pixels_top_left`, `box`, `width`, `height`, `rotation`, `line`), in their own `source_provenance_sets` row (`pipeline_key='image_text_location'`) via `ImageWordBoxStore`. Offsets are NULL. The set is tied to `sha256(extracted_text)`. |
| How does a citation become regions? | `SourceReferenceResolver::imageRegions()` → `ImageRegionLocator::locate()`: tolerant whole-word match of the quote against stored words, one unambiguous run, merged to one box per line, normalised to 0..1. |
| What did a PDF citation return? | `textLocated:false, regions:[]` always: `imageRegions()` returned `[]` for any type except JPG/PNG. `source.page` was already present. |
| Which PDF pages are scans? | The pages `OcrPageBatchJob` sent to OCR: every page of an image-only PDF, or the `empty_text_pages` of a hybrid PDF. Their `ocr_results.page_number` values are passed to the new job. |

## What was added

- `LocatePdfTextJob` (default queue, `$tries=1`, `$timeout=150`): same contract as the image job and recorded
  under the same optional stage name `image_text_location` (never required, never `failed`, charges nothing).
- `PdfTextLocator`: for each OCR page (lowest first, capped), `PdfPageRenderer` (`pdftoppm -f N -l N -singlefile`)
  renders it, the existing `WordBoxRunner` (tesseract TSV) reads it, words below
  `ocr.image_location.min_word_confidence` are dropped, and `ImageWordBoxStore::storePages()` writes every
  page's words in one set with `source_anchors.page = N` and geometry measured against that rendered page.
  Rendered PNGs and the temporary PDF copy are deleted in `finally`. A page that fails is logged and skipped;
  the others continue. Nothing is thrown out of the job.
- `SourceReferenceResolver::imageRegions()` now also handles `PDF`: it needs the cited page (`sources[0].page`),
  reads only that page's words, and reuses `ImageRegionLocator` unchanged. A page with a text layer has no
  anchors, so it returns `[]` exactly as before.
- `QueueTopology`: `LocatePdfTextJob` routed to `default`.

## API (no new contract)

`GET /api/documents/{document}/sources/{sourceId}` already carries `data.textLocated`, `data.regions`
(`{x,y,width,height}`, 0..1, top-left origin) and `data.source.page`. For a PDF the regions are relative to
the **rendered page** of `data.source.page` (the page as pdf.js draws it, with its `/Rotate` and crop box
applied by poppler). No field was added.

## Config (`config/ocr.php` → `pdf_location`)

| Key | Env | Default |
|---|---|---|
| `enabled` | `OCR_PDF_LOCATION_ENABLED` | `true` (kill switch) |
| `max_pages` | `OCR_PDF_LOCATION_MAX_PAGES` | `10` |
| `dpi` | `OCR_PDF_LOCATION_DPI` | `200` |
| `page_timeout_seconds` | `OCR_PDF_LOCATION_PAGE_TIMEOUT` | `45` |
| `total_budget_seconds` | `OCR_PDF_LOCATION_TOTAL_BUDGET` | `120` (must stay below the job's 150s timeout) |

Languages, confidence floor, page segmentation mode and `min_matched_words` are shared with
`ocr.image_location.*`. (`.env.example` was not edited by this change; add the variables there if you list the
image ones.)

## Rollback

`OCR_PDF_LOCATION_ENABLED=false`: the job is neither dispatched nor run; stored boxes stay readable.

## Existing scanned PDFs: reprocess note

Existing documents have no anchors; boxes are created only when OCR finishes (`OcrPageBatchJob::finalizeDocument`).
To get them, the document must go through OCR again:

```
php artisan documents:reprocess 01a1686f-a7e7-73b5-a642-70deff081d61 --actor=<user-id>
```

Traced in code, not executed:

- **Status gate.** With `DOCINTEL_REVISION_V1` on, a `Ready` document may be reprocessed. With it off (the
  config default) the reprocessor refuses anything but `Failed` / `Needs Review`
  (`DocumentReprocessor.php`, "Only Failed or Needs Review documents can be reprocessed"), so a Ready scan
  cannot use this command. Whether production has the flag on was not checked.
- **It re-runs OCR.** Extracted text is cleared and the file is re-read by `claude_vision` (a new Anthropic
  cost to us), then insights are regenerated. The OCR text and the analysis can differ from the current ones.
- **Credits.** For a document already settled (`credit_accounted_at` set) the legacy accounting does **not**
  charge again: `reserveDocument` returns early and `accountForReadyDocument` returns early at publish. Under
  AI credits (`DOCINTEL_AI_CREDITS_ENABLED`, default off) the same document-level rule applies, **except** on
  the incremental route (large documents): `admitReanalysis` then quotes and reserves a paid re-analysis. A
  small scan like `scan_test_scanned.pdf` is expected to be on the simple route, but its `ai_pipeline.route`
  was not checked.
- **`documents:ocr-locate-images` does not cover PDFs.** There is no PDF backfill that avoids re-running
  extraction; adding one (render + tesseract only, no text change) is a possible follow-up.
