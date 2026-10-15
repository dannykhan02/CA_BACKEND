# DocIntel hierarchical synthesis reliability audit (2026-10-15)

## Scope and evidence

Branch `fix/hierarchical-synthesis-reliability` starts at clean `310bc73` (`fix/large-synthesis-coverage`). No production database row, provider, deployment, or environment variable was changed. The supplied production counts describe a different processing run from the 799-row deterministic fixture. Railway project status was reachable, but a metadata-only `railway ssh` query did not return and was interrupted. Therefore the two production failures and the 81 extraction rejections cannot be assigned exact causes from live diagnostics in this audit.

## Pipeline trace

1. `SynthesisCoverage::plan` (`app/Services/AI/Incremental/SynthesisCoverage.php:11`) reads eligible `document_evidence` for the active pipeline key, groups by 40-page window (or source offset) and kind, orders by materiality type, and packs whole compact records under the request byte bound and maximum batch count. It reports oversize and batch-limit records as uncovered.
2. `SynthesisBatches::processNext` (`app/Services/AI/Incremental/SynthesisBatches.php:28`) computes an identity from each exact batch, checks revision ownership and pipeline key under a document row lock, creates or reuses its checkpoint, reserves a cost bound while protecting the final synthesis and repair, then makes one provider call. Completed, failed, budget, and uncertain checkpoints are skipped on redelivery. A running checkpoint makes another worker wait.
3. `AnthropicClient::summarizeEvidenceBatch` (`app/Services/AnthropicClient.php:1153`) asks for up to eight verbatim claims. `structuredCall` (`:572`) records each response or transport failure as a `document_ai_runs` row. `decodeJsonContent` (`:1059`) distinguishes malformed JSON, truncation, and nonfinal provider stops. Unknown evidence IDs and changed claims are discarded by exact ID and claim comparison; they are never published as facts.
4. `EvidenceBudget::compactSynthesis` (`app/Services/AI/Incremental/EvidenceBudget.php:123`) counts every item in a completed batch as considered, even if the final presentation selects only representatives. Failed or unprocessed batch items are uncovered. `uncovered_groups` folds them by group and reason. A final evidence-only request may consider one original record if no intermediate batch succeeded.
5. `SynthesisCheckpoint::claim` (`app/Services/AI/Incremental/SynthesisCheckpoint.php:14`) owns the final request, fallback level, budget reservation and duplicate claim. `GenerateDocumentSummaryJob::process` (`app/Jobs/GenerateDocumentSummaryJob.php:81`) writes the typed summary, marks genuine partial coverage, and changes Ready under an owner lock. `DocumentRevisionPublisher::publish` (`app/Services/Intelligence/DocumentRevisionPublisher.php:92`) atomically verifies required revision artifacts and switches the published pointer. `DocumentIntelligenceService::publishedView` (`app/Services/DocumentIntelligenceService.php:34`) pins summary, evidence, and coverage to that published revision for readers.

## Confirmed defects and corrections

