# Audit evidence reconciliation — 2026-09-12

Status: **Tier 1, item 1 is blocked on owner-supplied environment and report evidence.** This is a provisional ledger, not a closure report. No deployed finding is marked resolved.

Source capture began at 2026-09-12 17:43 UTC. Git, source, framework internals, and local test results were inspected during this session. No Railway/Neon CLI, authenticated live connection, remote health request, migration, seeding, upload, or deployment was used. Remote-tracking refs were not fetched. Earlier conversation claims of live access or payment recovery are historical evidence only.

## Repository identity

| Repository | Initial branch and revision | Initial working tree | Remote evidence |
| --- | --- | --- | --- |
| Backend `CA_BACKEND` | `main`, `d05fdc197c2977e446b13753a2169348f1868b4b` | Clean | Locally cached `origin/main` agrees; current remote/deploy unknown |
| Frontend `CA` | `main`, `ef0af9e` | Clean | Locally cached `origin/main` agrees; current remote/Netlify deploy unknown |

Backend `main` contains `196dfa7`, `c1c5041`, `f695e8b`, and `5229114`, checked using `git merge-base --is-ancestor`. The separate local `deployment` branch is not evidence of a deployed revision. Session changes are on `audit/tier1-evidence-20260912`; no push or merge was performed.

The requested initial backend `git log --oneline -20` was:

```text
d05fdc1 added referral setting
5229114 Update ImageDetector
2d2431a Merge Track 1 + Track 2 + TB-1 audit fixes and repo cleanup into main
f7da05d Repo cleanup: remove one-off audit/debug scripts, stray empty file; relocate manual test fixtures to tests/fixtures/manual/
196dfa7 Track 1 + Track 2 audit fixes: AUTH-1 through AUTH-5, VAL-1 through VAL-3, INFRA-1, F1-P1, FU-1/FU-3/FU-4/FU-5, TB-1 prompt fix, plus test/seeder/trigger corrections found during this session's review
c1c5041 fix: gate dev-only auth logging behind explicit config flag, not APP_ENV string match
679609b initial payment upload
a8cc2ff record the run only after parsing/validation succeeds, not before.
4b09280 Fix: move artisan cache commands to runtime, not build time — env vars weren't available at build
426a69b Debug: override CMD again to test Redis connect() on staging image
3d2d2a2 Revert debug CMD, restore php artisan horizon
73a3bd3 Debug: override CMD to keep container alive for SSH investigation
35bc4dc Add Dockerfile with poppler-utils and composer fix (staging test)
cb096e3 Test: confirm staging branch isolation
07e0ae1 Enforce target/threshold exclusion from chart data points in code; populate PDF page count (was always 0)
fad581b Add chart_vision to document_ai_runs purpose check constraint
4a53422 Fix double base64-encoding bug in DocxImageDetector causing Anthropic API to reject all DOCX embedded images
5a2e1a6 Revert: remove diagnostic Dockerfile accidentally pushed to production
6eb158d Diagnostic: test Redis private network connectivity in isolation
639aafb Revert: remove broken Dockerfile, restore working Railpack auto-detection build
```

Commit subjects containing “staging,” “production,” or “confirm” establish neither a successful deployment nor current environment identity.

## First dependency: staging isolation

At the initial 17:43 UTC capture, the two supplied accounts conflicted: an isolated Neon branch with a canary versus later identical database endpoints. Neither was accepted as current evidence. A committed `.neon` file and `cb096e3` could not settle this. The owner's subsequent updates establish the service-variable split recorded as ENV-2 and confirm genuine Neon branch isolation and correct staging web routing. Worker runtime and end-to-end isolation remain unverified.

Remaining evidence for the wider ledger:

- Capture UTC time, Railway environment/service, deployed SHA/deployment ID, `DB_HOST`, `DB_DATABASE`, `DB_HOST_POOLED`, and Laravel's effective database host/name after staging worker redeployment. Retain comparable service evidence for the other deployments; this does not reopen the owner's confirmed staging web routing.
- Archive the owner's branch/endpoint metadata when available. Neon architecture and staging web routing are now confirmed from the owner's reported CLI verification; the original raw JSON is not attached here. `neondb` names and pooled/unpooled host spellings alone were never used as proof.
- Obtain current and relevant historical Railway deployment records, plus Netlify's frontend deployment revision. Fresh identity settles current isolation; historical deployment/environment evidence is separately needed to qualify old staging claims.

