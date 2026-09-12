# ENV-2 — owner-run worker isolation recovery

Prepared from local source at 2026-09-12 18:38 UTC and the owner's 2026-09-12 variable observations. This is a continuation of Tier 1 item 1 on `audit/tier1-evidence-20260912`. All live actions are performed by the owner. **Isolation remains blocked.**

## 1. Direct Neon endpoint mapping — run this first

```sh
date -u +%Y-%m-%dT%H:%M:%SZ
neonctl endpoints list --project-id fragrant-cherry-99998400
```

Paste the UTC timestamp, the table's column headings, and the complete rows for these two endpoint IDs. Required fields are **endpoint ID (`id`) and associated branch ID (`branch_id`)**. Include `host`, endpoint `type` (read/write versus read replica), `current_state`, and `pending_state` if shown. If the default listing omits the associated branch ID, paste what it does show; do not infer the mapping from the branch name or hostname. The associated branch ID from endpoint details will then be required.

| Endpoint ID | Required associated branch ID | Expected host |
| --- | --- | --- |
| `ep-cold-cake-axtm6s8c` | `br-hidden-wildflower-axoalt7m` | `ep-cold-cake-axtm6s8c.c-4.us-east-2.aws.neon.tech` |
| `ep-shy-paper-axsw5ecr` | `br-divine-lab-axjoi7y0` | `ep-shy-paper-axsw5ecr.c-4.us-east-2.aws.neon.tech` |

Expected values are hypotheses until this output agrees. Pause on a mismatch. Branch listing alone and identical/different `neondb` names do not settle the mapping.

## 2. Proposed deployment — held for explicit confirmation

**PRODUCTION CHANGE — confirm before running/deploying.** Although the target is staging, its old worker configuration points at the production endpoint. This action is not authorized merely because corrected variables were saved.

Scope for approval: apply the already-corrected database variables and replace/redeploy **only `ca-horizon-worker` in Railway environment `staging`**, using the intended existing source revision. No production service, staging web service, database schema, prompt row, or queue contents are included.

Use the Railway dashboard so service scope is explicit and does not depend on this session guessing your installed CLI's flags:

1. Open the existing backend project. Select environment **staging**. Open service **ca-horizon-worker**. Capture its current deployment ID/SHA and timestamp.
2. Review that service's pending changes. The database-variable correction must resolve to `ep-cold-cake-axtm6s8c.c-4.us-east-2.aws.neon.tech`, with the intended staging database name and credentials. Confirm this privately; paste no credentials or connection URLs.
3. Inspect **Settings → Deploy** for the **Pre-deploy Command** and **Start Command** (or whether it uses the Dockerfile default). Provide only command structure with any embedded secrets redacted. The checked-in Dockerfile ends in `config:cache`, `route:cache`, `view:cache`, then `horizon`. It does not run migrations. In contrast, `bin/deploy.sh` runs migrations and `horizon:terminate`; that script is not part of this proposed action. A dashboard override or older deployed source must be accounted for before approval.
4. If the database changes are still staged, open **Review changes**, ensure the deployment selection includes **only staging / ca-horizon-worker**, and deploy that service's changes **only after explicit approval**. If the review includes any other service or unrelated change, stop and paste the service/change summary; do not use a project-wide deploy.
5. If the corrected configuration has already been applied and only a new deployment is needed, open that service's **Deployments**, use the intended deployment's **⋮ → Redeploy**, and confirm the selected environment/service **only after explicit approval**. Do not use a restart of an old configuration as evidence that pending variable edits were applied.
6. Capture the resulting deployment ID, source SHA, creation/completion times, and status. Confirm the previous staging-worker deployment has stopped, including old replicas; no old production-connected Horizon process should remain alongside the replacement. Do not clear queues, retry old jobs, or delete old failures/test records as part of redeployment.

Before requesting approval, return step 1's endpoint output and the staging worker's deployment/start-command details. Then the intended action can be approved against a concrete target. A successful dashboard deployment alone is not isolation proof; proceed to the runtime check below after actual completion.

## 3. Read-only post-deployment runtime check

In Railway, select **staging → ca-horizon-worker → the NEW deployment** and connect to its shell. If using the Railway CLI from your linked backend project, this service/environment selection is explicit:

```sh
railway ssh --environment staging --service ca-horizon-worker
```

If the CLI rejects the selection or the dashboard points at an old deployment, stop and paste the non-secret error instead of falling back to an implicitly selected service. Run inside the new worker container:

```sh
php artisan tinker --execute='$c = DB::connection(); $c->beginTransaction(); try {
    $c->statement("SET TRANSACTION READ ONLY");
    $actual = $c->selectOne("SELECT current_database() AS name, pg_backend_pid() AS diagnostic_connection_pid");
    dump([
        "captured_at_utc" => gmdate("c"),
        "railway_environment" => getenv("RAILWAY_ENVIRONMENT_NAME"),
        "railway_service" => getenv("RAILWAY_SERVICE_NAME"),
        "deployment_id" => getenv("RAILWAY_DEPLOYMENT_ID"),
        "deployed_commit" => getenv("RAILWAY_GIT_COMMIT_SHA"),
        "app_environment" => config("app.env"),
        "configuration_cached" => app()->configurationIsCached(),
        "DB_HOST" => getenv("DB_HOST"),
        "DB_HOST_POOLED" => getenv("DB_HOST_POOLED"),
        "DB_DATABASE" => getenv("DB_DATABASE"),
        "effective_connection" => $c->getName(),
        "effective_host" => $c->getConfig("host"),
        "effective_database" => $c->getConfig("database"),
        "actual_database" => $actual->name,
        "diagnostic_connection_pid" => $actual->diagnostic_connection_pid,
    ]);
} finally { $c->rollBack(); }'
```

