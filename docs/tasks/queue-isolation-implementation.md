# Queue isolation and global Anthropic admission: implementation report

Branch `chore/queue-isolation-provider-gate`, based on `main` at `b0fc4ef91bbf7414a9cdcc59ebfe3360595824ee`.

The audit was written at `4254b5a`. The newer HEAD adds only the extraction-truncation work and docs; no queue isolation had landed.

Nothing has been pushed, deployed or committed. No Railway service, env value or region was touched.

**ENV VARIABLE NAMES CHANGED: NO.**

## 1. Topology before and after

| | Before | After |
|---|---|---|
| Queues | `default`, `extraction` (catch-all) | `extraction`, `synthesis`, `default` |
| Production supervisors | default: 3 processes, 60s timeout. extraction: 2 processes, 360s timeout | default: 2 processes, **180s**. extraction: 2 processes, 360s. synthesis: 2 processes, 360s |
| Total PHP worker processes | 5 | 6 |
| Global Anthropic cap | none (a racy 40/min cache counter only) | `ANTHROPIC_MAX_INFLIGHT` (default 2), atomic Redis leases |

The default pool drops from 3 to 2 processes, as Phase 2 specified. Set `HORIZON_DEFAULT_MAX_PROCESSES=3` to keep 3.

## 2. Job-to-queue routing

The map lives in `app/Support/QueueTopology.php`. Every dispatch site uses `QueueTopology::for()`, and the same map is registered with `Queue::route()`, so a dispatch without `onQueue()` still lands correctly.

| Queue | Jobs |
|---|---|
| extraction | `ProcessDocumentChunkJob`, `ClassifyDocumentTypeJob`, `ExtractDocumentEntitiesJob`, `DetectDocumentRisksJob`, `DetectDocumentDeadlinesJob`, `GenerateInsightsJob`, `OcrPageBatchJob` (Claude Vision is the default OCR provider), `ProcessDocumentVisualJob`, `CompareDocumentsJob` |
| synthesis | `MergeDocumentEvidenceJob`, `GenerateDocumentSummaryJob` (incremental and legacy) |
| default | `ScanUploadedFileJob`, `ExtractDocumentTextJob`, `AnalyzeEmbeddedVisualsJob` (no provider call; its injected client is unused), `GenerateEmbeddingsJob` (Voyage), `RetryDeferredBillingEvent`, `SendTrackedDeadlineReminder`, mail notifications |

Dispatch paths covered:
- the upload chain and the rescan chain (each chained link carries its own queue);
- the legacy `Bus::batch`, which runs on extraction, while its `finally` summary goes to synthesis;
- pump: chunks to extraction, merge and summary to synthesis;
- merge completion, to synthesis;
- self-retries (chunk transient retry to extraction; summary transient retry and fallback to synthesis);
- reprocess and re-analysis;
- recovery and resume;
- the visual planner;
- comparison;
- billing retries.

## 3. Horizon (`config/horizon.php`)

Timeout ordering per pool: longest job < worker timeout < Redis `retry_after` (390s).

| Pool | Longest job | Worker timeout |
|---|---|---|
| default | 120s (text extraction, visual planning) | 180s |
| synthesis | 330s (merge) | 360s |
| extraction | 330s (legacy entities) | 360s |

