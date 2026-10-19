# Resolved: source viewer reported originals as unavailable (2026-10-17 → 2026-10-19)

**Nothing was deleted and nothing was lost.** A configuration change silently moved the key prefix
the application read and wrote under, so objects that were present in R2 the whole time stopped
resolving. The fix restored every one of them.

This document previously recorded this as a storage availability problem with an unverified cause,
and said production source navigation was blocked by missing originals. **That was wrong** and has
been removed.

## Root cause

Commit **`260d730`** (2026-10-17) added one line to the `documents` disk in
`config/filesystems.php`:

```php
'driver' => env('DOCUMENTS_DISK_DRIVER', 's3'),
'root' => env('DOCUMENTS_LOCAL_ROOT', storage_path('app/documents')),   // <- the bug
```

On the **local** driver `root` is a filesystem directory. On the **s3** driver it is an **object-key
prefix**, which Flysystem prepends to every key it reads or writes. Production runs the s3 driver,
and `storage_path('app/documents')` resolves inside the container to `/app/storage/app/documents`,
so from that deploy onwards the application addressed every object as

    /app/storage/app/documents/<workspace-uuid>/<uuid>.pdf

while every object already in the bucket was stored — correctly — as

    <workspace-uuid>/<uuid>.pdf

Reads of existing documents therefore missed, and `exists()` answered false for objects sitting
untouched in the bucket. New uploads during the window were written *under* the accidental prefix,
so they worked while the bug was live and would have stopped working once it was fixed — which is
why they had to be moved (see Recovery).

The line was almost certainly intended to make local development work with
`DOCUMENTS_DISK_DRIVER=local`, which the same commit introduced. The driver was made conditional;
the root was not.

## Evidence

- An object uploaded on **2026-10-05** was visible in the Cloudflare dashboard at its normal key at
  the same time the application reported it MISSING. Present in storage, unreachable by the app —
  which is what pointed at addressing rather than availability.
- `listObjectsV2` showed the window's uploads under keys beginning
  **`/app/storage/app/documents/`**, next to the older objects at bare `<workspace>/<uuid>` keys.
  Two prefixes in one bucket, with the dividing line at the deploy of `260d730`.
- The apparent age gradient was the deploy, not decay: the one document that resolved during the
  incident was uploaded **after** the bad deploy (and so lived under the new prefix); everything
  older did not.
- After the fix, documents resolving went from **7 to 79** of 86, with no object restored, copied
  back or recovered from backup.

## What was NOT the cause

Each of these was considered and is excluded:

- **No bucket lifecycle or expiry rule.** Nothing expired; the objects were listable throughout.
- **No deletion, by the application or by anyone.** The application has no code path that deletes
  an original of a `Ready` document: the one path that deletes an original (a malware hit in
  `ScanUploadedFileJob`) sets `status = 'Failed'` in the same transaction, both upload-cleanup paths
  refuse any key a `Document` row owns, deleting a document removes nothing from storage, nothing
  calls `forceDelete` on a `Document`, and every other write on the disk uses a distinct prefix
  (`ai-ocr/…`, `chart-visuals/…`).
- **No credential, bucket or endpoint change.** The same bucket and keys were in use before, during
  and after; the dashboard listing proves the app was authenticated against the right bucket.
- **Not the 2026-09-03 local→R2 migration.** That switch (commit `5034843`) carries its own
  caveat for anything uploaded before it, but every document in this incident was uploaded after it
  and was present in R2.
- **Not a divergent `DOCUMENTS_DISK_DRIVER`.** This was the leading hypothesis of the earlier
  read-only code review and it was wrong. The driver line and the root line arrived in the same
  commit; the driver defaulted correctly to `s3` and only the root was unconditional.

## Fix

**PR #64**, on `main` as **`5e67a2d`** — `root` is applied only on the local driver:

```php
// 'root' is an object-key PREFIX on the s3 driver. Only set it for the local
// driver, otherwise every R2 key gets /app/storage/app/documents/ prepended
// and existing objects (stored without a prefix) stop resolving.
'root' => env('DOCUMENTS_DISK_DRIVER', 's3') === 'local'
    ? env('DOCUMENTS_LOCAL_ROOT', storage_path('app/documents'))
    : null,
```

Pinned by `tests/Unit/DocumentsDiskConfigTest.php`, which fails if the unconditional root returns.

## Recovery

1. Diagnosed by comparing the dashboard listing with what the application reported, then by
   `listObjectsV2`, which showed the two prefixes.
2. The **7 uploads made while the bug was live** were copied from their prefixed keys to their
   normal `<workspace>/<uuid>` keys **before** the config fix shipped, so they would still resolve
   afterwards. Copies, not moves — nothing was deleted.
3. `5e67a2d` deployed.
4. Verified: **79 of 86** documents resolve.

## Final state

**79 of 86 documents resolve.** The remaining 7 are not storage failures:

- **6 seeded rows with a null `file_path`** (dated 2025-12-20 and July 2026). There is no object
  because no file was ever uploaded for them.
- **1 upload of 0 MB from 2026-09-17** whose status is `Failed`. The upload did not complete, so
  there is nothing to resolve.

Neither group needs recovery, and both are reported — without failing — by
`php artisan documents:audit-originals`.

## Lesson

**Never set `root` on an S3-driver disk.** It is a key prefix, not a directory, and the damage is
invisible: writes succeed, reads of new objects succeed, and only objects written before the change
disappear from the application's view while remaining in the bucket. A disk option that means two
different things on two drivers has to be made conditional on the driver in the same change that
introduces it.

Two follow-ups this exposed, neither of which existed at the time:

- `php artisan documents:audit-originals` — read-only, reports rows whose original does not resolve,
  grouped by status, and exits non-zero only when a live `Ready` document is affected. Running it
  after the bad deploy would have shown 79 documents missing within a minute.
- It is **not** wired into scheduling or the health check yet. `HealthCheck` still covers only the
  database, Redis, local free space and Horizon, and does not touch the `documents` disk.
