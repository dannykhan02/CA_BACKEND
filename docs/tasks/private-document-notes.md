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

## Rich-text upgrade (Tiptap/ProseMirror v1)

The additive `2026_10_14_000002_add_rich_content_to_document_notes` migration adds nullable JSONB `content` and constrained `content_format` (`plain_text` or `tiptap_v1`) to `document_notes`. Existing rows remain `plain_text` with null content and retain their body. Citation tables and document authorization are unchanged. `content_format` carries schema version 1 independently of the note's concurrency `version`; the API returns `contentFormat` and `contentSchemaVersion` (null for legacy rows). The server treats all stored JSON and citation labels/quotes as untrusted user input. A frontend must render text safely and must never interpret it as HTML.

`DOCINTEL_NOTES_RICH_TEXT_ENABLED` defaults to false independently of `DOCINTEL_NOTES_ENABLED`. `/api/capabilities` reports `documentNotes.richTextEnabled` only when both flags are true. A rich submission while the rich flag is off returns 404. The existing Notes flag still makes every Notes route return 404 when off. The API changes only existing Notes routes and the Notes capability field.

### JSON and write contract

`content` is a ProseMirror root object such as `{"type":"doc","content":[{"type":"paragraph","content":[{"type":"text","text":"Hello"}]}]}`. Send `contentSchemaVersion: 1` with it. The server derives `body` from text nodes in reading order, separating blocks with newlines and representing inline citation references as `[citation]`; any submitted `body` is ignored when `content` is present. No client-supplied source label, quote, revision, or verification status is allowed in a citation-reference node.

POST without `content` retains the legacy required-body contract. PATCH always requires `version`. Omitted `body`, `content`, and `citations` preserve their current values. A rich PATCH replaces the whole content tree and derives a new body. A legacy plain-text PATCH with `body` updates a plain note. To deliberately convert a rich note to plain text, PATCH with `content: null` and a nonempty `body`; a body-only request against a rich note returns `NOTE_FORMAT_CHANGE_REQUIRED` so an old client cannot silently discard formatting. `contentSchemaVersion` is required for nonnull content and must be omitted when clearing it. A supplied `citations` array replaces all citations atomically, while omission preserves them. Successful PATCH increments `version` once. Stale versions return the authorized current note with `NOTE_VERSION_CONFLICT`; DELETE still requires quoted `If-Match`.

Allowed blocks: `doc`, `paragraph`, `heading` (level 1–3), `bulletList`, `orderedList` (optional positive `start`), `listItem`, `taskList`, `taskItem` (required boolean `checked`), `blockquote`, `codeBlock`, `horizontalRule`, and `callout` (kind `info`, `tip`, or `warning`). `citationRef` is an inline atom with only `attrs.citationId`. Allowed text marks: `bold`, `italic`, `underline`, `strike`, `code`, `link`, and `highlight`. Links accept only absolute `http`, `https`, or `mailto` URLs without control characters; highlight accepts only `yellow`, `green`, `blue`, or `pink`. Paragraphs and headings may use `textAlign` of `left`, `center`, `right`, or `justify`. Unknown nodes, marks, attributes, arbitrary HTML/CSS, embedded resources, and executable URLs are rejected. Inline text whitespace is preserved exactly before the server derives body.

The server caps a rich request's raw JSON at 256 KiB (including citation drafts), the encoded content tree at 128 KiB, the tree at 2,000 nodes and 16 levels, list nesting at 6 levels, and combined text at 20,000 Unicode characters; derived body must also fit 20,000 characters. These bounds constrain traversal work, storage amplification, and the existing Notes body policy. Invalid content is rejected without truncation as 422 `NOTE_INVALID_CONTENT`. Citation references with no owned matching row yield 422 `NOTE_CITATION_REFERENCE_INVALID`.

For a new citation in POST or a citation replacement in PATCH, give its citation object a unique ephemeral `client_ref` of the form `draft:<UUID>` and point a rich `citationRef.attrs.citationId` at that token. The transaction validates the citation against the current document and published revision, creates the immutable snapshot row, replaces draft tokens in stored JSON with the new row UUID, and commits the note and citations together. The response contains only stored UUIDs. When `citations` is omitted, a rich update may reference existing citation IDs belonging to that same note; historical snapshots remain intact even if intelligence is reprocessed. When `citations` is supplied, all old citation IDs are removed, so content must be supplied again if it references any of them. This prevents a citation-only replacement from silently rebinding old references to new evidence. Failed validation or a stale version rolls back every replacement.

### Railway migration and rollback for rich text

There is no staging environment. Before a production change, verify a current backup, the active deployed code version, Notes flag state, and `php artisan migrate:status` from the Railway app service without printing credentials. First deploy the backward-compatible migration support while the old Notes API remains active and the rich flag is off. Run **once, before deploying the rich API**: `railway ssh --service CA_BACKEND --environment production -- php artisan migrate --force`. Confirm `2026_10_14_000002_add_rich_content_to_document_notes` is applied and the two new columns and check constraint exist. A nonzero exit, pending migration, or missing column means stop the release and keep the old API. Then deploy the API code with the rich flag still off, verify legacy create/list/PATCH/delete and cross-author 404, and only then set `DOCINTEL_NOTES_RICH_TEXT_ENABLED=true` on the web service and refresh its config. Smoke-test rich create/list/PATCH, stale-version 409, inaccessible-note 404, and legacy behavior.

If rich API verification fails, turn off `DOCINTEL_NOTES_RICH_TEXT_ENABLED` first. Turning off all Notes uses `DOCINTEL_NOTES_ENABLED=false`. Leave the additive columns in place when reverting API code, and assess whether old code can safely edit rich rows before allowing legacy writes again. Database rollback of migration `000002` drops stored rich JSON; it is **not a routine production rollback** once any rich note exists. Export and make an explicit data-retention decision before any destructive schema rollback. No production changes are part of this implementation task.
