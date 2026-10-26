# Kisiwa payment chart: read-only investigation

The four-page **Kisiwa Ridge Development — 2026 Operations Report** and its production analysis are not in this checkout. Railway CLI returned `Unauthorized` here, so the missing payment chart has no confirmed production root cause.

The current legacy insights prompt asks for **at most three charts** (`document_processing.insights_max_charts`, default 3). The job saves every chart returned for pre-v10 responses without slicing the array. The published document relation and chart resource have no three-chart cutoff. The unified grid initially shows six charts; four persisted model charts are covered by regression tests. The three-chart prompt is a credible explanation for the reported three visualizations, but only production records can establish which charts were saved and served. Raw Claude insight responses are not retained in `document_ai_runs` or `processing_jobs`, so a three-chart stage count cannot by itself prove whether Claude considered a fourth candidate.

After authenticating the Railway CLI, confirm `railway status` points to project `superb-emotion`, environment `production`. Run the following read-only SQL against the **production** Postgres service (one Railway SSH session at a time). The title query may return more than one upload; use the exact document ID from the UI to disambiguate.

Read the deployed prompt limit without displaying Railway's full variable set:

```sh
railway ssh --service CA_BACKEND --environment production -- php artisan config:show document_processing.insights_max_charts
```

```sql
BEGIN TRANSACTION READ ONLY;
SELECT id, name, status, created_at, published_intelligence_revision_id,
       active_intelligence_revision_id
FROM documents
WHERE name ILIKE '%Kisiwa Ridge Development%'
ORDER BY created_at DESC;

-- Substitute the exact document ID from the preceding result.
SELECT c.id, c.intelligence_revision_id, c.type, c.title, c.description,
       c.data, c.source_metadata
FROM document_charts AS c
JOIN documents AS d ON d.id = c.document_id
WHERE d.id = '<document-id>'
ORDER BY c.intelligence_revision_id, c.id;

SELECT id, intelligence_revision_id, stage, status, output, created_at
FROM processing_jobs
WHERE document_id = '<document-id>' AND stage = 'ai_analysis'
ORDER BY created_at DESC;

SELECT id, prompt_version, status, created_at
FROM document_ai_runs
WHERE document_id = '<document-id>' AND purpose = 'insights'
ORDER BY created_at DESC;
COMMIT;
```

For each chart on the published revision, compare its saved type, title, points and source metadata with the authenticated document API's `charts` array. If a payment chart is saved but absent from the API, inspect the revision pointer and `DocumentResource` selection. If present in the API, inspect `renderEligible`, `unavailabilityReason` and frontend `data-chart-count`; an invalid saved chart should appear as a table rather than vanish. If the published revision contains only the three observed charts and the stage output reports `chart_count: 3`, the omission happened before or during insights persistence. The current job neither structurally filters nor slices pre-v10 model charts; the prompt's three-chart maximum is a likely constraint, not a proven decision by Claude. If the run used prompt v10 or later, that version intentionally saves zero model-authored charts and the visible charts need their origins checked separately.

Do not infer a unit or synthesize the missing payment chart from these four expected numbers. No extra inference is needed for the inventory check.