Exact owner-run commands and expected results: [live evidence checks](AUDIT_LIVE_CHECKS_2026_09_12.md). No canary or other live test data is requested before identity is established.

### ENV-2 — Staging Horizon worker pointed at production database

**Status: Blocked — Neon branch architecture and staging web routing confirmed; staging worker variables corrected; worker runtime and end-to-end isolation unverified.** The initial ENV-2 entry was captured at **2026-09-12 18:38 UTC**; the additional owner-supplied Neon confirmation was incorporated at **18:52 UTC**. Earlier evidence states are preserved below.

Initial evidence source: the owner's dated update says Railway variables were inspected for all four services; Neon branch IDs came from `neonctl branches list --project-id fragrant-cherry-99998400` and branch inspection. At that point direct endpoint metadata, deployment output, and a worker-executed canary had not been supplied. The following table retains that initial observation, before the later confirmation.

| Railway environment / service | Configured host reported 2026-09-12 | Expected endpoint → branch (not directly cross-confirmed yet) | Runtime evidence |
| --- | --- | --- | --- |
| staging / `CA_BACKEND` | `ep-cold-cake-axtm6s8c.c-4.us-east-2.aws.neon.tech` | `ep-cold-cake-axtm6s8c` → `br-hidden-wildflower-axoalt7m` | Not supplied; configuration appears intended |
| staging / `ca-horizon-worker` before correction | `ep-shy-paper-axsw5ecr.c-4.us-east-2.aws.neon.tech` | `ep-shy-paper-axsw5ecr` → `br-divine-lab-axjoi7y0` | Owner reports production endpoint was configured; exact affected deployment/time interval pending |
| staging / `ca-horizon-worker` after correction | `ep-cold-cake-axtm6s8c.c-4.us-east-2.aws.neon.tech` | `ep-cold-cake-axtm6s8c` → `br-hidden-wildflower-axoalt7m` | Variables corrected on 2026-09-12; worker **not redeployed/restarted or runtime-verified** |
| production / `CA_BACKEND` | `ep-shy-paper-axsw5ecr.c-4.us-east-2.aws.neon.tech` | `ep-shy-paper-axsw5ecr` → `br-divine-lab-axjoi7y0` | Not supplied; configuration appears intended |
| production / `ca-horizon-worker` | `ep-shy-paper-axsw5ecr.c-4.us-east-2.aws.neon.tech` | `ep-shy-paper-axsw5ecr` → `br-divine-lab-axjoi7y0` | Not supplied; configuration appears intended |

Latest evidence source: the owner subsequently confirmed branch details using `neonctl branches get ... --output json`. Production is `br-divine-lab-axjoi7y0`; staging is the genuine branch `br-hidden-wildflower-axoalt7m`, forked from production at **2026-09-11T12:03:03Z**, with copy-on-write database isolation. The owner also confirms staging web's staging-endpoint routing is correct. These are owner-supplied CLI conclusions; no CLI invocation or live query was performed by the agent, and no raw JSON transcript is represented as archived here.

| Component | Current status | Evidence / limit |
| --- | --- | --- |
| Neon branch architecture | **CONFIRMED ISOLATED** | Owner-confirmed genuine staging branch fork at `2026-09-11T12:03:03Z`; distinct production/staging branch IDs |
| Staging web DB routing | **CONFIRMED CORRECT** | Owner-confirmed `CA_BACKEND` staging routing to `ep-cold-cake-axtm6s8c` on the staging branch |
| Staging worker saved Railway variables | **CORRECTED** | Owner changed staging `ca-horizon-worker` from production endpoint `ep-shy-paper-axsw5ecr` to staging endpoint `ep-cold-cake-axtm6s8c` on 2026-09-12 |
| Staging worker runtime connection | **NOT YET VERIFIED** | Redeployment/restart and effective connection evidence still required |
| End-to-end worker isolation | **NOT YET VERIFIED** | Real staging upload, worker processing, and cross-branch record comparison still required |

