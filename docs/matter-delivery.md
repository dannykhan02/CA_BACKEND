# DocIntel Matter intelligence delivery

Implemented against the existing React/Vite and Laravel application. The pre-implementation audit is in [matter-implementation.md](matter-implementation.md).

## Product behavior

- Matters support creation, editing, deletion, nullable extensible types, and paginated document membership. Documents remain usable without a Matter. Assignment is available after upload from document detail and Matter detail; suggested pairs are assigned atomically.
- Directional relationships support extensible type slugs, notes, editing/removal, and duplicate prevention. Both documents must be visible to the caller. Matter comparison/connection selectors are scoped to that Matter.
- Document detail foregrounds entities, risks, actionable deadlines and time-bound obligations, confidence, processing status, and actual source excerpts. Existing KPIs/charts remain intact. Summary prose is collapsible.
- Users can close/dismiss or mitigate risks. Exact title/evidence matches retain review status through re-extraction. Existing audit logging records risk review.
- Durable tracked items preserve extracted evidence, date type, confidence and original date even when extraction rows are replaced. Users can correct title/date/notes, complete, dismiss or reopen items.
- Dashboard and Deadlines page show open, overdue, upcoming, completed and dismissed tracking views.
- Matter overview queries existing structured records, counts open risks/upcoming dates/tracked items and flags failed/pending extraction stages. Documents and intelligence sections are paginated and authorization-filtered. Opening a Matter never calls AI.
- Related-document suggestions match existing normalized contract/reference/organization entities. Dismissal is per user. Suggestions never create relationships or Matter membership automatically.
- Word export extends the existing reportBuilder and docx dependency, including visible documents, document executive summaries, entities, dates/obligations, risks, KPIs, tracked records, comparison results and evidence. Report generation uses the existing AuditLogger convention.

## What Changed

The caller chooses base/original and compared/new. A fingerprint of the ordered structured snapshots (and optional excerpt context/model/prompt version) deduplicates persisted comparisons. Jobs run on the existing `extraction` queue; page loads only read/poll persisted results. Changed extraction inputs create a new snapshot; prior results remain historical.

The default comparison is deterministic: normalized labels/types group deadlines, risks, entities and KPIs, preserving multiple values rather than overwriting duplicate labels. Before/after values retain document IDs and available evidence. Newly observed or unobserved extraction is not presented as proof of a legal addition/removal.

An explicit optional checkbox enables terms/clauses reasoning through the existing AnthropicClient, throttle/retry handling, PromptManager and document_ai_runs audit records. It reuses stored embedding chunks, or TextChunker over bounded extracted text when chunks are unavailable. Up to 200 candidate chunks are ranked using business-term signals; at most 12 excerpts of 1,600 characters per document are submitted. No new embeddings are requested. The response validator rejects unknown chunk references and quotes absent from the indicated side's supplied excerpt. Before/after document IDs and names are assigned by the server, not trusted from AI. Processing/provider/validation failures remain explicit failed comparisons and can be retried.

## Authorization and data decisions

- `IntelligenceAccess` requires actual membership of the user's current workspace, then applies Personal uploader ownership or Organization classification visibility and the existing DocumentPolicy.
- Personal users can manage their own Matter intelligence. Organization Analysts, Reviewers and Administrators can manage it; Viewers can read only what their document permissions allow. Matter permissions never grant extra document access.
- Relationships, comparisons, suggestions and tracking check both workspace and underlying document visibility on reads/writes. Reclassification revokes comparison access, including stored snapshots.
- One optional `documents.matter_id` is used for V1. Moving a document moves its tracked-item Matter reference. Deleting a Matter detaches documents/tracking without deleting them.
- Database foreign keys cascade document hard deletions. DocumentObserver also removes relationships, comparisons, tracking and dismissals on soft deletion. API document deletion is transactional.
- Tracking is a shared workspace record per document/evidence fingerprint, independent of replaceable AI extraction rows. Only the original tracker configures that item's email reminder.

## API endpoints

All new routes use Sanctum authentication and verified-email middleware.

