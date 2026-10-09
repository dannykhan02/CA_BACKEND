# Phase 1 extraction experiment flags — offline pre-registration

Date: 2026-10-09. Pushed branch tip verified as `df53b7febd4a068355ce1655bc081a7737135497` before edits. Provider calls: **0**. Production extraction is unchanged unless an explicit `ExtractionExperiment` object is passed to `AnthropicClient::extractChunk()`; ordinary callers pass three arguments. Production prompt version remains `2`.

## Collector hypothesis

Classification: **A — PROMPT_SELECTION_LANGUAGE_PRESENT**. `EvidenceSchema::instructions()` (legacy) and `EvidenceSchema::spanInstructions()` (span grounding) both say “Prefer material evidence to repetitive boilerplate” and, under `max_records` pressure, “keep the most material ones ... ahead of row-level table detail.” These are direct instructions to rank and omit lower-priority qualifying observations. The existing omission audit establishes omission, but does not establish that this wording caused it.

The diagnostic collector option removes the material-facts qualifier and replaces those two selection sentences. It asks for independently supported observations without importance ranking, distinct values and claims, and valid JSON within `max_records` and practical response capacity. Grounding, validation, model, sampling, chunking, and Stage A/B1 logic are unchanged. **Limitation:** the current architecture has a record cap and max-token salvage/continuation, but no deterministic unbiased ordering or capacity allocation among more qualifying observations than fit. The collector prompt discloses incomplete coverage and does not claim exhaustiveness. It cannot guarantee freedom from positional bias.

## Modes and version identifiers

| Mode | Collector | Metadata | Prompt version |
|---|---:|---:|---|
| CONTROL | off | off | `2` |
| COLLECTOR_ONLY | on | off | `diagnostic-collector-v1` |
| METADATA_ONLY | off | on | `diagnostic-metadata-v1` |
| COLLECTOR_PLUS_METADATA | on | on | `diagnostic-collector-metadata-v1` |

`ExtractionExperiment` is request scoped and has no environment flag. Metadata Discipline adds a span-grounded instruction to use null for unsupported optional metadata, without relaxing downstream provenance or grounding. The diagnostic prompt version is recorded through the existing `DocumentAiRun.prompt_version` path. The old chunk planning version remains `2`; no production version is overwritten. Each request artifact also carries its version in `request-hashes.json` or the probe result.

**Experiment readiness:** Collector is implemented for offline request testing. Metadata Discipline and the combined mode are **not experiment-ready** because of the period collision below. No paid design should start until that risk is resolved or bounded by an approved study design. No identity rules were changed here.

## Identity collision audit

The earlier audit's *primary* quantitative identity uses cited span IDs plus canonical number, scale, currency and percent notation; period only disambiguates a collision within that base. Its *strict* sensitivity identity includes period. Thus same-value 2023/2024 claims in separate spans remain distinct in the primary view, while claims in the same span may collide when period is null. Strict identity definitely collides when both periods are nulled and other fields match. These are diagnostic comparison identities, not database keys (`unicef-4call-analyze.py`).

`EvidenceMerger::identity()` includes period for metrics, but excludes the source quote. The merger groups records by that identity in `$state` and combines their sources. The offline fixture `2023 revenue = $50m` / `2024 revenue = $50m`, with equal label, subject, unit and value, yields separate identities with periods and the **same identity when both periods are null**. Its projected provenance remains `origin=document` for both cited quotes, while `TypedEvidenceProjector` loses `period_covered` on null. This is a **COLLISION**, and normal merge logic implies **MERGE and LOSS OF DISTINCTNESS** if both accepted records reach it in one pipeline. Full database merge was not run because the local PHP lacks PDO SQLite; that last outcome is a code-path inference, not a measured database outcome. `KpiIdentityProfile` also accepts period metadata, though its definition key does not include period. Nulling a genuinely unsupported period is correct for grounding but can erase a distinction when the year is present elsewhere in the cited quote yet omitted from the field. No identity rule was weakened.

## Request isolation and hashes

The checked-in `experiment-flags/build-offline-requests.php` starts from the **previously captured real-path** UNICEF E033–E186 control request, verifies its SHA, changes only the system instruction text using the new option class, and asserts every other JSON field is identical. The control body contains model `claude-haiku-4-5-20251001`, `max_tokens=16000`, `max_records=79`, no explicit sampling keys, the same source spans, schema and document metadata. Prompt version is a separate diagnostic identifier, not serialized into this provider request. These are **offline projected hashes**, not fresh real-path captures:

| Mode | SHA-256 of request body |
|---|---|
| CONTROL | `143b3385379edaa0955625350eb69da3a59814ad23dffb0676bbf073ccfccd91` |
| COLLECTOR_ONLY | `ed3a27dce2ee70cfe14f9c6aa9a969c5cbcd0db013ad5e4a8ac309750049663c` |
| METADATA_ONLY | `2c42488418ce6a8e0f87021b157cb6e7752b81956e3813276eb62eb01c3be31f` |
| COLLECTOR_PLUS_METADATA | `5e5602f7c68bb19eb44f868ff945f0c70860ea042ef5e3e0ed62ff3d6fbf8fe4` |