| Finding | Evidence | Correction |
| --- | --- | --- |
| Any exception other than `AiProcessingException` in a batch was labeled `validation`, conflating malformed provider output, parser defects and other failures. | Previous `SynthesisBatches::processNext` catch; `AnthropicStructuredOutputException` carries `outputStatus`. | Preserve explicit `malformed_output`, `invalid_schema`, `truncated`, and `provider_error`; label unexpected internal exceptions `internal_validation`. |
| A JSON object with missing or non-list `findings` could be saved as a completed batch, because the parser iterated `findings ?? []`. | Previous `AnthropicClient::summarizeEvidenceBatch`; deterministic `{}` response test. | Reject malformed batch shape with `invalid_schema`. Exact evidence ID and claim checks remain unchanged. |
| Received malformed JSON or structural batch output was terminal after one paid attempt, even when one correction could safely succeed within budget. | Batch request uses `single_response` and one checkpoint attempt; final-summary repair already uses reserved, bounded calls. | Allow one further checkpoint attempt only for received `malformed_output` or `invalid_schema`, with a correction prompt and a fresh cost reservation. Transient retries retain the existing configured attempt limit. Truncation, timeout, provider errors, invalid claims, and internal defects do not receive format repair. |
| A retry could become `pending` before the prior paid attempt settled, allowing another worker to claim it during settlement. | Previous batch catch updated status before `finally` called `settleCost`. | Settle first, then expose `pending`, `failed`, or `completed`; retain the document-locked claim and idempotent settlement. |
| Failed batch rows lacked group/input/error detail. | Previous failed row held only `failure_class`. | Persist metadata-only `_failure` with group, input count, code, exception type, attempt and recoverability. Extend `docintel:pipeline-report` with batch identity prefix, mapped inputs and provider run statuses. No source text, prompts or response bodies are added. |
| A stale summary worker could save an old `ai_pipeline` snapshot before final publication checks. | Previous level and `evidence_trimmed` saves in `GenerateDocumentSummaryJob`; fallback and worker-failure saves checked owner outside a lock. | Lock the document, confirm candidate revision and pipeline key, and merge changes into the current row before saving. Existing final artifact and pointer checks remain. |
| Synthesis stage could say `unavailable` when a summary relation was absent despite persisted `synthesis=completed` and 36 uncovered items. | `CoverageStateBuilder::stages` (`app/Services/Intelligence/CoverageStateBuilder.php:79`) tested summary availability first. | Mark such a completed stage `partial` when omissions exist; if coverage is complete but its summary artifact is missing, report `unknown` and name the missing artifact. An actually unfinished stage with no summary remains `unavailable`. |

The terminal error metadata is intentionally categorical. It does not include exception messages because provider and parser messages may contain response fragments. Existing `document_ai_runs` contain stop reason, status, token usage, and whether a response was received.

## Production interpretation and unresolved checks

- The supplied `703 = 667 + 36` is internally consistent. It describes accepted, eligible evidence in that run, not the separate 799-row fixture.
- Two failed checkpoints can produce one 36-record `page:2:definition` entry because coverage aggregates active batch items by **group and reason**, not by checkpoint. Both active batches may share that group and reason. A failed historical checkpoint outside the active plan is another possible explanation. The individual counts, hashes, and levels must be checked in the production checkpoint rows and reconstructed active plan; they were unavailable here.
- In the old code, `failure_class=validation` on a batch means a non-`AiProcessingException` escaped. It does **not** establish a schema error or invalid citation. `AnthropicStructuredOutputException` could contain `malformed_output`, `truncated`, or `provider_error`; a generic exception could signal an internal parser defect. `document_ai_runs` and response-status metadata are needed to distinguish them. Invalid IDs and altered claims are filtered and counted as `invalid_claims`, rather than causing that batch class.
- `synthesis_level=3` is the capped `synthesis_reductions` value (`EvidenceBudget::level`, `:223`). Level 3 config uses `source=none`, so `source_text=omitted` is expected (`sourceContext`, `:267`). Intermediate batching forces the final merge to the last level (`GenerateDocumentSummaryJob`, around `:120`); level 3 alone does not prove a timeout or budget failure.
- Published revision consistency is enforced by pointer publication and `publishedView`, but the supplied production revision ID and coverage counters could not be compared to the stored revision row. Check that the document pointer, revision `pipeline_key`, revision `ai_pipeline.coverage`, and typed summary revision ID match before drawing a production conclusion. A missing summary relation could be a read pin, stale-summary flag, or stored-artifact issue; the stage-label correction does not infer which.

## Extraction follow-up