ENV-2 is now narrowed to **Railway worker database configuration**, not Neon branching/aliasing. The fork timestamp is not proof of correct historical worker routing and does not establish the exact misconfiguration window.

Historical reconciliation must also distinguish inherited data from later worker writes: records already present at the fork can legitimately exist on both branches. Matching document IDs alone do not prove a cross-branch write; retain creation/update times, processing evidence, and branch lineage when attributing each historical test.

Chronology and conclusions:

1. Earlier sessions claimed isolated staging and successful worker-backed checks. A later session claimed a shared production database. The initial local audit could not reconcile either assertion from git.
2. The new variable observations identify a web/worker configuration split that could explain the conflicting accounts. They do not establish when that split began or whether either historical account was accurate at its own capture time.
3. The owner corrected staging worker variables on 2026-09-12. An existing Horizon process can retain its old connection; saved variables are not a runtime correction.
4. Previous worker-backed “staging verified” claims are now explicitly **contaminated/unreliable pending individual reconciliation**, including queued OCR, chart/vision analysis, insights, embeddings, document status changes, and other worker database operations. This is not a claim that every queued job successfully wrote production data: a job may have found no matching document, failed, or run through another consumer. Locate records and deployment/queue evidence per test.
5. Preserve historical test documents, processing jobs, AI-run rows, audit records, queue job IDs/failures, and retained deployment/log evidence until their actual branch/location and relationship are established. No cleanup of historical records, queue clearing, retries, or bulk reprocessing is authorized by this update.
6. The later branch-get confirmation establishes genuine Neon isolation and correct staging web routing. It resolves the architecture question without retroactively validating worker-backed tests. Those tests may have modified production before the worker correction; branch creation and saved-variable correction are neither a worker restart nor a canary result.

With branch architecture and staging web routing confirmed, closure still requires, in order: explicitly approved deployment of **only** staging `ca-horizon-worker`; post-deployment effective-connection evidence from the new container; and a minimal upload processed by that worker, with resulting IDs present on staging and absent on production. A fresh Tinker process alone cannot prove the connection held by an existing Horizon process. The worker canary must close that gap. Queue routing must also be attributable to staging before submitting it; a shared queue could invalidate an otherwise correct database mapping. Historical worker-backed claims retain their contamination flag pending individual reconciliation even after a future canary succeeds.

The exact next checks and held deployment action are in [ENV-2 recovery checks](ENV_2_WORKER_ISOLATION_2026_09_12.md). Per the owner's ordering, the executable canary fixture, upload, SQL comparisons, and cleanup will be prepared only after steps 1–3 have actually succeeded. No test has been uploaded or dispatched in this session.

## Findings and claims ledger

“Committed” below refers to local git history, not deployment. The fresh full suite passes after the test-only correction described below. A passing suite is not proof of a specific edge case unless that test is identified. No application-fix deployment is newly confirmed by this update; the owner-confirmed Neon architecture and staging web routing are explicitly distinguished from pending worker behavior.

