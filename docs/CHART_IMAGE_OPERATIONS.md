# Chart-image storage and purge release runbook

## Current evidence and release gate

The `documents` Laravel disk uses private Cloudflare R2 through the S3 driver (`R2_ENDPOINT`, `R2_BUCKET`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, region `auto`, path-style URLs, `throw=true`). Chart images are stored in that disk, not in a Railway container or volume. A configuration comparison is useful but does **not** prove that the worker can write and the web service can read the same object. On 2026-10-15 the Railway project had only a `production` environment; there was no staging target. Do not run this synthetic write on production without explicit authorization.

The release gate is one successful real worker `write`, web `verify`, and web `cleanup` sequence in the same environment, with matching storage fingerprints, matching SHA-256, denied unsigned access, and confirmed deletion. Review Cloudflare R2 bucket public development URL and custom-domain settings independently. Pass **every** enabled public domain as `--public-base-url`; use `--public-access-reviewed` only after checking that no other public domain is enabled. An unsigned request to the S3 endpoint alone cannot establish that the bucket has no public custom domain. The command does not inspect Cloudflare settings itself.

## Explicit cross-service diagnostic

After the migration and matching backend code are deployed, run these commands **one at a time**, only in an authorized environment. `railway run` executes locally and is unsuitable. Use `railway ssh` on the named remote service. These commands touch only a unique 1×1 synthetic PNG at `chart-storage-diagnostics/{run}/probe.png`; they never read or change a customer document.

```sh
railway ssh --service ca-horizon-worker --environment staging -- php artisan chart-images:storage-diagnostic write --expected-service=ca-horizon-worker --expected-environment=staging
```

Record the `run`, `sha256`, and `storage_config_fingerprint` from the output. Substitute them below. The web and worker fingerprints must match. If staging is unavailable, replace `staging` with `production` only after explicit production-test authorization.

```sh
railway ssh --service CA_BACKEND --environment staging -- php artisan chart-images:storage-diagnostic verify --expected-service=CA_BACKEND --expected-environment=staging --run=<run-uuid> --sha256=<worker-sha256> --public-access-reviewed
```

If Cloudflare lists any bucket public domains, add one `--public-base-url=https://<domain>` for each. A public HTTP 200 or redirect fails verification. A network error or an unreviewed public-domain setting also fails. The diagnostic reports service/environment, a fingerprint of **nonsecret** disk settings, result codes and a classified error; it never prints R2 credentials or object bytes. A permission, bucket, endpoint, DNS, or SHA mismatch must be fixed at its reported layer before release. Do not switch storage providers.

Always attempt the cleanup, even if verification failed. Cleanup requires the exact worker SHA-256, so a mismatched object cannot be deleted by accident.

```sh
railway ssh --service CA_BACKEND --environment staging -- php artisan chart-images:storage-diagnostic cleanup --expected-service=CA_BACKEND --expected-environment=staging --run=<run-uuid> --sha256=<worker-sha256>
```

If cleanup fails, keep the run UUID and escalate to the storage operator for a scoped manual investigation. Never use an unscoped bucket delete. A synthetic diagnostic object under `chart-storage-diagnostics/` is separate from customer chart images.

## Purge monitoring and read-only reconciliation

Soft deletion revokes the authorized image endpoint and retains images under the document retention model. Model-level permanent deletion enqueues `PurgeDocumentChartImagesJob` after commit. It only targets `chart-visuals/{workspace}/{document}/`, refuses a prefix still referenced by any retained chart row, and retries ordinary failures after 60 and 300 seconds. Terminal failure writes a `critical` log with workspace/document IDs and error class, without exception text or storage secrets. Horizon and `failed_jobs` also retain the failed job until normal pruning. Operations should alert on that log event and on any nonzero failed-purge count; confirm the production log/alert route during rollout.

For a scoped read-only inventory, run:

```sh
railway ssh --service CA_BACKEND --environment staging -- php artisan chart-images:audit --workspace=<workspace-uuid> --min-age-hours=24 --limit=100
```

Add `--document=<document-uuid>` to narrow it further. `failed_purge_jobs` is a **global** count; candidate paths are limited to the selected workspace/document and exclude recent objects, objects without a known modification time, and paths still referenced by chart metadata. A candidate is a lead for manual investigation, not proof that deletion is safe. The command never deletes objects. Direct SQL deletion can bypass Laravel model events; this audit can detect old unreferenced images, but no automatic orphan deletion is enabled. A complete retention period and disposition procedure must be approved before automated orphan removal.

## Controlled rollout

1. Review and approve the nullable `document_charts.source_metadata` migration. It leaves historical rows `NULL`; old web and worker code can continue to read/write them. Apply it before deploying new code. Do not roll it back while any new rows depend on it.
2. Pause Horizon intake and wait for active visual jobs to finish. Inspect failed/in-flight jobs; do not reprocess customer documents as part of this release. Deploy the web service and Horizon worker from the **same backend commit**, then restart/resume Horizon. Verify both deployments and the `documents` disk's nonsecret configuration fingerprint. The web image route must be live before new worker image writes are relied on.
3. In an authorized environment, run the worker/web/cleanup diagnostic above and verify private-bucket settings. Treat any failed phase or unmatched fingerprint as a release blocker. No diagnostic was run as part of the code change because staging was unavailable and production writes were not authorized.
4. Confirm the authenticated image endpoint with a permitted test document and a revoked-access case, then deploy the frontend. The frontend requests source images with its normal Bearer token and uses the same server redraw gate as Word export. Historical charts remain tables when trustworthy source metadata is absent.
5. Monitor Horizon, critical purge logs and the read-only audit after rollout. Investigate any failed purge or unreferenced image according to the approved retention policy; do not bulk-delete candidates.
