# Private Document Notes — first release

## Scope and boundaries

This release stores private notes attached to a document. `DOCINTEL_NOTES_ENABLED` defaults to false. The Notes routes and tables are separate from document intelligence, source references, search embeddings, Q&A, and report snapshots. Citation labels and quotes are author-supplied snapshots, always returned with `verificationStatus: unverified_snapshot`. A source ID is opaque and may be stored only with a current published revision and a unique source record owned by the same document and workspace. The label and quote are never asserted to match that source.

The current `DocumentPolicy::view` does not independently check a live `workspace_members` row. Its Personal branch checks uploader but does not compare the document's workspace to `current_workspace_id`. This is a broader document-policy concern; this release does not modify that policy. `DocumentNoteAccess` and `DocumentNotePolicy` both require live membership, current workspace identity, document visibility, Personal uploader ownership, and author ownership. Notes lookups return the same 404 for missing and inaccessible objects.

| Actor/state | List/create | Change/delete |
| --- | --- | --- |
| Current member who can view document | Own notes | Own notes with matching version |
| Same workspace, different author, including Administrator | Own notes only | 404 |
| Other workspace, revoked member, or Personal non-uploader | 404 | 404 |
| Role no longer permits classification; document soft-deleted | 404 | 404 |
| Flag off | 404 | 404 |

PATCH requires a body, complete replacement citation array, and `version`. DELETE requires `If-Match: "<version>"`; missing/malformed preconditions return 428, stale versions return 409 with the current note only after authorization. The controller locks the membership, document, and note rows in one transaction. Each successful PATCH increments the version once and replaces citations in that transaction. Citation rows are immutable in place; no pipeline job updates them.

Audit events `document_note.created`, `.updated`, and `.deleted` contain IDs and version only. The Notes write path catches database errors before Laravel's exception handler can report SQL bindings containing note text. Sentry request bodies and SQL bindings are disabled; its Notes event hook strips query strings and request data. Infrastructure access logs outside Laravel were not inspected and should not receive note content through URL parameters, which the Notes API does not use.

## Standalone Notes later

Keep `document_notes.document_id` required for this release. A later standalone Notes scope can use a separate table or an explicit parent/scope model, sharing body validation, version handling, citation serialization, and private-author semantics. It must gain its own policy and query boundary before use. Do not make `document_id` nullable or add a polymorphic target to these routes: doing so would turn a document authorization check into an optional condition.

## Railway deployment and rollback

Production is Railway PostgreSQL; there is no staging environment. Do not use `railway run` for a private-network database command. Do not print variables or credentials. Run one Railway SSH session at a time.

1. Verify a current backup and record `php artisan migrate:status` from the production app service. Confirm prerequisite migrations through `2026_10_12_000001_allow_source_continuations` are applied. Stop and reconcile any earlier pending migration before proceeding.
2. Release **only** commit `c1c920f` (migration and models) to the web and worker images. It adds no Notes routes or reads. From the newly released `CA_BACKEND` image, run once: `railway ssh --service CA_BACKEND --environment production -- php artisan migrate --force`. This must precede deploying the Notes API code. Confirm `2026_10_14_000001_create_document_notes` is applied and both new tables, triggers, and indexes exist. A nonzero migration exit, pending status, or missing object is a failed migration: stop; do not deploy the API.
3. Release the API and tests commits with `DOCINTEL_NOTES_ENABLED=false`. Confirm `/api/capabilities` reports `documentNotes.enabled=false`, every Notes route returns 404, and existing document paths behave normally.
4. Set `DOCINTEL_NOTES_ENABLED=true` on the web service and refresh/redeploy its config. Smoke-test one authorized create/list/edit/delete and a cross-author 404. Workers need no Notes flag because no Notes jobs exist.

If the API release fails, turn the flag off and revert that code while leaving the additive tables in place. If migration fails, keep the API undeployed and inspect the error and migration status before retrying a corrected migration. The migration `down()` drops both Notes tables and their content; use it only after an explicit export or data-retention decision. No production migration, deployment, push, or secret access occurred during implementation.