| Finding/claim | Git/source evidence today | Test/evidence qualification | Correction or remaining evidence |
| --- | --- | --- | --- |
| ENV-1 / P1 item 7: isolated staging | `.neon` in `196dfa7`; `cb096e3`; owner-confirmed branch-get result and staging web routing | Neon architecture confirmed isolated; staging fork `2026-09-11T12:03:03Z`; web routing confirmed correct | Overall item remains blocked on worker runtime/canary and historical reconciliation |
| ENV-2: staging Horizon worker configured for production | Owner's 2026-09-12 Railway-variable observations plus later branch-get confirmation; infrastructure evidence, not a deployed git fix | Root cause narrowed to Railway worker configuration; saved variables corrected; runtime and end-to-end isolation not yet verified | Worker-backed historical staging claims contaminated; preserve records. Approved worker-only deploy → runtime check → branch canary still required |
| AUTH-1: verification gate | Middleware and protected route groups in `196dfa7` remain present | `9679a78` tests purchase initialization/verification returning 403 before email verification and succeeding afterward | Confirms those local boundaries; not an exhaustive route matrix or live proof |
| AUTH-2: Google ownership handoff | `c1c5041`, AuthController resets password and deletes tokens for an unverified pre-existing account | Google auth tests pass; no dedicated assertion of both old-password and token revocation was identified | Commit is not solely a logging change despite its subject |
| AUTH-3: password-reset throttling/response consistency | `c1c5041`, reset limiter and generic failures | General auth suite passes; no dedicated reset-throttle boundary test identified | Historical reset-token reuse probe is independently flawed; see older reports below |
| AUTH-4: verify-email enumeration | `c1c5041`, generic invalid/already-verified/missing-user response handling | Existing auth suite passes; no complete three-way comparison identified | Live behavior unconfirmed |
| AUTH-5: distributed signin attempts | `c1c5041`, additional email-wide limiter | AuthApiTest tests repeated signin failures; not a multi-IP attack simulation | Do not equate single-IP test coverage to the distributed edge case |
| VAL-1: change-email normalization | `196dfa7`, ChangeEmailRequest normalization before validation | Existing email normalization tests pass; do not establish every change-email variant | Live behavior unconfirmed |
| VAL-2: download extension mapping | `196dfa7`, DocumentDownloadController type-to-extension map | Document API tests pass; exhaustive format matrix not established | Live behavior unconfirmed |
| VAL-3: Google key algorithm allowlist | `196dfa7`, GoogleTokenVerifier allows RS256 keys | Signed local RSA fixtures pass, including audience/error cases | Dedicated algorithm-confusion negative test not identified |
| F1 / F1-P1: admin listing scope | `196dfa7`, Admin/UserController scopes via current workspace membership | RoleManagementTest: 5 pass, workspace fixtures are present | Corrected fixtures are committed; a dedicated two-workspace admin-list isolation assertion was not identified in that class |
| F3: database audit immutability | `196dfa7`, initial trigger and narrower workspace FK-nullification migration | Full suite migrates successfully locally | No dedicated tampering/FK-cascade test established; live function definition pending |
| FU-1: storage path/extension defense | `196dfa7`, upload controller and DocumentStorageService sanitize extension, validate workspace UUID shape | Upload tests pass | No live file-path probes performed |
| FU-3: stored-file cleanup on creation failure | `196dfa7`, cleanup around failed database creation | General upload tests pass | Failure-specific storage compensation test not identified |
| FU-4: upload dedup race | `196dfa7`, upload unique-violation handling plus `2026_09_11_102653...` partial unique index | Local suite applies migrations successfully | Current live index definition/history and a dedicated upload race test remain unconfirmed |
| FU-5: filename column length | `196dfa7`, upload request length validation | General upload tests pass | Dedicated filename boundary test not identified |
| FU-8: document type constraint | August widening migrations already accept eight types; placeholder migration existed in `196dfa7` | Fresh local migrations support current document tests | See migration-history correction below; live catalog evidence pending |
| FU-9: exception-related failure | Only named in supplied narrative; exact finding/source mapping not supplied | No closure inferred | Need combined report; exception audit is item 3 |
| INFRA-1: disable local file-serving routes | `196dfa7`, current `config/filesystems.php` has `serve => false` | Installed/locked Laravel 13.14.0 has signature guards on private GET and PUT handlers | **Correct severity narrative:** source does not support arbitrary unsigned anonymous read/write. No historical exploit or deployed route evidence supplied |
| TB-1: Q&A placeholder and v2 activation | `196dfa7`, AnthropicClient, DocumentQaPromptSeederV2, DatabaseSeeder registration | DocumentQaTest: 7 pass, including explicit question-placeholder regression | v2 seeder also activates v2 itself; this is not the claimed separate inert-seed/explicit-activation workflow. Live row state pending |
| TB-2: fixture cleanup | Cleanup commit `f7da05d` is present; document-author random-password logic predates it (`14c087e`) | No live account inspection/deletion evidence supplied | Need exact TB-2 report; do not infer database fixture cleanup from source or file deletion |
| TB-3: adversarial probes | Narrative says two probes did not complete | No completed live outputs available | Remains pending Tier 3; external Redis reachability is not evidence of an app defect |
| TB-7: quantitative accuracy | Narrative describes real v4/v6 defects and successful live checks | No corresponding v4–v6 code or current live results available | Claimed observations remain historical assertions pending artifacts |
| document_insights v1 | `a420bdf`, inline AiPromptSeeder version 1 | Source present | Live version/hash pending |
| document_insights v2 | `556714e`, inline AiPromptSeeder version 2 | Source present | Live version/hash pending |
| document_insights v3 | `f695e8b`, inline version 3 includes target/threshold and description/data rules | Source present; no separately named V3 seeder | Live version/hash pending |
| document_insights v4/v5/v6 | No matching versioned seeder files, registrations, or history found in local refs | No reproducible committed v4–v6 implementation identified | Cannot say absent live; request metadata plus source from the other working session |
| Prompt release discipline | AiPromptSeeder ends with `AiPrompt::activate('document_insights', 3)` | Reading confirms behavior; no live seeder run | Rerunning this seeder could replace live v6 with v3. Do not use it to reconcile drift |
| `5229114` image support/dedup | Actual diff adds ImageFileDetector and AnalyzeEmbeddedVisualsJob integration/title filtering only (2 files, 57 insertions) | No extraction/dispatch/OCR file changed by this commit | Partial fix confirmed in git; no claim the text-empty image reaches vision. Item 5 remains untested |
| Cleanup: 22 scripts / 5 fixtures | `f7da05d` deletes 22 scripts and a stray empty file | Git shows **one** tracked fixture rename into tests/fixtures/manual | Five relocated fixtures cannot be corroborated by tracked history |
| Historic 132/134 suite and production health | Supplied narrative only | No archived test/deploy output attached to the combined report | Replace current baseline with actual results below; ENV-2 specifically contaminates worker-backed staging claims, not the provenance of the local test run |
| Persistent logging fix | Current default is stack, whose default channel list is single/local file | No redeploy/log-retention evidence | Not applied as a repository default; live LOG_CHANNEL overrides unknown. Item 4 remains open |
| Q&A exception swallowing | Current DocumentQaController::ask catch returns a generic error without reporting the exception | Existing test checks response sanitization, not logging | Still present by source inspection; fix and broader catch audit belong to item 3 |
| Personal/referral/payment follow-up | Backend `d05fdc1`; frontend `ef0af9e` | Personal, referral, purchase, concurrency and frontend tests pass after fixture correction | No new live payment, webhook, credit balance, or deployed frontend confirmation this session |

