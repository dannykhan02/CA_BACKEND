# Local V10 document evaluation

This command uses the Laravel `AnthropicClient`, V10 parser and validator, `GenerateInsightsJob`, evidence persistence, integrity rules, analytics composer, and composite API resource. It sends at most one insights inference request. It does not run the other AI enrichment jobs (OCR, embeddings, classification, entities, risks, deadlines, or narrative summary). No queue worker, Redis, or web server is needed for this command.

## One-time local setup

Use a disposable PostgreSQL cluster on loopback. Install PostgreSQL 18 and its `pgvector` extension before migrating. For a new cluster (PostgreSQL 18 tools shown):

```sh
cd CA_BACKEND
mkdir -p storage/framework/testing
/usr/lib/postgresql/18/bin/initdb -D storage/framework/testing/docintel-local-pg
/usr/lib/postgresql/18/bin/pg_ctl -D storage/framework/testing/docintel-local-pg \
  -o '-h 127.0.0.1 -p 55449 -c unix_socket_directories=/tmp' \
  -l storage/framework/testing/docintel-local-pg.log start
createdb -h 127.0.0.1 -p 55449 -U "$(id -un)" docintel_local_eval
```

If `.env` does not exist, copy `.env.example` to `.env`, run `chmod 600 .env`, and generate a local `APP_KEY` with `php artisan key:generate`. Set the ignored `.env` to `APP_ENV=local`, `APP_DEMO_MODE=0`, `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=55449`, `DB_DATABASE=docintel_local_eval`, `DB_USERNAME` to the local cluster role, `DB_PASSWORD` to its local password if any, `DB_URL=` and `DB_SSLMODE=disable`. Set `DOCUMENTS_DISK_DRIVER=local`. Put the Anthropic key in `ANTHROPIC_API_KEY` in this file and keep it mode `0600`. Never put the key on a command line or in a report. The evaluator rejects a remote database host, a non-evaluation database name, or a cluster outside `storage/framework/testing` or `/tmp/docintel-*`.

```sh
php artisan migrate --force
```

The command seeds V10 into this disposable database if needed and selects it only within the command process. It does not activate V10 in `ai_prompts`. Evidence resolution remains disabled by default in application configuration.

## Offline first: one frozen response, both modes

The existing restricted Northstar response can verify the workflow without a paid call:

```sh
php artisan docintel:evaluate-local \
  tests/Fixtures/Northstar_Q3_2026_Board_Performance_Review.pdf \
  --response=storage/framework/testing/docintel-v10-evaluation/live-20261021-204955-e0085f8755c8.json.provider-response.json \
  --model=claude-haiku-5-5 --prompt=10 --max-requests=1 --max-usd=0.01
```

That response file exists only in the restricted local evaluation artifacts from the earlier authorized Northstar run. If it is unavailable, use another captured `response-1.json` from this command with its matching source document and model. The command rejects a missing or mismatched model and blocks source documents that cannot fit the lossless normal span route. Scanned sources needing OCR are outside this one-request workflow.

## One authorized live document

After reviewing the source document, model, token cap and budget, run:

```sh
php artisan docintel:evaluate-local /absolute/path/to/document.pdf \
  --live --confirm-paid-call --model=claude-haiku-5-5 \
  --prompt=10 --max-requests=1 --max-usd=0.01
```

`--confirm-paid-call` is required for live inference. The configured key stays in `.env`; the command never prints it. `--model` accepts only models in `document_intelligence.approved_models` with configured prices. The Anthropic HTTP middleware checks the endpoint, model, exact rendered prompt digest, request count and worst-case price immediately before dispatch. The normal provider admission also checks the document budget. V10 uses one response and one transport attempt; provider errors, timeouts, malformed output and exhausted limits stop the run. If another model's worst-case request exceeds `$0.01`, raise `--max-usd` deliberately (maximum $1 per command run).

## Inspect and replay

The command prints a restricted `report.md` path. Its sibling `report.json` contains each observation, identity, units, period, evidence, refusal reason, verification decision, chart, financial series, comparison, usage record and composite API evidence mapping. `request-1.json` and `response-1.json` contain the exact bodies; no credential headers are stored. Each run directory is mode `0700`, each file mode `0600`, and `storage/framework/testing` is Git-ignored. The report records the two persisted evaluation document IDs and extraction ID. The off/on table uses the **same parsed provider response**. `paid_request_count` is zero for replay; `recorded_response_estimated_cost_usd` is the estimate from captured usage, while `estimated_api_cost_usd` is zero for an offline replay.

To replay a capture offline with the *same* source file and model:

```sh
php artisan docintel:evaluate-local /absolute/path/to/document.pdf \
  --response=storage/framework/testing/docintel-local-evaluation/run-YYYYMMDD-HHMMSS-ID/response-1.json \
  --model=claude-haiku-5-5 --prompt=10 --max-requests=1 --max-usd=0.01
```

The command itself runs resolution off, then on, with separately persisted evaluation documents. The `comparison` section in `report.json` and table in `report.md` show their counts. The saved source and results remain available in the disposable local database. The existing authenticated `GET /api/documents/{id}/intelligence` route can serve these documents to a user authorized for their evaluation workspace; the command also resolves that same `DocumentIntelligenceResource` directly into the private report.

Source verification, analytical eligibility and a proven table cell are **backend decisions**. Factual correctness requires an independently reviewed source and claim comparison; the report makes no automatic correctness claim.

## Verification

```sh
php artisan test --filter='LocalEvaluationCaptureTest|NorthstarV10OfflinePipelineTest|DocumentInsightsV10ContractTest|V10ObservationContractTest|NorthstarV10RequestGuardTest'
```

Run tests against a **separate** local test database. Some test suites rebuild their database, so pointing them at `docintel_local_eval` can remove saved evaluation documents. PHPUnit's HTTP boundary blocks real provider requests. The opt-in Northstar live test stays skipped unless its separate paid-call gates are explicitly enabled.
