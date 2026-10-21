# Northstar V10 live evaluation preflight

21 October 2026 · branch `feat/docintel-v10-live-eval-prep` · **no V10 paid request has been sent**.

## Frozen inputs and selection

| Input | SHA-256 / selection |
|---|---|
| Original Northstar PDF | `4507eb76cb2dfce4be55c23e6572a70e473a1bab520071734065f64755384f2f` |
| Current real PDF extracted text and evidence revision | `a4722cfdb3795de38553953d1c12521053a5368232bf3a0cbcd4a952712fa8da` |
| Source gold, 29 facts | `82e7204118b729ea7a7ee89668f8c72cd4abce10b942a298de60a488325477c3` |
| V10 prompt template | `2be7ab2584d154df68437fb48b0dcc20e9ebecdf07e628c76793a01c8e1f88d0` |
| Rendered V10 prompt with this PDF | `9bb9433df3f53439ec41428609b7bb2ae5dc1254a435737f7dbc50ede2d50717` |
| Model / output cap | `claude-haiku-5-5` / 14,000 tokens |

The PDF and gold hashes were checked directly; the extracted-text hash and 126-span count passed through `DocumentTextExtractor` and `EvidenceGrounding` in the recorded harness. The prompt hashes are asserted before dispatch. The gold file is read only after the model response, and the exact rendered-prompt hash is checked at the HTTP boundary. V10 activation occurs only inside the isolated test transaction; production V9 selection is unchanged.

## Isolation and request budget

The test requires PostgreSQL `docintel_v10_eval` at `127.0.0.1:55449` in the existing local `storage/framework/testing/docintel-v9-pg` cluster. This dedicated database was cloned from the isolated test schema without a migration. It had zero users and documents before testing, and zero users, documents, V10 prompt rows and AI runs after rollback. A different host, port, database, cluster path or nonempty user/document set aborts before inference. The test fakes queues, uses the in-memory provider gate and transaction rollback, and permits no other HTTP destination.

The V10 evaluation calls the real `AnthropicClient::extractDocumentInsights` with `singleResponse: true`: one structured response and one transport attempt. `NorthstarV10RequestGuard` checks the HTTPS endpoint, exact model, exact one-message prompt digest, output cap and request-body shape at the HTTP boundary. A second dispatch, corrected prompt, changed model, extra system/options or cost beyond **$0.01** fails before HTTP dispatch. The guard is process-scoped; it is not an Anthropic account spending limit.

The recorded request contains at most **23,644 input-token-bound units**, conservatively counted as serialized bytes plus framing, and 14,000 output tokens. The application pricing guard reserves the highest configured short-context input/cache-write rate of $0.125 per million and $0.50 per million output, yielding a **$0.009956 maximum estimated request cost**. No caching option is present in the permitted payload. [Anthropic's Haiku 5.5 model page](https://platform.claude.com/docs/en/models/haiku-5-5/overview) currently lists $0.10/$0.50 per million regular input/output tokens for prompts up to 100,000 tokens and $0.125 per million for a five-minute cache write. Actual billed usage remains unknown until a live response.

The evaluation credential is read from `DOCINTEL_V10_EVAL_ANTHROPIC_KEY`, or from the existing dedicated `DOCINTEL_V9_EVAL_ANTHROPIC_KEY` if needed. The local `.env` is parsed only for those two values; its database or other settings are never loaded into the test process. A dedicated V9 evaluation key is present locally; its validity has not been checked by contacting the provider. Keys are never written to the diagnostic summary.

## Exact authorized command

Run only after explicit approval of **one paid Claude Haiku 5.5 request with a $0.01 process cap**. The command uses the dedicated evaluation credential already available locally and runs only the live test:

```bash
cd /home/collins/boys/CA_BACKEND
env APP_DEMO_MODE=0 DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55449 DB_DATABASE=docintel_v10_eval DB_USERNAME=postgres DB_PASSWORD= DB_URL= DOCINTEL_V10_EVAL_LIVE=1 DOCINTEL_V10_EVAL_APPROVED=1 DOCINTEL_V10_EVAL_BUDGET_USD=0.01 ./vendor/bin/phpunit tests/Feature/NorthstarV10LiveEvaluationTest.php --filter test_live_once_only_after_explicit_authorization --no-coverage
```

No command with `DOCINTEL_V10_EVAL_LIVE=1` has been run. The recorded tests use `Http::fake` and prove that complete, truncated, invalid-schema and provider-error responses each cause at most one request. The live test is skipped by default; an approval flag and dedicated credential are required in addition to the explicit live selector.

## What the live result will contain

`storage/framework/testing/docintel-v10-evaluation/live-*.json` will hold the attempt bounds, stop reason, usage, estimated cost, latency, parser status, exact candidate identities and resolved citation spans, grounding and integrity ineligibility reasons, stored evidence, strict 29-fact gold score, strict verified financial-series completeness, deterministic analytics and API serialization. Its sibling `.provider-response.json` will hold the **complete provider response body**, written before parsing. The directory is mode 0700, files mode 0600, and the entire directory is git-ignored. A truncated or invalid response retains its raw body and failure stage. Neither file is committed or printed in routine test output.

The raw provider response is parsed once by the real V10 validator. The parsed result is handed unchanged to `GenerateInsightsJob`, which runs identity resolution, grounding, evidence persistence, metric collection, integrity checks, charts, comparisons and composite API serialization. The test uses a mock only to replay that **same parsed live result** into the job, preventing a second paid call. It never substitutes the hand-written offline 14-observation fixture in live mode.

| Metric | Frozen V9 live | V10 live |
|---|---:|---:|
| Model observations | 7 | Pending |
| Factually correct observations, manual source review | 7 | Pending |
| Independently eligible | 1 | Pending |
| Rejected | 6 | Pending, with reasons |
| Strict exact gold matches out of 29, corrected scorer | 4 | Pending; terminology variations require manual review |
| Complete financial series | 0/4 | Pending |
| Trusted charts / deterministic comparisons | 0 / 0 | Pending |
| Incorrect field/citation associations | Four financial subject citations | Pending |
| Input / output tokens | 8,266 / 5,196 | Pending |
| Completion / truncation | `end_turn` / no truncation | Pending |
| Insights-call latency / usage-estimated cost | 20.880 s / $0.003425 | Pending |

The scorer reads `candidate.identity.period` and preserves strict value, label, unit, period, subject, basis, row and page checks. It does not turn a source-correct number with inadequate citations into verified evidence. After the live run, manually review candidate facts and disputed terminology against the PDF separately from the strict automated match count.

Failure localization follows the captured stages: missing observations or wrong facts originate in model generation; correct facts with wrong span IDs in citation selection; malformed or truncated JSON in provider output/validation; adequate citations rejected in grounding; eligible operands missing a series or comparison in analytical composition. Compare each with the offline 14-observation result before attributing fault to the model or backend.

## Mocked verification completed

- V10 guard unit tests: 6 passed, 22 assertions.
- V10 evaluation harness: 5 passed, 87 assertions; 1 live test skipped. Complete, truncated, invalid-schema and HTTP 529 cases made exactly one mocked request each.
- Shared V9 diagnostics and V10 guard unit regression: 18 passed, 165 assertions.
- Recorded V9 evaluation: 5 passed, 224 assertions.
- Existing V10 prompt and offline pipeline: 6 passed, 238 assertions.
- Direct V10 identity-scorer regression: 4 passed, 16 assertions; the twelve financial observations score as separate strict gold matches.

No paid inference, deployment, push, merge, schema migration, frontend change or Intelligence V2 activation occurred during preparation.