Unlisted AUTH/FU/TB/F-series identifiers must not be guessed. The combined Day 1/Day 2 report and follow-up ledgers have been requested. Until supplied, this cannot be called an exhaustive ledger of every finding.

### Migration-history correction already established by git

`196dfa7` added `XXXX_XX_XX_XXXXXX_add_type_enum_and_hash_uniqueness_to_documents.php`. Merge `2d2431a` omitted/deleted it relative to its audit branch parent (`git diff 2d2431a^2 2d2431a -- <file>`). Current main retains the empty `2026_09_11_053723...` stub and the `2026_09_11_102653...` index migration. Earlier August migrations already widen the type constraint.

Therefore the supplied description of all three files currently remaining is stale. This does not prove what the live migrations table records. No rename, squash, migration-history rewrite, or migration run is proposed until owner-supplied staging and production history/catalog output arrives (item 2).

### INFRA-1 source qualification

`composer.lock` in both `196dfa7` and current main pins Laravel 13.14.0. Installed `Illuminate/Filesystem/ServeFile.php` requires a valid relative signature for private disks; `ReceiveFile.php` requires both `upload=true` and a valid relative signature. The local disk has no public-visibility setting. These checks run inside the route handlers even though the routes have no Sanctum middleware. Keep `serve=false`; the comment claiming zero-authorization anonymous private read/write overstates the evidence. This is source evidence, not a reconstruction of an unknown deployed framework/configuration.

## Other available reports and their limits

The combined report was not located. Available reports were read without treating their old data as current:

