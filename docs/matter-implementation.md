# DocIntel architecture audit and implementation plan

Audit completed before implementation (20 September 2026).

| Area | Existing implementation / reuse decision |
| --- | --- |
| Documents | UUID Document, soft deletes, optional workspace, uploader/updater, extracted_text, insights JSON, file hash; versions, OCR results, page flags and charts. Add optional matter_id; preserve existing uploads. |
| Ownership | UUID workspaces and workspace_members; Personal ownership versus Organization classification/role permissions. New endpoints require current workspace membership AND existing document visibility. |
| Processing | Upload/storage/dedup/credits → malware scan → native text or batched OCR → insights/charts/embeddings. Separate extraction batch classifies and extracts entities/risks/deadlines; grounded summary follows completion. |
| AI | AnthropicClient owns provider calls, retry, throttling, validation and document_ai_runs; database-versioned PromptManager. No second provider or extraction pipeline needed. |
| Vectors | VoyageEmbeddingClient, TextChunker, pgvector document_embeddings, tenant-scoped search/retriever. Chunks lack page provenance. Cheap related suggestions can use existing normalized entities without embedding calls. |
| Insights | DocumentIntelligenceService and composite API Resource already aggregate existing tables without AI. Frontend currently fetches this primarily for reports. Surface on document page. |
| Risks | document_risks: title, description, type, severity, confidence, evidence, status, model/prompt metadata. Reuse. |
| Dates | document_deadlines: explicit/relative/inferred, nullable actual due date, relative text, evidence and confidence. AI job deletes/recreates rows, so user tracking must use durable evidence snapshots rather than mutable extraction row IDs alone. |
| KPIs | document_kpis label/value/value_numeric/unit/trend; charts and chart points. Preserve normalization. |
| Entities | document_entities type/value/normalized_value/context/confidence; organizations, people, contracts and references already structured. |
| Sources | Risk/deadline evidence and entity context exist. OCR has page numbers but no reliable mapping from extracted insight to page. Show real excerpts and document links only. |
| Classifications | document_type_classifications is AI business type; documents.classification is security classification. Keep distinct. |
| Review | Processing/Ready/Needs Review/Failed/Rejected, approve/reject/reprocess policies and audit logging. Preserve. |
| Export | Frontend pages/reportBuilder.tsx uses docx, shared branded headings/body/tables and charts. Backend records report-generated audit events. Extend Word builder, no new PDF engine. |
| Notifications | Laravel mail notifications, default queue and scheduler exist; user preferences currently only processing/review/Power BI. Deadline reminder consent must be explicit per tracked item. |
| Queues | Horizon default and extraction queues, processing_jobs stage recording and failed_jobs. Comparison uses extraction queue, its own persisted status/result and explicit failure. |
| Frontend | Hash router, AppShell, API client, auth hooks, modal/toast, theme CSS tokens; DocumentDetailPage currently foregrounds KPIs/charts/prose. Reuse. |
| API | Sanctum + verified-email middleware, controllers, inline or FormRequest validation, JsonResources, paginated document list. Follow conventions. |
| Authorization | DocumentPolicy filters Personal uploader or Organization classifications. New shared access service must also enforce current membership and both comparison/relationship endpoints. |
| Tests | PHPUnit/PostgreSQL RefreshDatabase; document authorization/upload/workflow/processing/recovery/workspace tests. Vitest + Testing Library already installed. Use both. |

## Implementation order and decisions

1. Add Matters and optional document membership; free-text nullable type. One Matter per document keeps V1 simple; future many-to-many requires explicit migration.
2. Directional typed relationships with unique from/to/type; authorize both endpoints. Remove on soft as well as hard deletion.
3. Expose existing structured intelligence and evidence before prose, including processing errors. Time-bound obligations already extracted through deadlines; preserve relative dates without inventing dates.
4. Durable tracked items snapshot existing deadlines/obligations and evidence, with user-confirmed dates/status/notes and optional reminder configuration. Keep independent of extraction replacement.
5. Aggregate visible documents only; paginate lists and intelligence, never list extracted_text.
6. Persist queued structured comparisons; match normalized labels/types, retain old/new values and evidence. Report added/removed extracted items as observations, not legal conclusions; do not claim exhaustive clause diffing.
7. Suggest shared references/entities, store per-user dismissals; linking requires user action.
8. Extend existing Word builder using authorized Matter intelligence.
9. Add isolation/cascade/workflow tests; run backend suite and frontend checks. No production database reset and no live AI calls in tests.