The 81 dropped records are observable **returned-record rejections** from completed extraction leaves, not evidence that the source contained only 81 additional facts. `EvidenceSchema::validate` (`app/Services/AI/Incremental/EvidenceSchema.php:148`) records rejection classes and reasons, including schema shape, quote/span grounding and date errors. `docintel:validation-report` (`app/Console/Commands/DocumentValidationReport.php:15`) summarizes these without reading source text. A saturated chunk either reached the configured record limit (`AnthropicClient`, around `:205`) or retained a truncated partial response with no continuation (`IncrementalPipeline`, around `:719`). The four production flags cannot be divided between those causes without leaf metadata. Omitted lower-priority source facts cannot be counted exactly from either flag. No extraction behavior was changed.

Follow-up task: run the read-only validation report for the published pipeline, reconcile the 81 against `rejection_classes` and `rejection_reasons`, list the four saturated leaf identities and their accepted counts, and compare them with extraction capacity diagnostics. Add deterministic fixtures for the dominant rejection reason and saturated table/list chunks **only if** the production metadata demonstrates a direct defect. Keep invalid citations rejected.

## Deterministic measurements and tests

| Fixture | Before this branch | After this branch |
| --- | ---: | ---: |
| 799-row complete fixture using old one-request evidence trim | 11 / 799 included | 11 / 799 on legacy mode (compatibility retained) |
| 799-row complete hierarchical fixture under sufficient budget | 799 / 799 considered | 799 / 799 considered |
| First malformed batch response in the 799-row fixture | Terminal `validation`; that batch uncovered | One format correction, same checkpoint; its evidence considered after success |
| Terminal timeout/truncation fixture | Failed batch items uncovered | Failed batch items uncovered |
| Partial stage fixture with 667 considered and 36 uncovered | `unavailable` if summary relation absent | `partial`; 36 remain uncovered |

Targeted synthesis tests: 15 passed, 254 assertions. Adjacent revision, extraction, summary and API suite: 98 passed, 2,461 assertions. Additional pipeline report, extraction and checkpoint suite: 108 passed, 930 assertions. Final targeted revision and synthesis check: 23 passed, 295 assertions. These suites overlap. PHP syntax checks, Pint, and `git diff --check` passed. No paid provider calls were made; HTTP tests fake responses and the test base blocks stray requests.

## Rollout and rollback

1. Deploy through the normal release process with `DOCINTEL_REVISION_V1` and `DOCINTEL_HIERARCHICAL_SYNTHESIS` set according to the existing production rollout. The latter already gates hierarchical routing; this branch adds no new flag or migration. Keep all queue workers on one compatible code version and let in-flight jobs settle before switching versions.
2. Run one new **test** document with sufficient allowance; verify every planned batch has one terminal checkpoint, all provider attempts appear in `document_ai_runs`, committed spend stays under `budget_usd`, and the published revision coverage equals the API coverage. Check a deliberately partial fixture separately; do not reprocess the cited production document automatically.
3. If the new behavior regresses, roll back the application release after draining/restarting workers under the existing procedure. Retain checkpoint and evidence tables. Turning off `DOCINTEL_HIERARCHICAL_SYNTHESIS` is an emergency routing fallback with the known large-document coverage loss, not an equivalent rollback. Do not run migration down or deploy workers that cannot deserialize queued jobs.

## Future read-only Railway verification

Run one SSH command at a time, substituting a **new test document** UUID. These commands do not dispatch jobs or call a provider:

```bash
railway status
railway ssh --service CA_BACKEND --environment production -- php artisan docintel:pipeline-report TEST_DOCUMENT_UUID --json
railway ssh --service CA_BACKEND --environment production -- php artisan docintel:validation-report TEST_DOCUMENT_UUID --json
```

After this branch is deployed, inspect `synthesis_batches` in the pipeline report: each failed prefix now has `group`, `input_count`, `failure`, and `provider_runs`. Compare the reported `document.published_revision_id` and `document.pipeline_key` with the published revision row using a read-only database query. The `validation-report` supplies the extraction rejection breakdown. Railway SSH metadata access was not verified in this audit because the attempted command stalled; use the normal authorized Railway access path if needed.