- `docs/auth-module-audit-2026-07-28.md`: explicitly local (27 tests and manual local curl). Its own section 3.3 admits the reset-token reuse probe never consumed the real token. “VERIFIED CLEAN” must be qualified as **not established by that probe**. “No SQLi/XSS found” is bounded by the tested auth surface, not comprehensive proof. The accepted junk-signup risk was conditional on an internal-only deployment; later public signup/referrals make that assumption stale.
- `docs/AUDIT_2026_09_08.md` in `a8cc2ff`: explicitly a local application configured to a remote Neon database, not a separately verified production deployment. Its 57 migrations, active insights v2, workspace-config counts, BI catalogs, and prompt hashes are dated snapshots. It explicitly claims no fixes and no two-tenant live login proof. Preserve those limits. Listed findings on prompt provisioning, deploy order, AI config/routing, retries/validation, scanner responses, BI eligibility/deletion/provisioning remain findings to reconcile against the combined report and later tiers; they are not automatically closed by the later omnibus commit.
- `docs/day-10-remaining-work.md`: historical checklist, not a deployment transcript.
- `docs/DEPLOYMENT_RUNBOOK.md`: records a prior credential-exposure/rotation requirement but supplies no evidence that rotation occurred. Do not paste its broad `config:show database` output; the new evidence commands allowlist non-secret fields.

## Fresh local verification and committed test correction

Unmodified backend `d05fdc1`, with working local PostgreSQL/Redis and a dummy Google client ID: **227 tests, 2 failures, 2 risky tests**. Both failures were referral purchase tests expecting success while their newly signed-up buyer was still unverified; AUTH-1 correctly returned 403. These were not Redis failures.

An earlier runner attempt also cleared the non-secret Google client ID and produced ten additional Google-fixture failures. That was a local harness error, not evidence of a deployed OAuth defect. The shared fixture now sets its own dummy client ID.

Test-only commit **`9679a78bae02a0adddb3d3de99ed5cdf3764bd38`**:

- Both referral purchase scenarios assert the unverified request is forbidden, then obtain the code from the faked notification and verify through the actual API before purchasing.
- Tests retain the buyer-identity, purchase completion, and exactly-once referral assertions.
- Google RSA token fixtures configure a dummy audience independently of `.env`.

Final run on those exact test contents: **227 tests, 1,005 assertions, zero failures/errors/skips**, PHP 8.5.4 / PHPUnit 12.5.29. All nine HealthCheckTest cases pass. Frontend at `ef0af9e`: **12 tests, zero failures**. Actual output is archived in [local test results](audit-evidence/2026-09-12/local-test-results.txt).

Isolation was explicitly checked before the backend run: PostgreSQL `127.0.0.1:5433`, database `ca_document_intelligence_test`; Redis `127.0.0.1:6389`; cache/session/mail array, queue sync, storage local, config-cache path nonexistent, external provider credentials cleared, Sentry/Pulse disabled. No production-like label was used as isolation evidence. Tests migrated only this disposable local database. Test runner details are in the archived result file.

## Handoff status — tier remains incomplete

| Item # | What | Status | Evidence | Notes for next session |
| --- | --- | --- | --- | --- |
| 1 | Reconcile claims and staging identity | Blocked | Git/source findings; `9679a78` local suites; Neon architecture and staging web routing confirmed; ENV-2 saved-variable correction | Need approved staging-worker redeployment, runtime checks, worker canary; preserve historical contamination finding. Combined report and historical deploy/prompt/health evidence still pending |
| 2 | Migration hygiene | Not started | Existing history discrepancy noted under item 1 | Obtain live migration names/catalog output before proposing changes |
| 3 | Exception reporting convention and fixes | Not started | Existing Q&A catch noted only | Start after items 1–2; no application edits yet |
| 4 | Persistent log target | Not started | Default still local-file stack | Config change and redeploy-retention test need later production confirmation |
| 5 | Text-empty image reaches vision | Not started | Required `5229114` diff checked | Read exact dispatch branch and run feature/live tests in order; do not alter detector, dedup, or prompts |
| 6–12 | Tier 2 | Not started | No new audit or live probes in this tier | Finish Tier 1 first |
| 13–16 | Tier 3 | Not started | Prompt provenance discrepancy recorded only | Finish Tier 2 first; keep AI test volume deliberate |

Nothing is ready to be marked resolved in production. No production deployment is requested for this documentation/test-only branch.