| Methods | Path | Purpose |
| --- | --- | --- |
| GET, POST | `/api/matters` | Paginated list / create |
| GET, PATCH, DELETE | `/api/matters/{matter}` | Detail / edit / delete |
| POST, DELETE | `/api/matters/{matter}/documents/{document}` | Assign/move / detach; POST optionally accepts related_document_id for atomic pair assignment |
| GET | `/api/matters/{matter}/intelligence?kind=risks` | Paginated risks, deadlines, obligations, entities, kpis, tracked, summaries |
| POST | `/api/matters/{matter}/report-generated` | Report audit event |
| GET, POST | `/api/document-relationships` | Paginated authorized list / create |
| PATCH, DELETE | `/api/document-relationships/{relationship}` | Edit / remove |
| GET, POST | `/api/tracked-items` | Paginated filtered tracking / track an extracted deadline |
| PATCH | `/api/tracked-items/{trackedItem}` | Correct, change status or configure reminder |
| GET, POST | `/api/document-comparisons` | Paginated authorized results / queue or reuse a comparison; optional include_terms boolean |
| GET | `/api/document-comparisons/{comparison}` | Authorized status/result |
| GET | `/api/documents/{document}/context` | Matter membership and suggestions |
| POST | `/api/documents/{document}/suggestions/{related}/dismiss` | Persist suggestion dismissal |
| PATCH | `/api/documents/{document}/risks/{risk}` | Review existing risk status |

Relationship and comparison lists accept `document_id` or `matter_id`. Tracking accepts `filter=open|upcoming|overdue|completed|dismissed` and optional document_id. Existing document listing now accepts an authorized matter_id filter. Existing reprocess endpoint accepts `intelligence_only=true` for Ready documents with missing/failed intelligence, preserving uploads and existing KPIs.

Frontend hash routes: `#/matters`, `#/matter/:id`, `#/deadlines`. Existing document and dashboard routes are extended.

## Migrations and deployment

1. `2026_09_20_000001_add_matter_intelligence.php`: adds matters, document_relationships, tracked_items, document_comparisons and document_suggestion_dismissals; nullable document Matter foreign key; relationship/dedup/filter indexes and normalized-entity lookup index. Existing documents need no backfill.
2. `2026_09_20_000002_allow_document_comparison_ai_runs.php`: expands the existing AI-run purpose constraint. Rollback refuses to invalidate existing comparison audit history.

Run from CA_BACKEND during deployment:

```sh
php artisan migrate --force
php artisan db:seed --class=DocumentComparisonPromptSeeder --force
php artisan horizon:terminate
```

Run the existing frontend production build/deploy process. No package changes or new environment variables are required. Existing Anthropic model/API credentials are needed only for optional AI terms analysis. Existing mail configuration and FRONTEND_URL serve reminders. Existing Horizon workers must consume `default` and `extraction`, and the Laravel scheduler must run each minute. These were already configured in the repository; no new external notification service is introduced. Do not run the development DatabaseSeeder against production.

The reminder scheduler selects opted-in, due, open items and dispatches jobs to the existing default queue. Jobs check current access, active/verified users, completion/cancellation and prior delivery, and serialize sends using a database row lock. It uses Laravel mail notifications. Delivery is at-least-once under a process crash between provider acceptance and database commit. Users who have switched away from the item's workspace are skipped until that workspace is current and accessible again.

No production/application migrations, prompt seeding, live AI requests or real email sends were performed during this implementation. Migrations ran in the isolated configured test database.

## Verification

- Backend full suite: **301 passed, 6 skipped, 1,823 assertions** (307 total). Includes 16 new MatterIntelligence tests and existing upload, document processing/recovery, workspace, authentication and authorization tests.
- Frontend full suite: **30 passed across 6 files**, including 7 new tests for Matter creation, evidence/failure presentation, comparison sides/retry, tracking, and Word package/XML contents.
- TypeScript typecheck: passed.
- Vite production build: passed.
- ESLint: zero errors; one existing Fast Refresh warning in `src/context/FilterContext.tsx`.
- New PHP files formatted with the installed Pint tool. Git whitespace checks passed.
- Route registration and scheduler listing verified; tracked-deadline-reminders is scheduled every minute.
- AI integration tested with HTTP fakes, including citation validation and existing AI-run metadata. Notification behavior tested with Laravel notification fakes.

## Limits and follow-up considerations