- `maxProcesses` comes from `HORIZON_DEFAULT_MAX_PROCESSES`, `HORIZON_EXTRACTION_MAX_PROCESSES` and `HORIZON_SYNTHESIS_MAX_PROCESSES`, all defaulting to 2.
- Waits (LongWaitDetected thresholds, from the audit's Stage A triggers): `redis:default` 30s, `redis:synthesis` 60s, `redis:extraction` 120s.
- `horizon:snapshot` was not scheduled. It now runs every five minutes in `routes/console.php`, next to `docintel:resume`.

## 4. Per-document concurrency (unchanged)

`DOCINTEL_EXTRACTION_CONCURRENCY=2` still bounds outstanding queued and running chunks per document. The window is computed under the Postgres document-row lock in `pump()`.

Split children stay within the existing whole-document split-parent budget (`max_split_parents_per_root=2`), which is unchanged.

The gate also uses the same number as its per-document permit limit.

## 5. Global Anthropic semaphore

Implemented in `app/Services/AI/ProviderGate.php` and `ProviderGate/RedisGateStore.php`.

**Storage:** one Lua script per admission, on the queue's shared Redis connection, so every web process and worker replica uses one semaphore.
- Leases are sorted-set members `token|document`, scored by expiry. Expiry uses the Redis server clock (`TIME`, with `redis.replicate_commands()` for Redis 6), so replicas don't need synchronized clocks.
- Expired leases are purged on every acquire and counted as `lease_expired`.
- Each lease has a unique owner token. Release is `ZREM` of that exact member, called in `finally`.

**Lease TTL is 240s.** It must exceed the longest permit hold. Whole-job holders are bounded by their job timeouts: chunk 180s, summary 200s, comparison 120s, visual 90s. A test enforces this invariant. A crashed holder's permit expires; no permit leaks permanently.

**Admission rules,** evaluated atomically in this order:
1. **Global:** active leases must be below `ANTHROPIC_MAX_INFLIGHT`.
2. **Priority:** synthesis and interactive web callers that were denied are owed the next free permits, so bulk callers can't take a permit they need. Web claims are withdrawn when the request gives up.
3. **Per-document:** a document may hold at most `DOCINTEL_EXTRACTION_CONCURRENCY` permits.
4. **Fairness:** a document that already holds a permit can't take the last free one while another document is waiting at the gate, or (for chunks) has queued extraction work in the database.

**Two entry points:**
- `call()` guards a single HTTP request. Each retry and repair re-acquires, and no permit is held while sleeping between retries. It reuses a permit already held by its job.
- `hold()` admits a whole job before it claims any durable state. This is used by the chunk job (token count plus extraction share one permit), the summary job (with priority), comparison and visual jobs.

**When busy:**
- Queued jobs dispatch a delayed copy of themselves (15s plus 0–15s jitter, same queue, same arguments, rest of the chain transferred) and finish normally. No retry attempt, claim, cost reservation or AI run is created, and no stage is started.
- Calls that weren't admitted up front (legacy batch jobs, OCR pages, merge context) wait at most 10s, then raise `ProviderBusyException`:
  - OCR batches defer, resuming from their page checkpoints;
  - merge-context resolution is optional, so it settles at $0 and marks itself `provider_busy`;
  - legacy batch members take their existing failure path, because a batch member can't be cloned safely (see risks).
- Web Q&A waits at most 4s, then returns **503** "AI is busy right now. Please try again in a moment." with `Retry-After: 15`. No internal detail is exposed.
- Token counting never waits; callers fall back to their conservative local estimate.

A busy result is never recorded as a provider failure: no `document_ai_runs` row and no cost. Rate-limit handling is otherwise unchanged.

### Anthropic call paths covered

Every provider HTTP request goes through the gate:
- `/v1/messages` via `callWithRetry`. That covers incremental extraction, synthesis and its repair, legacy classify/entities/risks/deadlines/insights, OCR (Claude Vision), chart vision, comparison, merge-context resolution, KPI adjudication, document Q&A, and every retry;
- `/v1/messages/count_tokens`;
- `/v1/models` (`docintel:verify-models --check-access`).

## 6. Backlog recovery

**Before:** any `queued` chunk or merge older than 10 minutes was reset and redispatched, even if its Redis message was only waiting in a backlog.

**After:**
- Every dispatch of a chunk, merge or visual unit issues a durable `dispatch_token`, plus `dispatched_at` (new migration). The message carries the token.
- After the same 10-minute minimum age, recovery resets a unit only if no message with its token is still in Redis. It scans the ready, delayed and reserved structures of all three queues once per run, cached for 60s.
- **Backed-up queue:** the message is found, so nothing is redispatched.
- **Lost dispatch:** no message is found, so the unit is redispatched once with a new token.
- A late or duplicate old message is dropped before any provider call (`Superseded chunk delivery ignored`).
- If Redis can't be inspected (non-Redis driver or a Redis error), the old age rule applies. It stays safe, because the new token makes any surviving old message a no-op.
- Messages from before the deploy (no token) are still accepted, and the database claim keeps them single-run.
- Deferred copies keep their token, so a long provider-capacity wait never looks like a lost dispatch.
- `running` units older than 7 minutes still become `uncertain`. Paid calls are never replayed automatically.

## 7. Fairness at Stage 1 (2 extraction workers, per-document 2, global 2)

| Documents | Behavior |
|---|---|
| 1 | Both permits go to the one document, which nobody else wants: same throughput as today. |
| 2 | A takes 1 permit. A's second chunk sees B's queued work, defers, and B takes the other permit: 1 call each. |
| 5 | 2 calls in flight, at most one per document while others are queued. Deferred chunks re-enter at the queue tail, so documents progress in rough round-robin order. |
| 20 | Same: 2 in flight. Each document gets one call at a time in queue order, so first-chunk wait grows with position. No document can hold both permits while others wait. Split children only enter through the per-document window and join the tail, so they can't starve older documents. |

At every load, completion work (synthesis) is owed the next free permit, so extraction backlog cannot starve it.

No persistent scheduler was built: the per-document window, the global cap and the fairness rule were sufficient in tests. Each denied chunk does cost one extra queue message per deferral; it makes no provider call, no token count and no database write.

## 8. Multiple replicas

Every Horizon replica starts all three supervisors, so process counts multiply: 2 replicas × (2+2+2) = 12 processes. The Redis semaphore is the only provider cap and does not multiply, so it stays authoritative. Chunk claims, the per-document window, cost accounting and billing remain Postgres-backed. The atomicity test runs 12 separate OS processes with separate Redis connections against real Redis: the observed peak was exactly the cap.

## 9. Retry and 429 handling

- `Retry-After` now accepts delta-seconds or an HTTP-date. A past or unparseable value means 0.
  - Typed (incremental) paths cap it at 120s.
  - Legacy in-process sleeps keep their 30s cap and bounded attempts.
- A date-valued header used to become 0. Chunk retries now honor it (tested with a +90s date).
- Busy deferrals are not counted as provider failures or retry attempts.

## 10. Billing and idempotency

Billing semantics are unchanged. The tests cover:
- duplicate chunk delivery (same token): one provider call;
- duplicate summary and merge delivery;
- the merge-retry one-debit test;
- comparison deferral, which reserves nothing;
- the full `SubscriptionBillingTest`, `WorkspaceCredits*`, `CreditPurchaseTest` and `PaymentLifecycleInvestigationTest` suites.

Pint (`--dirty`) reformatted imports in the touched `SubscriptionService.php`, converting fully-qualified exception names into `use` lines. It is cosmetic, with no behavior change. The only functional edit in that file is the billing retry queue lookup (still `default`).

## 11. Observability (metadata only)

- `php artisan docintel:queue-status [--json]` (read-only):
  - per queue: ready, delayed and reserved counts, and oldest ready age;
  - provider: max in-flight, active permits, waiting documents, priority waiters, lease TTL, and counters (`acquired`, `denied_global`, `denied_document`, `denied_fairness`, `denied_priority`, `lease_expired`).
- Logs:
  - `Anthropic admission deferred` / `granted after wait` (reason, active, max, wait_ms);
  - `Job deferred for provider capacity`;
  - `Lost queue dispatch recovered`;
  - `Superseded chunk delivery ignored`.
- Chunk `queue_wait_ms` is now measured from the original dispatch, so provider waits are visible. Merge diagnostics gained `queue_wait_ms`.
- Existing per-call `document_ai_runs` latency and failure class (429 classified as transient, timeout), synthesis and cost logs are unchanged.
- No text, excerpts, prompts, responses or keys are logged.

## 12. Files

- **New:**
  - `app/Support/QueueTopology.php`, `app/Support/QueueInspector.php`
  - `app/Services/AI/ProviderGate.php`, `app/Services/AI/ProviderGate/{RedisGateStore,MemoryGateStore}.php`
  - `app/Exceptions/ProviderBusyException.php`, `app/Jobs/Concerns/DefersWhenProviderBusy.php`
  - `app/Console/Commands/QueueStatus.php`
  - `database/migrations/2026_10_06_000001_add_dispatch_tokens_to_document_chunks.php`
  - `tests/Feature/{QueueTopologyTest,ProviderGateTest,QueueBacklogRecoveryTest}.php`
- **Changed:**
  - config: `config/horizon.php`, `config/document_intelligence.php`, `routes/console.php`
  - providers and services: `app/Providers/AppServiceProvider.php`, `app/Services/AnthropicClient.php`, `IncrementalPipeline.php`, `VisualPlanner.php`, `ContextResolver.php`, `DocumentReprocessor.php`, `DocumentComparisonService.php`, `SubscriptionService.php` (queue lookup only)
  - jobs: `ProcessDocumentChunkJob`, `GenerateDocumentSummaryJob`, `MergeDocumentEvidenceJob`, `ProcessDocumentVisualJob`, `CompareDocumentsJob`, `OcrPageBatchJob`, `ExtractDocumentTextJob`, `DispatchesIntelligenceChain`
  - other app code: `DocumentChunk` model, `DocumentQaController`, `DocumentUploadController`
  - tests: `tests/TestCase.php` (in-memory gate per test), `DocumentRoutingAndMergeScaleTest` and `EntityTimeoutBudgetTest` (they now read the effective pool config)
- **Migration:** `document_chunks` gains `dispatch_token` (uuid, nullable) and `dispatched_at` (timestamp, nullable), plus an index on `(stage, status)`. It is additive and safe to run before the code deploys.

## 13. Env variables added

- `ANTHROPIC_MAX_INFLIGHT`, default 2
- `HORIZON_SYNTHESIS_MAX_PROCESSES`, default 2
- `HORIZON_DEFAULT_MAX_PROCESSES`, default 2

Existing variables are unchanged: `DOCINTEL_EXTRACTION_CONCURRENCY`, `HORIZON_EXTRACTION_MAX_PROCESSES`, `REDIS_QUEUE_CONNECTION` (also used by the gate).

## 14. Remaining risks

- **Legacy `Bus::batch` jobs** (classify, entities, risks, deadlines) can't be deferred by cloning: finishing early would complete the batch member and fire `finally` too soon. They wait at the boundary for up to 10s, then use their existing failure path (tries=2). Under long saturation, small legacy documents may record failed stages. Recommended follow-up: route small documents through the incremental direct path, or give batch members `release()` with a `retryUntil` policy.
- **Workers waiting idle:** up to 10s per call for non-admitted worker calls (OCR pages, legacy, merge context) while the cap is full.
- **Deferral churn:** each busy chunk makes one delayed queue message per 15–30s per waiting chunk. That is cheap but visible in queue depth.
- **Fairness is approximate:** rough round-robin, not a strict scheduler. Under continuous arrivals above drain rate there is no upload backpressure yet.
- **Inspector cost:** the recovery inspector scans all queued payloads once per run (cached 60s). That's fine at hundreds of messages; revisit at tens of thousands.
- **Unmeasured limits:** account RPM/ITPM/OTPM, worker CPU/RAM, Postgres connections and Redis memory were not measured. No capacity above 2 is claimed safe.
- **Region:** web/worker in `us-west2` and Postgres/Redis in `sfo`. No measured latency indicates a problem; no change made.

## 15. Recommended initial Railway settings (Stage 1, Option A)

**Option A (recommended):** one `ca-horizon-worker` service, one replica, running one Horizon master with three supervisors.

Set explicitly, or rely on the defaults:
- `HORIZON_EXTRACTION_MAX_PROCESSES=2`
- `HORIZON_SYNTHESIS_MAX_PROCESSES=2`
- `HORIZON_DEFAULT_MAX_PROCESSES=2` (or 3 to keep today's default pool)
- `DOCINTEL_EXTRACTION_CONCURRENCY=2`
- `ANTHROPIC_MAX_INFLIGHT=2`

**Option B (later, only if measured CPU/RAM isolation needs it):**
- an extraction worker service, plus a synthesis/default worker service;
- each needs a supervisor filter (for example a role env var deciding which supervisors to define), so every queue still has exactly one set of listeners;
- each service needs its own ClamAV volume if it scans;
- the semaphore stays shared through Redis.

## 16. Ramp 2 → 4 → 6 → 8

At each stage, raise `ANTHROPIC_MAX_INFLIGHT` and `HORIZON_EXTRACTION_MAX_PROCESSES` together, one step at a time, after at least one busy day. Keep per-document at 2 (consider 3 only at a global cap of 6 or more).

Check:
- p95 oldest age per queue (`docintel:queue-status`, Horizon waits);
- first-chunk wait and `queue_wait_ms`;
- synthesis and merge wait;
- document completion time;
- 429 rate and provider latency (`document_ai_runs`);
- timeout and unknown-usage rate;
- deferral counters;
- worker CPU/RSS, Redis memory, Postgres connections and latency;
- spend per hour (`docintel:ai-usage-report`).

Roll back a step if 429s persist, timeouts or unknown-usage rise, or queue age doesn't improve. Do not claim 8 is safe without these measurements and confirmed account limits.

## 17. Safe deployment sequence

1. Run the migration (additive) through the normal release process.
2. Deploy web and worker together. The new code dispatches to `synthesis`, so the worker must be running the new Horizon config, with all three supervisors, before or with the web deploy.
3. `php artisan horizon:terminate` on the worker so the new supervisors start. In-flight jobs finish; the 390s visibility window protects them.
4. Old `extraction`-queue merge and summary messages still drain, because extraction listeners remain and the jobs are queue-agnostic. Old messages without a token are still accepted.
5. Confirm with `php artisan horizon:list` / `horizon:supervisors` and `php artisan docintel:queue-status`. Every queue should have a supervisor, and active permits should be ≤ 2.
6. Roll back by redeploying the previous release. The new columns are nullable and ignored by old code. Synthesis-queue messages left behind need a temporary listener (`php artisan queue:work redis --queue=synthesis --once` repeated), or should drain before rollback.

## 18. Post-deploy verification

1. `docintel:queue-status`: three queues listed, `max_inflight=2`, `active` ≤ 2, `lease_expired` not growing.
2. Upload one large document and one small document, plus one Q&A.
   - Logs should show chunks on extraction and merge/summary on synthesis.
   - `active` never exceeds 2.
   - Q&A answers, or returns 503 "busy" only while saturated.
3. Upload 3–5 documents at once. Each should get a first chunk within roughly position × chunk time. `denied_fairness` should increase while `denied_document` stays rare, and synthesis should never wait more than about 60s for a permit.
4. Hold the queue above 10 minutes old (for example with a paused extraction supervisor in a test window). `Lost queue dispatch recovered` should not appear for queued chunks whose messages exist.
5. Billing: each completed document has exactly one debit in `credit_ledger` and no `billing_operations` duplicates.
