# Production source viewer incident: PDF object unavailable

Read-only diagnosis on 2026-10-18 for document `01a127f4-3162-7380-9a9f-547215aa5145`:

- The record is `Ready` and is not soft-deleted. Its original file hash is recorded.
- The `documents` disk is configured with the S3 driver. The stored path is a PDF under the document's workspace prefix, but `Storage::disk('documents')->exists($document->file_path)` returned false. There was no local legacy copy or document version for this record.
- No published intelligence revision exists for this document (`generation=0`). A source reference or extracted passage does not prove that the original object is available for viewing.
- The authenticated download controller authorizes document access, verifies workspace ownership and document state, then checks object existence. The missing object produces HTTP 404. The frontend fetches this endpoint with authentication; its previous generic viewer message obscured the 404. The frontend now describes an unavailable original and offers its existing retry path. Authorization and source verification remain intact.
- A read-only ten-document sample in the same workspace found an existing object for the 2026-10-17 document but missing objects for nine older Ready records dated 2026-10-16 through 2026-09-21. This points to a broader storage availability problem. The reason those objects disappeared or became inaccessible is unverified.

Safe follow-up diagnostics: compare the configured bucket, endpoint, prefix, and object version history with deployment and storage lifecycle records; check any provider-side backup or replication inventory for the exact recorded object keys and hashes. Do not log signed URLs, credentials, document text, or financial identifiers. Any restoration must preserve the stored hash and workspace key and requires a separately reviewed production change. Reprocessing these production documents would not restore the original bytes and was not attempted.

Release status: production evidence-driven source navigation remains blocked until original object availability is restored and an authenticated download is verified for an authorized account. The local three-page PDF fixture proves the current download and viewer path works when the object exists; it does not resolve this production data loss.
