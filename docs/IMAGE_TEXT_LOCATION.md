# Image text location (JPG/PNG word boxes)

**Status:** implemented 2026-10-21 on `feat/image-text-location`. Not deployed.

## The one sentence that matters

**Text extraction for images has not changed and does not change: it stays on `claude_vision`.**
Tesseract runs locally, inside our own container, purely to say *where on the image* a word sits.
It is geometry, not a reading.

## Why it exists

An image citation could not be highlighted. `SourceReferenceResolver` projected geometry only for
`cell` / `worksheet_cell` anchors, so a JPG/PNG source returned text offsets and nothing a viewer
could draw. Meanwhile the word-box machinery already existed but was dead in production:
`TesseractOcrProvider::parseTsv()` produced regions, and
`TextRoleProvenanceProducer::ocrWordRegions()` stored them — but only when
`metadata.source === 'tesseract'`, and the default OCR provider is `claude_vision`, so that branch
never ran. On top of that, nothing in the pipeline normalised EXIF orientation, and the stored
geometry hardcoded `rotation => 0`, so any box from a rotated phone photo would have been wrong
against what a browser renders.

## Data flow — no new third party

| Step | Where it runs | Leaves our infrastructure? |
|---|---|---|
| Text extraction (`OcrPageBatchJob` → `ClaudeVisionOcrProvider`) | Anthropic API | Yes — **unchanged, as before this work** |
| EXIF orientation + downscale (`ImageOrienter`, PHP GD) | our container | No |
| Word boxes (`TesseractWordBoxRunner`, local `tesseract`) | our container | **No** |
| Box storage (`ImageWordBoxStore` → `source_anchors`) | our Postgres | No |
| Quote → regions (`ImageRegionLocator`) | our container, per request | No |

**Tesseract adds no new data flow and no new vendor.** The image bytes it reads are already on the
worker's disk, having been fetched from our own object storage for extraction. Nothing is sent
anywhere. This is the reason the hybrid design was chosen over replacing the extraction provider.

## Where the boxes live

`source_anchors` rows with `kind = 'ocr_region'`, `modality = 'ocr'`, `page = 1`, and pixel
`geometry` — the kinds the schema already allowed. They sit in their **own**
`source_provenance_sets` row under the reserved `pipeline_key = 'image_text_location'`, *not* in the
analysis set, because:

- `SourceProvenance::publish()` deactivates whatever else is active for the document's pipeline key.
  A second publish under the analysis key would switch the analysis set off — exactly the "citations
  silently stop resolving" failure this must not be able to cause. `source_provenance_one_active` is
  scoped to `(document_id, pipeline_key)`, so the two sets coexist and neither can deactivate the
  other.
- `publish()` also requires `ownsPipeline()`, i.e. a live candidate revision. Location is optional
  and may legitimately finish after the revision has published.

`start_offset` / `end_offset` are deliberately **NULL**. Tesseract's reading is a second,
independent reading of the image; it is not an offset into the `claude_vision` text the citations
quote. Claiming an offset would assert a correspondence nothing verified — `ImageRegionLocator`
establishes that correspondence per lookup instead, and tolerates the two readings disagreeing.

The set's `source_revision` is the SHA-256 of `extracted_text`, so re-extraction retires the old
reading rather than letting it answer for text it never saw.

## Optional stage

`image_text_location` (`LocateImageTextJob`, `default` queue). The full list of guarantees —
no required-stage membership, never a `failed` row, never throws, charges nothing, revision-scoped —
is in `docs/contracts/image-source-regions.md`. **An OCR failure must never fail a revision or
change charging, and the tests assert it.**

Dispatched from `OcrPageBatchJob::finalizeDocument()` right after `extracted_text` is committed, so
the text whose quotes the boxes must locate is final.

## Container image

CI verifies this rather than trusting it: `docker-build-check` now runs `tesseract --list-langs`
inside the built image and fails if `eng` or `swa` is missing, or if `exif`/`gd` are not loaded.

`Dockerfile` (the single image serving both `CA_BACKEND` and `ca-horizon-worker`, which differ only
by the role argument to `bin/start-production.sh`) gains:

- `tesseract-ocr`, `tesseract-ocr-eng`, `tesseract-ocr-swa` — English and Kiswahili for Kenyan
  documents. `eng` and `osd` are already dependencies of `tesseract-ocr`; `eng` is listed explicitly
  anyway so the requirement is stated rather than inherited.
- the `exif` PHP extension, so `ImageOrienter` can read the Orientation tag. Without it orientation
  silently degrades to "upright", which is why it is installed rather than assumed.

### Image size impact

**Estimated +55 to +70 MB installed.** From Debian trixie package metadata (amd64), the direct
packages are small and one transitive dependency dominates:

| Package | Installed |
|---|---|
| `tesseract-ocr` 5.5.0-1+b1 | 2.3 MB |
| `tesseract-ocr-eng` | 4.0 MB |
| `tesseract-ocr-swa` | 2.1 MB |
| `libicu76` (pulled by `tesseract-ocr`) | **37.4 MB** |
| `libtesseract5`, `libleptonica6`, `tesseract-ocr-osd`, cairo/pango/harfbuzz/fontconfig/archive | ~10–20 MB, partly already present |
| `exif` PHP extension | negligible |

`libicu76` is more than half the cost and is a hard dependency of `tesseract-ocr`, so it cannot be
trimmed without dropping the engine.

**This is an estimate from package metadata, not a measured build** — Docker was unavailable on the
machine this was written on. The `docker-build-check` CI job now prints the built image's real size
("built image size: … MB"), so compare the first run on this branch against a run on `main` for the
true delta.

Note the engine version differs between environments: trixie ships **tesseract 5.5.0**, while a
developer machine may have 4.1.1. The TSV contract this code parses (level 5 rows, `conf`, `text`,
`left`/`top`/`width`/`height`) and the `--psm` / `-l` flags are the same in both, and
`ImageTextLocatorRealBinaryTest` exercises whichever is installed.

## Backfill

```
php artisan documents:ocr-locate-images            # dry run — runs OCR, writes nothing
php artisan documents:ocr-locate-images --force --limit=25
```

Dry run is the **default**; `--force` is required to write. Options: `--limit`, `--timeout`
(per document), `--document`, `--workspace`, `--refresh` (re-read images that already have current
boxes). It only ever writes word boxes — it cannot change extracted text, analysis, document status
or credits.

**Not run anywhere yet, including production.** It reads every selected image off object storage and
runs OCR, so pick a small `--limit` and watch the first batch.

## Rollback

`OCR_IMAGE_LOCATION_ENABLED=false`. The stage stops being dispatched and stops running; already
stored boxes stay readable. No deploy needed. Removing the packages from the image is a separate,
unrelated step.

## Tesseract as an extraction provider

`TesseractOcrProvider::supports()` was an unconditional `true` and now reports whether the binary is
actually callable. One behavioural consequence worth knowing: a workspace that has explicitly set
`workspace_settings.ocr_provider = 'tesseract'` previously got a **failed document** (the provider
claimed support, then threw because the binary was absent) and will now actually get tesseract
extraction. No workspace has that setting today — the column is nullable with no default — so for
every current workspace `claude_vision` remains the extraction provider, unchanged.
