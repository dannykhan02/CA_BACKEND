# UNICEF leaf 2 small root-cause audit — stopped before provider calls

## Verified source

- File: `/home/collins/boys/CA_BACKEND/unicef-leaf2.txt`
- Byte count: **37,584** (required: 37,584)
- SHA-256: **`5e9e9609210a288163288a1d64a9ffd5a153116d58f2ec201f6f76a0944ee023`** (matches required hash)
- Branch: `feat/intelligence-v2-stage-a`

## Verified production request construction

`AnthropicClient::extractChunk()` uses `claude-haiku-4-5-20251001`, `EvidenceSchema::extraction()`, `EvidenceSchema::instructions()`, a user JSON envelope containing document name, page bounds and `max_records`, and a source payload from `EvidenceGrounding`. For a span-reference pipeline, that payload is **labeled evidence spans** rather than the raw slice. The historical UNICEF pipeline used span references. The request's output limit is **16,000** tokens and `ExtractionCapacity::recordLimit()` computes **79** (`floor((16000 × 0.75 − 64) / 150)`). `callWithRetry()` constructs the HTTP body with model, max_tokens, messages, system and output_config. It sends **no** `temperature`, `top_p`, `top_k`, or `stop_sequences` fields for Control; those parameters are therefore omitted from the actual request body, not explicitly set to values. Extraction sets `max_attempts=1`.

Configured model pricing is USD **$1 per million input tokens** and **$5 per million output tokens** in `config/document_intelligence.php`. The four-call maximum output charge alone is **$0.320** (4 × 16,000 × $5 / 1,000,000). A compliant conservative total and expected total were **not finalized or used as a call gate**, because the exact historical labeled-span request payload and prior per-call input-token usage were not available. No provider call was made.

## Blocking condition

The exact raw leaf bytes are present, but the local file does not include the historical document-level source-span IDs, offsets, page mapping, extraction version, document name, or the complete `EvidenceSpanSet::render()` payload. The prior `/tmp/unicef-ab-diagnostic` response bundle is absent. Railway read-only SSH attempts timed out before running a command. Re-segmenting the isolated leaf or sending raw text would change the request and grounding mode, so it would not be the specified production-request comparison. The requested two-pass replay would use the first new response; because the request gate did not pass, there is no new response to replay.

**Calls made: 0 of 4.** No response, replay, raw union, attribution bucket, or C-versus-T0 result exists for this study. The pre-registered A/B/C/D root-cause decision is **D — inconclusive**, solely because the exact request and response data needed for the experiment are unavailable. This is not a finding about Anthropic sampling or DocIntel downstream behavior.

To resume, recover the historical labeled-span payload and document request metadata from the stored pipeline, then complete both cost estimates before any provider call. Keep the raw responses in persistent diagnostic files so the deterministic replay can be run without production persistence.