`extraction-experiment-probe.php` is the real `AnthropicClient::extractChunk()` fake-HTTP probe for both frozen chunks and all four variants. It prevents stray HTTP and rolls back database writes. **Gate not passed:** this machine's PHP has PDO and `pdo_pgsql` but no `pdo_sqlite`; the probe stops at in-memory table creation. The exact real-path experimental request hashes and cross-process reproducibility remain unverified. The projected hashes must not be treated as a substitute. The frozen control request itself was captured through the real path in the earlier diagnostic audit.

## Capacity and truncation

The E033–E186 fixture has an estimated 60-record source pressure versus `max_records=79`. Earlier calls returned **80–108** records despite that instruction, so `max_records` is soft in practice. Output reached about **13.6k of 16k** tokens, with no prior truncation. Collector wording may admit more table rows and ordinary facts, pushing returned records and output tokens upward. The gap to 16k is only about 2.4k tokens at the observed high-water mark. Risk of `max_tokens` truncation is material. Existing salvage and up to two continuations activate only after truncation and include `already_extracted`; they may preserve earlier-position records preferentially because the model reaches them before the cap. The request cap and output cap are unchanged. Future calls must record `stop_reason`, output tokens and continuation count as primary outcomes; no silent retry.

## Two frozen source-side study chunks

The files under `experiment-flags/` were selected from source spans only, before experimental output. `study-chunks.json` locks offsets, span range and source SHA; each `.source.txt` contains labeled exact text. The two chunks are independent of prior model output.

| Chunk | Spans | Profile | Estimated pressure | Gold / negative |
|---|---|---|---:|---:|
| `unicef_quantitative` | E033–E186, offsets 4218–29519 | Dense partner table plus quantitative operations and narrative; previously audited, pressure estimate from frozen scan | 60 | 14 / 5 |
| `india_wash` | E200–E238, offsets 32299–40228 | India sanitation case study, temporal changes, goals versus achievements, money and ordinary operational facts | about 25–40; source-side estimate, not provider result | 12 / 5 |

The second slice is materially different in evidence profile, though both are from the same UNICEF source document because no second fully spanned frozen document was available. It is not dominated by boilerplate. Its shorter source is expected to fit safely under the existing record cap, but the fake-HTTP probe must verify the exact Control request across fresh processes before any paid calls.

The two `.gold.json` files contain hand-checked span IDs, normalized claims, canonical values where applicable, why they qualify, priority membership and explicit-period support. They include currency, percentages, time bounds, operational quantities and ordinary facts. The negative lists cover headings/axes, context, target-versus-actual confusion, unsupported periods and false deadlines. **There are no clear legal or contractual deadlines in these slices; none were fabricated.** The gold and negative lists are frozen before any variant outputs and must not be edited after observing them.

## Future comparison, contingent on gates

Four variants × **3 independent calls** per variant × **2 frozen chunks** = **24 maximum provider calls**. All use the current production sampling behavior; temperature is not set. No silent retries. For each call store exact request and response, request SHA, prompt version, returned and accepted counts, validator and grounding failures, stop reason, input/output/cache tokens, cost, and continuation count.

Pre-registered metrics per variant: (A) gold recall; (B) priority/material gold recall; (C) sampled precision against source; (D) negative-set false-positive rate; (E) accepted-record count; (F) validator rejection rate; (G) grounding failure rate; (H) share `origin=document`; (I) share `origin=unknown`; (J) key-figure eligibility rate; (K) truncation rate and stop reason; (L) output tokens; (M) provider cost; (N) cross-run stability/Jaccard. Compare each variant within each chunk and across both chunks. More records alone does not establish Collector success; more `origin=document` alone does not establish Metadata success.

Collector may be recommended only if gold recall improves or holds, sampled precision and grounding hold, negative findings do not materially rise, truncation/continuation stays acceptable, and cost stays under an agreed threshold. Metadata also requires reduced unsupported metadata, more consistent provenance, preserved distinct-period identity, no legitimate record collapse, and preserved or better precision. Combined mode needs improvement beyond either single flag. **No paid experiment is authorized or run by this phase.**

## Verification

`php artisan test --filter=ExtractionExperimentTest`: 2 passed, 29 assertions. Relevant broader suite (`ExtractionExperimentTest`, `EvidenceSchemaDiagnosticsTest`, `KpiIdentityProfileTest`, `IntelligenceProvenanceTest`, `IntelligenceKeyFigureSelectorTest`): 30 passed, 129 assertions. Pure offline request projection passed field-isolation assertions and produced the hashes above. Fresh real-path fake-HTTP probe failed before request construction because the SQLite driver is missing. Provider calls: **0**.