Expected: service `ca-horizon-worker`, environment `staging`, **new** deployment ID/SHA, effective host `ep-cold-cake-axtm6s8c.c-4.us-east-2.aws.neon.tech`, and effective/actual database names matching the intended staging database. A URL-based database setting or stale config cache can override `DB_HOST`; the effective fields matter. No raw connection URL is requested. A different host spelling needs endpoint attribution before accepting it.

**Evidence limit:** this boots a fresh Laravel process inside the new worker container. It establishes that process's resolved connection, not the private connection held by an older Horizon process. `diagnostic_connection_pid` belongs to Tinker. Combine it with old-deployment termination and the real worker canary; never label the Tinker result alone “Horizon's live connection verified.”

Run this additional allowlisted, read-only diagnostic in staging web, staging worker, production web, and production worker. It determines whether the upload is actually queued and which queue a worker can consume:

```sh
php artisan tinker --execute='$q = config("queue.default"); $driver = config("queue.connections.".$q.".driver"); $r = config("queue.connections.".$q.".connection") ?: "default"; $rc = $driver === "redis" ? (new Illuminate\Support\ConfigurationUrlParser)->parseConfiguration(config("database.redis.".$r, [])) : []; $ro = array_merge(config("database.redis.options", []), $rc["options"] ?? []); dump([
    "captured_at_utc" => gmdate("c"),
    "railway_environment" => getenv("RAILWAY_ENVIRONMENT_NAME"),
    "railway_service" => getenv("RAILWAY_SERVICE_NAME"),
    "deployment_id" => getenv("RAILWAY_DEPLOYMENT_ID"),
    "app_environment" => config("app.env"),
    "queue_connection" => $q,
    "queue_driver" => $driver,
    "queue_default_name" => config("queue.connections.".$q.".queue"),
    "redis_connection_name" => $driver === "redis" ? $r : null,
    "redis_url_resolved_host" => $rc["host"] ?? null,
    "redis_url_resolved_port" => $rc["port"] ?? null,
    "redis_url_resolved_database" => $rc["database"] ?? null,
    "redis_queue_prefix" => $rc["prefix"] ?? $ro["prefix"] ?? null,
    "horizon_prefix" => config("horizon.prefix"),
    "configured_horizon_environments" => array_keys(config("horizon.environments", [])),
]);'
```

Redis configuration may be URL-derived too; this uses the same URL parser as RedisManager without connecting or printing its URL/credentials. The output is configuration evidence, not proof of an established Redis socket. If your deployed version cannot provide these fields, paste the error without dumping configuration.

Before a canary, establish that staging web queues via Redis to the intended staging worker, and production workers cannot consume those same queue keys. Map private Redis hostnames to actual Railway Redis service/resource IDs in their environments; identical `redis.railway.internal` strings can resolve within separate private networks. Staging web/worker must agree on queue target/database/prefix. A different Horizon dashboard prefix alone does not isolate the Redis job queues. Check the staging worker has active supervisors for the upload's `default` and `extraction` queues using the read-only command below; the checked-in Horizon config declares `production` and `local`, not `staging`, so report actual APP_ENV/worker configuration rather than infer it from Railway's label. Do not change these settings without a separately reviewed action.

Inside the new staging worker container, this reads active Horizon supervisor metadata and prints only the fields needed to identify its queues and processes:

```sh
php artisan tinker --execute='dump([
    "captured_at_utc" => gmdate("c"),
    "container_hostname" => gethostname(),
    "deployment_id" => getenv("RAILWAY_DEPLOYMENT_ID"),
    "supervisors" => collect(app(Laravel\Horizon\Contracts\SupervisorRepository::class)->all())->map(fn ($s) => [
        "name" => $s->name,
        "master" => $s->master,
        "pid" => $s->pid,
        "status" => $s->status,
        "processes_by_queue" => $s->processes,
        "connection" => $s->options["connection"] ?? null,
        "queue" => $s->options["queue"] ?? null,
    ])->values()->all(),
]);'
```

Do not accept supervisors belonging to an old/different deployment as evidence of the new worker. Compare names/hostname, deployment context, and old-deployment termination; a shared Horizon namespace can display other consumers. This reads metadata, not queued payloads, and does not resume/pause/terminate/retry any job.

This queue identity check supports ENV-2 canary attribution; it does not start the broader Tier 2 queue security audit.

## 4. Worker-backed canary — not yet executable

Per the required sequence, prepare and submit the minimal canary **only after** endpoint metadata, approved worker redeployment, and post-deployment diagnostics have succeeded and their pasted output has been reviewed. No canary success is recorded now.

The next evidence packet must include a unique disposable fixture and its SHA-256, a dedicated disposable staging account/workspace, exact staging upload action, document/workspace/job identifiers, worker execution evidence, branch-labeled read-only SQL proving the generated/updated records are present on staging and absent on production, and precise cleanup instructions. Cleanup must preserve historical ENV-2 evidence and wait until the canary's actual branch identity is proven. Do not rerun old audit documents to produce this evidence.

## Current status

| Step | Status | Evidence still required |
| --- | --- | --- |
| Endpoint → branch mapping | Blocked | Two endpoint rows including associated branch IDs |
| Worker-only redeployment | Blocked | Explicit approval, exact command configuration, new deployment record and old deployment stopped |
| Effective worker/container connection | Blocked | New deployment's allowlisted output and queue attribution |
| Worker-backed branch canary | Not started | Design after preceding steps succeed; then actual owner-run output |

Tier 1 item 1 remains blocked. No Tier 2 work, deployment, runtime query, upload, or cleanup has been executed by the agent.
