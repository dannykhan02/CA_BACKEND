# Original-document chart contract

## Data path and ownership

`GenerateInsightsJob` writes legacy AI-produced `document_charts` rows. Its output has no row-level source provenance. `AnalyzeEmbeddedVisualsJob` detects an image and records its page and temporary bytes in a `document_chunks` visual unit. `ProcessDocumentVisualJob` sends those bytes to the existing chart-vision operation, merges chart rows, and previously deleted the image. V2 generated analysis charts come from accepted evidence through `DocumentAnalysisComposer` and are a separate response (`analysis.visualAnalysis.charts`). The frontend must not treat legacy `document_charts` as source charts.

For newly processed visual units, the job now retains the exact input image under `chart-visuals/{workspace}/{document}/{revision-or-legacy}/{sha256}` on the private `documents` disk (Cloudflare R2 in production, not Railway container storage) and stores nullable `document_charts.source_metadata`. The path is internal and is never serialized. The metadata records detected page, image hash and media type, document file hash, pipeline key, axis meanings, common unit, basis, value status, and validated series. Existing rows receive `NULL`; there is no backfill or reprocessing.

## API: `GET /api/documents/{document}` and document report snapshot

Existing chart fields `id`, `type`, `title`, `description`, and `data` remain. Each chart now also includes:

| Field | Meaning |
| --- | --- |
| `sourceOrigin` | `document_visual` for new image-derived rows; `null` for historical rows of unknown origin. |
| `sourcePage` | Positive page detected from the PDF visual reference, or `null`. |
| `sourceImageUrl` | Relative authorized image endpoint only when the source path, revision, workspace, file hash, pipeline key, media type, and object existence check pass; otherwise `null`. |
| `sourceImageMediaType` | `image/png`, `image/jpeg`, `image/webp`, or `null`. |
| `basis` | Explicit `time_series`, `categorical`, `composition`, or `null`. |
| `axis` | Explicit `{x, y}` meanings, or `null`. |
| `valueFormat` | `{unit}` only when one common unit is explicit; otherwise `null`. |
| `series` | Validated named series with aligned labels and optional per-series `unit`, or `null`; legacy flat `data` remains available. |
| `valueStatus` | `exact` only for values the vision output says were printed legibly; `estimated` or `unavailable` otherwise. Historical rows use `unavailable`. |
| `valuesVerified` | True only when all supplied values are finite and the vision output reports exact printed labels while the input visual is retained. This is a source transcription claim, not independent human verification. |
| `dimensionsVerified` | True only with retained visual, explicit compatible basis, both axis meanings, common unit, valid series alignment, and compatible per-series units. |

The existing chart-vision call now requests these optional fields. It does not add a provider call. Incomplete output remains a table. The server does not infer semantics from a chart title, fill missing points, or infer a unit. A chart with different series units cannot be redrawn on one axis; its per-series units remain visible in tables and CSV. The frontend additionally requires a secure image reference and contiguous recognizable periods before drawing a source line. Generated analysis charts keep their existing evidence IDs and independent contract.

`GET /api/documents/{document}/charts/{chart}/image` requires normal document view authorization. It serves only a chart owned by the document's published revision (or a true legacy document), same tenant and file hash, same pipeline key, expected private storage path, and matching image SHA-256. Missing or stale references return 404. The URL is not a bearer token; clients must request it with their normal authorization header. Images use private, no-store responses.

## Workspace and Word export

The Workspace has Generated, Document, and All filters. Historical charts with unknown origin appear only in All and are labelled as such. The Workspace and Word builder both call `sourceChartRec` for the same redraw decision. Unsupported charts use the complete data table, including every series, plus original page or legacy limitation wording. The Workspace can fetch the original image with a bearer token and download it without recoloring.

## Rollout and limits

Apply the nullable migration **before deploying any new web or worker code**. Finish or gracefully drain in-flight visual jobs from the old worker, then deploy the web and worker from the same backend commit and restart Horizon. Verify both Railway services have the same `R2_ENDPOINT`, `R2_BUCKET`, `R2_ACCESS_KEY_ID`, and `R2_SECRET_ACCESS_KEY`, plus read/write access to the private bucket; their separate container volumes are not shared document storage. Deploy the frontend only after the backend image route and API fields are live. New visual jobs write optional metadata; old jobs and historical rows remain legacy without fabricated provenance. No automatic reprocessing is required or performed.

Soft deletion revokes API access while retaining source images under the existing document retention model. Model-level permanent deletion dispatches `PurgeDocumentChartImagesJob` after database commit; it removes only that document's `chart-visuals` prefix, refuses deletion while any retained chart row references that prefix, and retries transient object-store failures. The command and monitoring steps are in [Chart-image operations](CHART_IMAGE_OPERATIONS.md). Previously processed chart images were deleted, so their exact source image cannot be restored from historical rows. Legacy chart origin, axis, unit, and value certainty remain unknown. Printed-value exactness is reported by the existing vision model and remains subject to human review of the retained original image. A complete retention schedule for documents and all other derivatives remains separate work.
