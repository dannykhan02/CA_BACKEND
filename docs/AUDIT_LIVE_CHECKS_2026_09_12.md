# Owner-run evidence checks — Tier 1 item 1

These steps collect evidence; they do not authorize deployment, migrations, prompt activation, or test uploads. Run them yourself and paste the actual labeled output. The agent has no live connection. Do not paste passwords, connection URLs, API keys, tokens, or full environment/config dumps.

**2026-09-12 update:** the owner reports staging web was configured for the staging endpoint while staging Horizon was configured for the production endpoint. Staging worker variables have been corrected, but its running process has not been restarted/verified. Start with the ordered [ENV-2 recovery checks](ENV_2_WORKER_ISOLATION_2026_09_12.md). This original checklist remains evidence still needed for the wider ledger; it does not override the new deployment/canary gates. Preserve all historical test records pending branch attribution.

**Subsequent Neon confirmation, incorporated at 18:52 UTC:** the owner verified staging is a genuine branch forked from production at `2026-09-11T12:03:03Z`. Neon branch architecture is **CONFIRMED ISOLATED**; staging web DB routing is **CONFIRMED CORRECT**; staging worker saved variables are **CORRECTED**. Worker runtime and end-to-end isolation are **NOT YET VERIFIED**. The next ENV-2 gate is the approved staging-worker redeployment, not a repeat of the branch architecture check. The original collection instructions below remain for evidence retention and other unresolved findings.

## 1. Establish current database identity first

From your locally linked Railway backend project, enter staging:

```sh
railway ssh --environment staging
```

Choose the backend web service if prompted. Inside its deployed container, run this exact allowlisted diagnostic:

```sh
php artisan tinker --execute='$c = DB::connection(); dump([
    "captured_at_utc" => gmdate("c"),
    "railway_environment" => getenv("RAILWAY_ENVIRONMENT_NAME"),
    "railway_service" => getenv("RAILWAY_SERVICE_NAME"),
    "deployment_id" => getenv("RAILWAY_DEPLOYMENT_ID"),
    "deployed_commit" => getenv("RAILWAY_GIT_COMMIT_SHA"),
    "DB_HOST" => getenv("DB_HOST"),
    "DB_DATABASE" => getenv("DB_DATABASE"),
    "DB_HOST_POOLED" => getenv("DB_HOST_POOLED"),
    "effective_host" => $c->getConfig("host"),
    "effective_database" => $c->getConfig("database"),
    "actual_database" => $c->selectOne("SELECT current_database() AS name")->name,
]);'
```

Exit that remote shell, enter production, and repeat the diagnostic:

```sh
railway ssh --environment production
```

Repeat for separate Horizon/worker services. A web service and its workers may use different environment variables. If the CLI cannot select the correct service/environment, paste its error instead of running against a default target. No raw `railway variables` or database URL dump is needed.

In Neon Console, map the effective host from each output to its **branch ID and compute endpoint ID**, recording which Railway service/environment uses it. Paste those IDs and host mappings. Two `neondb` names are not proof of sharing; a pooled hostname and its direct counterpart are not proof of isolation. Separate compute endpoints on the same branch still share data. Different branch IDs are required before relying on staging data isolation.

Expected evidence: all staging web/worker connections map to the intended staging branch, and production connections map to a different branch. If they match, report the match; do not repoint either environment yet. Any corrective infrastructure/configuration action will be prepared separately as **PRODUCTION CHANGE — confirm before running/deploying**.

## 2. Establish what is deployed and what was deployed historically

In Railway's deployment history for each web/worker service, paste these fields for the current deployment and the deployments cited by the earlier audit verification:

```text
Captured at UTC:
Environment / service:
Deployment ID:
Git commit SHA:
Deployment status:
Deployment timestamp UTC:
Health-check result and timestamp, if retained:
```

If the command above returns no deployed SHA, the dashboard's deployment source revision is required. A build timestamp or git branch name alone cannot establish source provenance. Missing/expired historical records should be reported as unavailable, not reconstructed from commit messages.

For Netlify, paste the same current deployment ID/status/timestamp/source SHA from the frontend site's Deploys page. Local snapshots to compare are backend `d05fdc1` and frontend `ef0af9e`; the new audit branch contains only tests/documentation and has not been pushed.

## 3. Prompt and migration metadata — no writes

After mapping the database identities, run this SQL in each corresponding Neon's SQL Editor and label the results with the Railway environment and Neon branch/endpoint IDs. If both environments share one branch, say so and identify that fact with the query output. These queries expose metadata, not document contents or credentials.

