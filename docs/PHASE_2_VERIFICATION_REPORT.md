Overall verdict: PASS WITH OPEN ITEMS

# Phase 2 independent verification report

Audit date: 2026-09-22 (Africa/Nairobi)

## Environment

- Repository: `dannykhan02/CA_BACKEND`
- Branch: `main`
- Final HEAD: `fae12a9`
- Railway project: `superb-emotion`
- Railway environment: `production`
- API service: `CA_BACKEND`
- Worker service: `ca-horizon-worker`
- Public API: `https://cabackend-production-f74e.up.railway.app`
- Laravel production read-only bootstrap reported PostgreSQL driver `pgsql`, database `neondb`, and a live Neon timestamp on the final query.

## Last seven hours of history

| Commit | Purpose | Verification |
|---|---|---|
| `41a2488` | startup/ClamAV configuration and soft-delete logging | Diff read; startup override remains active in Railway and is an open item |
| `7f1f8a7` | merge containing billing, workspace, referral and regression test changes | Diff and all changed tests reviewed; full suite run |
| `55311ba` | correct AI-run completion guard and transactional persistence | Diff read; targeted and full tests pass |
| `68f79de` | audit fixes: source-integrity build guard, CLI ClamAV path, DOCX XML extractor, recovery command, AI recovery guard, tests and documentation | Diff read; targeted and full tests pass |
| `6aba04b` | allow the `artisan` shebang in the source-integrity check | Build failure reproduced and fixed |
| `fae12a9` | exclude archived migration backups from runtime integrity scanning | Build failure reproduced and fixed; production deploy succeeded |

Tests changed in the historical window included `GenerateInsightsJobTest`, `PersonalWorkspaceJourneyTest`, `DocumentJobTransactionRecoveryTest`, `DocumentQaTest`, `MatterIntelligenceTest`, billing/credit/referral/concurrency tests, and error-logging tests. Each file was read and the suite was run.

## Frontend to backend

**PASS after backend deployment.** The production Vite bundle contains the HTTPS Railway API URL. The earlier failure was not a frontend URL, DNS, TLS, or mixed-content problem: the deployed PHP artifact consisted of NUL-filled files, so Railway returned HTTP 200 HTML without Laravel headers. The successful `CA_BACKEND` deployment `f4ccbf34-8b88-4b00-a77b-5e59d02cd38c` replaced that artifact.

Final evidence:

- `GET /up`: HTTP 200, Laravel HTML response, zero NUL bytes, `X-Powered-By: PHP/8.3.33`.
- CORS preflight from `https://docintel.co.ke`: HTTP 204 with `Access-Control-Allow-Origin: https://docintel.co.ke`, requested methods and headers allowed.
- Railway HTTP logs showed the requests reaching the service.

## Test suites

Baseline with dependencies available: 335 tests, 325 passed, 4 errors, 6 skipped, 4 risky, 2,052 assertions. All four errors were the same personal-document fixture problem: the fixture created an AI run without a completed processing-job proof required by the new guard.

Final: `php artisan test` — 353 tests, 347 passed, 0 failed, 6 skipped, 2,109 assertions. Skips are five missing PDF fixtures and the local runner’s unavailable real-ClamAV test. `php -l` passed for all 58 changed/recent PHP files; Pint and `git diff --check` passed.

The fixture was corrected and the application guard was strengthened. An interrupted AI-analysis regression now proves an upload with `insights=[]` is reprocessed rather than incorrectly treated as complete.

## ClamAV and startup