- Exact insight-to-page mapping is not available in the existing pipeline; sources use actual excerpts/context and original-document downloads. No page or clause references are fabricated.
- Obligations reuse the existing time-bound obligation/deadline extraction. This is not a comprehensive undated-obligation register or a task-management system.
- Comparisons are evidence-backed observations, not authoritative legal diffing. Deterministic matching can split renamed concepts into separately observed items; selected AI excerpts can miss terms elsewhere. Model judgment is not proven by fixture tests. No legal-authority decision is made.
- Suggestions currently use exact normalized entity/reference overlap; fuzzy titles/vector ranking are not added. Organization-name overlaps can be broad and remain suggestions only.
- Tracking persists through re-extraction, but substantially changed evidence/title can produce a new source fingerprint; the old tracked item stays for user review.
- Matter assignment is offered after upload, not in the upload modal. Existing upload behavior and credit charging remain intact.
- Word is implemented and package contents tested. PDF export, visual Word rendering, live Claude output quality, real email deliverability, and manual mobile/dark-mode browser inspection were not verified.
- No chat, autonomous linking, relationship graph, or unrelated product features were added.

## File manifest

Paths below are relative to each repository. An unrelated leading-blank-line edit in CA/src/referrals.ts appeared during the work and is excluded from this implementation manifest.

### CA_BACKEND

- `app/Http/Controllers/Api/DocumentComparisonController.php`
- `app/Http/Controllers/Api/DocumentContextController.php`
- `app/Http/Controllers/Api/DocumentController.php`
- `app/Http/Controllers/Api/DocumentRelationshipController.php`
- `app/Http/Controllers/Api/DocumentReprocessController.php`
- `app/Http/Controllers/Api/DocumentRiskReviewController.php`
- `app/Http/Controllers/Api/MatterController.php`
- `app/Http/Controllers/Api/TrackedItemController.php`
- `app/Http/Requests/Document/IndexDocumentsRequest.php`
- `app/Http/Resources/DocumentComparisonResource.php`
- `app/Jobs/CompareDocumentsJob.php`
- `app/Jobs/DetectDocumentRisksJob.php`
- `app/Jobs/SendTrackedDeadlineReminder.php`
- `app/Models/Document.php`
- `app/Models/DocumentComparison.php`
- `app/Models/DocumentRelationship.php`
- `app/Models/Matter.php`
- `app/Models/TrackedItem.php`
- `app/Notifications/TrackedDeadlineReminder.php`
- `app/Observers/DocumentObserver.php`
- `app/Services/AI/ResponseValidator.php`
- `app/Services/AnthropicClient.php`
- `app/Services/ComparisonContextService.php`
- `app/Services/DocumentComparisonService.php`
- `app/Services/DocumentIntelligenceService.php`
- `app/Services/IntelligenceAccess.php`
- `app/Services/MatterService.php`
- `app/Services/RelatedDocumentService.php`
- `app/Services/TrackingService.php`
- `database/migrations/2026_09_20_000001_add_matter_intelligence.php`
- `database/migrations/2026_09_20_000002_allow_document_comparison_ai_runs.php`
- `database/seeders/DatabaseSeeder.php`
- `database/seeders/DocumentComparisonPromptSeeder.php`
- `docs/matter-delivery.md`
- `docs/matter-implementation.md`
- `routes/api.php`
- `routes/console.php`
- `tests/Feature/MatterIntelligenceTest.php`

### CA

- `src/App.tsx`
- `src/components/AppShell.tsx`
- `src/components/DocumentConnections.tsx`
- `src/components/DocumentIntelligencePanel.tsx`
- `src/components/DocumentMatterPanel.tsx`
- `src/components/IntelligenceControls.tsx`
- `src/hooks/useIntelligenceAction.ts`
- `src/matters.ts`
- `src/pages/DashboardPage.tsx`
- `src/pages/DeadlinesPage.tsx`
- `src/pages/DocumentDetailPage.tsx`
- `src/pages/MatterDetailPage.tsx`
- `src/pages/MattersPage.tsx`
- `src/pages/reportBuilder.tsx`
- `src/permissions.ts`
- `src/router.ts`
- `src/types.ts`
- `tests/matter-intelligence.test.tsx`