```sql
BEGIN TRANSACTION READ ONLY;

SELECT now() AT TIME ZONE 'UTC' AS captured_at_utc,
       current_database() AS database_name;

SELECT name, version, active, provider, model, temperature,
       md5(template) AS template_md5,
       md5(COALESCE(system_prompt, '')) AS system_prompt_md5,
       created_at, updated_at
FROM ai_prompts
WHERE name IN ('document_insights', 'document_qa')
ORDER BY name, version;

SELECT migration, batch
FROM migrations
WHERE migration LIKE '%type_enum_and_hash_uniqueness%'
   OR migration LIKE '%hash_uniqueness%'
   OR migration LIKE '%widen_document_type%'
   OR migration LIKE '%audit_log%'
ORDER BY migration;

SELECT conname, pg_get_constraintdef(oid) AS definition
FROM pg_constraint
WHERE conrelid = 'public.documents'::regclass
  AND contype = 'c'
ORDER BY conname;

SELECT indexname, indexdef
FROM pg_indexes
WHERE schemaname = 'public'
  AND tablename = 'documents'
  AND indexname = 'documents_workspace_file_hash_unique';

SELECT t.tgname, t.tgenabled, pg_get_triggerdef(t.oid) AS definition
FROM pg_trigger t
WHERE t.tgrelid = 'public.audit_logs'::regclass
  AND NOT t.tgisinternal;

SELECT pg_get_functiondef(p.oid) AS definition
FROM pg_proc p
JOIN pg_namespace n ON n.oid = p.pronamespace
WHERE n.nspname = 'public'
  AND p.proname = 'prevent_audit_log_mutation';

COMMIT;
```

Expected from the supplied history: document_insights versions 1–6 with only 6 active, and document_qa v2 active. These live states remain hypotheses to check, not accepted facts. The MD5 fields are content-comparison identifiers, not security checks. **Source update, 2026-09-13:** v4–v6 seeders and registrations are now confirmed in commit `9e608ea`, incorporated into this audit branch by `31981f3`; the earlier source-absence statement applied to checkout `d05fdc1` and the refs inspected then. The source files are available for comparison, so obtaining missing wording from the other session is no longer a prerequisite. Current deployed hashes and active-version metadata still require owner-run verification; see the [dated source reconciliation](AUDIT_EVIDENCE_2026_09_12.md#prompt-source-reconciliation--2026-09-13).

The migration rows establish what Laravel thinks was applied. Constraints/index/trigger definitions establish the actual schema. Current git has already dropped the literal-placeholder migration at merge `2d2431a`; do not rename anything, update the live migrations table, or rerun that migration. A migration plan must wait for these outputs. Any later live migration-history action is **PRODUCTION-ADJACENT CHANGE — confirm before running**.

**Do not run `db:seed` or AiPromptSeeder to obtain these results.** Current AiPromptSeeder explicitly activates insights v3 and could undo a live v6 selection.

## 4. Current health evidence

After identity/source evidence, run one health request for each backend environment from your own machine. Replace the placeholder with that environment's actual backend base URL:

```sh
CA_AUDIT_API_URL='https://REPLACE-WITH-EXACT-BACKEND-HOST'
date -u +%Y-%m-%dT%H:%M:%SZ
curl --silent --show-error --max-time 20 --include "${CA_AUDIT_API_URL%/}/api/health"
```

Paste the timestamp, environment label, HTTP status, and JSON. Expected: HTTP 200, `success: true`, `status: healthy`, and database/redis/queue/storage all healthy. A 503 or timeout is evidence to investigate; do not count it as success.

The source health controller temporarily writes/deletes a small file on the **default filesystem disk**, despite its “read-only” comment. This is the health check requested for this assessment. It does not upload a document or invoke AI. It tests database reachability, Redis ping, queue size access, and a storage operation; it does **not** demonstrate that workers consume jobs, that document storage on R2 works when the default disk is local, that prompts match git, or that a specific security fix works live. Match results to the current deployment ID; this is not a retroactive health check of old deployments.

## 5. Missing report source

Provide the combined Day 1/Day 2 report and follow-up ledgers as text or accessible local file paths, plus any retained command outputs used to mark findings “fixed & verified.” The available July 28 and September 8 repo reports do not define every AUTH/VAL/FU/TB/ENV finding. Without the combined report and live evidence, the provisional ledger cannot be made exhaustive or marked closed.