- Both services have `CLAMAV_DRIVER=cli` and `CLAMAV_ENABLED=true`.
- Legacy clamd socket/host/port code is removed from the scanner path.
- Signatures persisted on both Railway volumes (`main.cvd`, `daily.cvd`, `bytecode.cvd`, `freshclam.dat`). A later observation showed the same files and timestamps remained present.
- `clamscan` is ClamAV 1.4.3.
- Disposable scan: clean file exited 0; literal EICAR file was reported `Eicar-Test-Signature FOUND` and exited 1.
- The old command’s semicolon and `2>/dev/null` were a fail-open/diagnostic problem. `bin/start-production.sh` uses explicit failure handling, visible freshclam warnings, signature presence validation, and a scanner smoke test.
- The new Docker build integrity guard prevented deployment of invalid PHP source; two false positives were fixed (the `artisan` shebang and archived migration backups).
- `CA_BACKEND` has no Horizon process.
- `ca-horizon-worker` has Horizon supervisors and workers, but Railway’s existing custom start command still also starts FrankenPHP. Attempts to update the custom command through the installed Railway CLI returned `No changes to apply`; this remains an open configuration item.
- The production safety command exists and is covered locally, but the old Railway custom command does not invoke it. The hardened script invokes it when used.
- Both services have mounted `/var/lib/clamav` volumes.

## DOCX extraction

The extractor now reads required WordprocessingML directly from the DOCX ZIP with XML hardening and size checks, so unsupported embedded EMF media does not make otherwise valid text unreadable. The regression fixture was inspected and contains `word/media/image1.emf`; its regression test and genuinely truncated-ZIP test pass. The real client file was not mutated or reprocessed. A direct production-storage extraction check remains open because the production artifact was repaired late in the audit and the storage metadata lookup did not return before the audit window closed.

## Recovery tooling

`documents:reprocess {document} --actor=` now delegates to the shared reprocessor, requires an explicit authorized actor, and queues the recovery chain. Documentation is in `docs/INCIDENT_RECOVERY.md`. Feature tests prove actor validation, authorization and dispatch. A disposable production document was not created: that would have added a production write after the deployment incident, and the audit stopped after read-only verification.

The frontend already has a Needs Review retry action using the bearer-authenticated reprocess endpoint.

## Soft-delete safety

Root cause remains **inconclusive**. The model uses `SoftDeletes`; the partial workspace/hash uniqueness migration and upload duplicate handling were reviewed. The safety-net warning path was tested with a disposable soft-deleted document and logging assertion: jobs now warn when an ID is hidden by the global scope instead of silently returning.

## Real client documents (final read-only query)

| Document | deleted_at | status | extracted_text_length | KPI count |
|---|---|---:|---:|---:|
| `01a0c40d-e5a4-738a-98d3-8018af833a1c` | NULL | Ready | 7,591 | 0 |
| `01a0c6f7-a21c-72bf-94cb-4d1a60e0b437` | NULL | Ready | 19,705 | 0 |

The second document contains substantial numeric/percentage and indicator-like content (65 percentage patterns, 396 numeric patterns, 56 target/indicator terms), while its KPI count remains zero. Processing history shows an `ai_analysis` stage left processing and later attempts skipped by the old unchanged-hash guard. This is a genuine unresolved data-recovery item, not evidence that the report lacks KPI-like content.

## Changes and deployments during this audit

- Application/build/test changes are in commits `68f79de`, `6aba04b`, and `fae12a9`.
- `CA_BACKEND` deployed successfully as `f4ccbf34-8b88-4b00-a77b-5e59d02cd38c`.
- `ca-horizon-worker` deployed successfully as `a23a8481-6605-42cb-9470-15b7c7b1848e`.
- Redis was not deployed or modified.
- No frontend source or deployment change was required.

## Remaining open items

1. Change the Railway custom worker start command to `sh bin/start-production.sh worker` and the API command to `sh bin/start-production.sh web` through the Railway service configuration mechanism; the installed CLI refused the edit even though the repo startup script is correct.
2. Re-run the real stored client DOCX through the current extractor after confirming its production storage key.
3. Queue a controlled recovery of the second client document’s incomplete AI analysis, then verify KPI persistence with production approval and a fresh read-only query.
4. Run a disposable production reprocess end-to-end test only after the worker start-command configuration is corrected.
