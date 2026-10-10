# DocIntel Intelligence V2 — B2 paid evaluation: results, blinded claim review, FinOps

Date: 2026-10-10
Worktree: `.claude/worktrees/b2-b1-integration`
Branch: `worktree-b2-b1-integration`
HEAD at run time: `e31555d` (working tree clean, `git diff HEAD` empty before and after)
Protocol: `docs/tasks/b2-b1-integration-readiness-report.md`, sections 12 and 13
Authorization: explicit, for the 6-call run under a hard cap of **$0.25**

**Spend: $0.049018 settled, 19.6% of the cap.** A further $0.022685 is committed to one document's
budget for a call whose real cost is unknown, so **$0.071703** is the amount charged against
document budgets and $0.071703 is the upper bound on what was actually billed.

**Headline result: 0 of 5 returned narratives were accepted. The verifier rejected all five.**
The pre-registered blinded review then found **19 apparent false-positive rejections and zero
unsupported claims that passed**, so under the definition registered in section 12 before any
money was spent, the finding is **LOOKS TOO STRICT**.

The cause is not in B2. It is three concrete defects in the shared B1 grounding layer
(`BriefVerifier`), and one of them is **already degrading B1 in production**: B1 silently drops its
own monetary blocks, so on both evaluation documents a reader gets a Brief with a headline and a
coverage note and no figures at all. Section 6. Nothing was changed to fix this; it is reported.

No production code, configuration, verifier setting or pricing was changed. Nothing was pushed,
merged or deployed.

---

## 1. What was run, and the one guard that fired

Protocol as written in section 13: evidence sets `india_wash` and `unicef_reduced`, 3 fresh
`Document` rows each, 6 synthesis attempts, each document's `ai_pipeline.budget_usd` set to $0.05 so
the shipped `NarrativeCheckpoint` enforces the per-call cap itself.

Pre-flight, all at zero cost and all matching the readiness report's predictions exactly:

| Check | Result |
|---|---|
| Dry run, 6 documents | 30 / 41 Stage A records, 15 / 18 supplied, 1 / 3 key figures, 1 782–2 090 context tokens, 0 omitted, coverage `bounded` on all six |
| Input hashes | 6 distinct (`c7d4aac9`, `d8e765cd`, `7c4e521e`, `22880bb1`, `fdf13d34`, `ce160f9c`) — the first three match the hashes recorded in the readiness report |
| Reservation per attempt | $0.022680–$0.023450, all `canReserve` true, all under the $0.05 production guard and the $0.034 harness guard |
| Response tee | verified free against a non-provider request: 577 / 577 bytes, body identical after teeing |
| Model | `claude-sonnet-5-5`, in `structured_models` and `effort_models` |
| `max_rejected_claims` | **0**, unchanged, as pre-registered |

**The run stopped itself after 2 calls.** Call 2 (`india_wash-2`) failed in transport at 15.4s with
no response: `provider_response_received = false`, `timeout_source = transport_unknown`, no provider
request id, no usage returned. The runner's `unknown_cost_for_a_sent_call` guard fired and the run
halted, as specified.

I stopped there rather than resuming, reported the bounded exposure ($0.011130 known plus at most
$0.022685 unknown, against a $0.25 cap), and resumed only on explicit instruction. A zero-cost
reachability probe to the provider was denied by this session's permission layer, so the transport
failure is **not diagnosed beyond its own telemetry**; its signature is consistent with a local
network fault on this machine (WSL1) and no second failure occurred in the remaining four calls.

The resumed pass re-ran the same harness file, byte-identical (`sha256 e62802d5…`), with no change
to the guards. On that pass the two already-attempted documents made **no provider call at all**,
which is the idempotency contract working on real paid results (section 4).

## 2. Per-call record

Every field section 16 of the spec asks for. `india_wash-2` is the transport failure.

| | `india_wash-1` | `india_wash-2` | `india_wash-3` | `unicef_reduced-1` | `unicef_reduced-2` | `unicef_reduced-3` |
|---|---|---|---|---|---|---|
| Provider response | yes | **no** | yes | yes | yes | yes |
| Input tokens | 2 141 | – | 2 125 | 2 601 | 2 604 | 2 601 |
| Output tokens | 448 | – | 444 | 382 | 415 | 486 |
| Cache-write tokens | 947 | 0 | 0 | 0 | 0 | 0 |
| Cache-read tokens | 0 | 0 | 947 | 947 | 947 | 947 |
| Stop reason | `end_turn` | – | `end_turn` | `end_turn` | `end_turn` | `end_turn` |
| Reserved USD | 0.022680 | 0.022685 | 0.022685 | 0.023435 | 0.023438 | 0.023450 |
| **Settled USD** | **0.011130** | **unknown** | **0.008879** | **0.009211** | **0.009547** | **0.010251** |
| Quote error (settled − reserved) | −0.011550 | – | −0.013806 | −0.014224 | −0.013891 | −0.013199 |
| Latency ms | 12 147 | 15 426 | 15 262 | 3 907 | 3 617 | 9 180 |
| Verifier | **rejected** | – | **rejected** | **rejected** | **rejected** | **rejected** |
| Claims returned | 6 | – | 6 | 5 | 6 | 6 |
| Claims rejected | 3 | – | 3 | 5 | 6 | 5 |
| Rejection reasons | entities, numbers | – | entities, numbers | key figure, numbers, entities | key figure, numbers, entities | numbers, entities, key figure |
| Unit status | `completed` / `verifier_rejected` | `failed` / `timeout` | `completed` / `verifier_rejected` | `completed` / `verifier_rejected` | `completed` / `verifier_rejected` | `completed` / `verifier_rejected` |
| B1 fallback served | yes | yes | yes | yes | yes | yes |
| Narrative persisted | no (`claims: []`) | no | no | no | no | no |
| Evidence input hash | `c7d4aac9` | `d8e765cd` | `7c4e521e` | `22880bb1` | `fdf13d34` | `ce160f9c` |
| Contract / prompt version | 1 / 1 | 1 / 1 | 1 / 1 | 1 / 1 | 1 / 1 | 1 / 1 |
| Reuse on second attempt | reused, **0 calls** | refused terminal, **0 calls** | n/a | n/a | n/a | n/a |

Prompt hash `b970235062f3f7de…`, verifier version 1, extraction version `8acd7d011fac21b3…` on all six.

## 3. Verifier outcome

| | |
|---|---|
| Narratives accepted | **0 of 5** |
| Claims returned | 29 |
| Claims rejected | **22 (75.9%)** |
| Reason frequency | `brief_entities_grounded` 17, `brief_numbers_grounded` 13, `unsupported_key_figure` 6 |
| Fabricated citations | **0** — every citation resolved to a record that was actually supplied |
| Citations not supplied | 0 |
| Malformed or truncated output | 0 |
| Unsafe text / length / duplicate rejections | 0 |

With `max_rejected_claims` = 0, one rejected claim rejects the whole narrative, so five calls with
3, 3, 5, 6 and 5 rejections produced five empty results.

Re-verifying each teed raw response through the shipped `NarrativeVerifier` reproduced the stored
verdict exactly for all five calls, which is what makes the rejected claims' own text reportable:
the stored unit deliberately keeps only accepted claims.

## 4. Idempotency and the read path, on real paid results

Both checked after the money was spent, with `Http::fake()` set to throw on any request.

| Case | Result |
|---|---|
| Re-attempt a `completed` unit (`india_wash-1`) | reused: `rejected` / `verifier_rejected`, `provider_called: false` |
| Re-attempt a terminal `failed` unit (`india_wash-2`) | refused, `provider_called: false` — never re-claimable for that identity |
| HTTP requests during both | **0** (`Http::recorded()` empty) |
| 12 reads of the Brief (6 documents × B2 on and off) | **0** HTTP requests |
| B1 blocks byte-identical to `BriefAssembler`'s own output | **12 of 12** |
| Status served with B2 on | `rejected` / `verifier_rejected` ×5, `failed` / `timeout` ×1 |
| Status served with B2 off | `disabled` / `disabled` ×6 |

So on real data the contract holds: a read never pays, a rejection never becomes a retry, and B1's
bytes are untouched by anything B2 did.

One correction to the readiness report's section 5 case E: a terminal `failed` unit is refused with
reason **`in_flight`**, not a distinct terminal reason. The effect is right (no call, never retried)
but the reason string is misleading to an operator reading logs.

Also worth recording: `intelligence_v2.b2.attempts = 2` did **not** apply to this failure. The
synthesizer marks a unit `pending` (retryable) only for `provider_busy` and `transient`; a cURL
timeout classifies as `timeout`, which is terminal. A transport timeout therefore permanently
disables B2 for that evidence set on the first try, and the configured second attempt is
unreachable for the most likely transient fault.

## 5. Blinded claim review (section 12)

Conducted exactly as pre-registered, before any result was known to the reviewer.

| | |
|---|---|
| Items | **29** — every claim returned across all calls, accepted and rejected, not sampled (within the pre-registered 12–48 range) |
| Each item carried | the claim text and the full serialized content of the records it cites and that were supplied |
| Each item did **not** carry | the verifier verdict, the reason codes, the pass/fail label, the document or run identity, the record ids, the record identity hashes, the extraction chunk ids, or the per-document citation handles (relabelled `E1…En` per item) |
| Order | deterministically shuffled under a fixed seed, so position carries no information |
| Reviewer | an AI reviewer with no part in generation and no part in running the verifier, instructed to read the packet only and no repository file |
| Packet `sha256` | `e5d6aa61573712131e77b0a29a2131191035b4d8d30c12b2a5c80f7108ec577d` |
| Sealed key `sha256` | `c8e5e5431a753018b68753bb34b84fb0e2211d90916580739471b98b30249ea7` |
| Judgements `sha256` | `51ecff871d546921f7aa89debd97e15b366cfe173d4bdd7386d7ced9f7f81869` |
| Hashes recorded at | **2026-10-10T11:18:47Z, before unblinding** |

### Cross-tab: verifier verdict × independent review

| | supported | partially supported | unsupported | ambiguous | total |
|---|---|---|---|---|---|
| Verifier **accepted** | 7 | 0 | 0 | 0 | **7** |
| Verifier **rejected** | **19** | 3 | 0 | 0 | **22** |
| total | 26 | 3 | 0 | 0 | 29 |

**Apparent false-positive rejections: 19.** The pre-registered threshold for describing the verifier
as "looks too strict" was 2.

**Unsupported claims that passed the verifier: 0.** The verifier produced no false negatives on this
sample. Fail-closed behaviour is intact; it is the closing that is mis-calibrated.

**Agreement on the one real defect.** The three `partially_supported` items are three wordings of a
single claim (the Core Resources / ORR / ORE funding ratio, 46% / 49% / 5%). The reviewer found
independently that 46% and 49% appear in the cited chart quote but 5% appears nowhere in it, reading
as a 100−46−49 residual. The verifier rejected all three as well. Reviewer and verifier agree on the
only claim that deserved rejection, which is some evidence the reviewer was not simply lenient.

### Finding, under the pre-registered definition

> **LOOKS TOO STRICT.** 19 rejected claims across the evaluation were independently judged
> adequately supported by their cited and supplied evidence, and those rejections materially reduce
> the accepted-narrative rate: `india_wash-1` and `india_wash-3` had **every** claim judged
> supported and were rejected solely on apparent false positives, so correcting them alone lifts the
> accepted-narrative rate from **0/5 to 2/5**. The three `unicef_reduced` calls would still be
> rejected, each containing the one genuinely partially-supported funding-ratio claim.

`max_rejected_claims` was **not** tuned, and no threshold change is proposed on a sample of five.
The right response is not a looser threshold: it is the four grounding defects in section 6, which
are correctness bugs rather than calibration.

## 6. Why the verifier rejected supported claims — four defects, confirmed in code

Diagnosed by calling `BriefVerifier`'s own extractors on the rejected claims and their records.
Read-only; nothing was changed.

### 6.1 Money records are unusable for grounding because `currency` holds a symbol, not an ISO code

`BriefVerifier::validNumericValue()` requires, for a `money` value:

```php
if (! is_string($value['currency'] ?? null) || ! preg_match('/^[A-Z]{3}$/D', $value['currency'])) {
    return false;
}
```

The values parser stores the **symbol** in `currency` and the **ISO code** in `unit`:

```
"type":"money", "raw":"over $120 billion", "number":120000000000, "scale":1000000000,
"currency":"$", "unit":"USD", "unit_kind":"currency", "precision":"exact"
```

`currency` is `"$"`, so `validNumericValue()` returns **false** and the record cannot ground any
number. The ISO code the check wants is sitting in the adjacent field.

**This is not a B2 problem.** `BriefAssembler` drops any block whose `numbers_grounded` check fails,
so B1 rejects its own generated text for its own records:

| B1 block text (generated by B1, from the record) | Outcome |
|---|---|
| `Government of India investment in water and sanitation: over $120 billion` | **dropped** |
| `Core Resources income from private sector: $724.9 million (2024)` | **dropped** |
| `Core Resources income from public sector: $512.6 million (2024)` | **dropped** |
| `Core Resources income from other partners: $346.1 million (2024)` | **dropped** |

Measured on the evaluation documents:

| Document | Candidate blocks | Kept | Dropped | What a reader gets |
|---|---|---|---|---|
| `india_wash-1` | 3 | 2 | 1 (all substantive) | headline + coverage note only |
| `unicef_reduced-1` | 5 | 2 | 3 (all substantive) | headline + coverage note only |

`headline` and `coverage_note` are exempt from the filter, so they survive. Every block carrying an
actual figure was removed. **This is live B1 behaviour in production, independent of B2**, and the
paid B2 evaluation is what surfaced it.

Accounts for 5 of the 19 apparent false positives directly, and for the B1 degradation.

### 6.2 The claim-entity extractor returns sentence fragments, compared literally

`BriefVerifier::entities()` yields capitalised token runs, and `entitiesGrounded()` compares them by
exact normalized equality against each record's `subject`, label, value and aliases:

| Claim opening | Entity extracted | Record subject | Grounded? |
|---|---|---|---|
| "In India, 580 million people were reached…" | `In India` | `India` | **no** |
| "The Government of India invested…" | `The Government` | `Government of India` | **no** |
| "In Sierra Leone, more than 675,000 children…" | `In Sierra Leone` | `Sierra Leone` | **no** |
| "…Core Resources income in 2024…" | `Core Resources` | `UNICEF` | **no** |
| "…the Swachh Bharat (Clean India) Mission…" | `Swachh Bharat`, `Clean India` | `Government of India` | **no** |

The leading `In` and `The` are swallowed into the entity, and `of` splits the run. The decisive
demonstration is within this evaluation: **the same fact passed or failed on word order alone.**

- `"In 2024, 59.3 million people in India accessed safely managed water…"` → **accepted**
  (mid-sentence lowercase "in", so the entity extracted is `India`, which matches)
- `"In India in 2024, safely managed water supplies were provided to 59.3 million people…"` →
  **rejected** (sentence-initial, so the entity extracted is `In India`, which does not)

B1's own blocks hit this too: `Core Resources income from private sector: …` yields the entity
`Core Resources` against subject `UNICEF`, so those blocks fail `entities_grounded` as well as
`numbers_grounded`.

Largest single contributor: `brief_entities_grounded` appears in 17 of 22 rejections.

### 6.3 Some monetary records have no typed value at all

| Record label | `data.value` | `typed.value` |
|---|---|---|
| `Core Resources income` | `$1.584 billion` | **null** |
| `Total income from voluntary contributions` (×5 years) | `$8.263 billion` etc. | **null** |

`numbersGrounded()` reads only `typed.value.number`, so a claim stating these figures has nothing to
ground against, however correct it is. These are chart-derived figures; the typing layer produced
nothing for them. This is an extraction/typing gap, upstream of both B1 and B2.

Accounts for 6 of the 19 apparent false positives (the `$1.584 billion` and
`$8.263bn / $8.920bn / $9.326bn / $8.122bn / $7.219bn` claims).

### 6.4 A duration in prose becomes a number that must be grounded

`"…over the last 10 years."` → `periods()` does not strip it, so `numbers()` returns `10`, which
must then match a typed value. The duration fallback in `numbersGrounded()` requires a typed
`duration` date with `anchor_resolved === false` whose `raw` equals its own text; these records carry
"last 10 years" only inside their **label**, so the fallback does not apply and the claim is
rejected on a number that is not a quantity at all.

Confirmed directly: for
`"In India, 580 million people were reached with improved drinking water and 550 million with basic sanitation over the last 10 years."`
the ungrounded number is exactly `{"n": 10}` — 580 000 000 and 550 000 000 both grounded fine.

### 6.5 Separately: the key-figure gate makes most monetary claims impossible

`unsupported_key_figure` fired 6 times. A claim that states money must cite a record Stage A marked
headline-eligible; `india_wash` had **1** key figure among 15 supplied records and `unicef_reduced`
**3** among 18. A narrative that mentions any other monetary figure is rejected by construction.
This is a deliberate contract choice rather than a bug, but combined with 6.1 and 6.3 it means B2
currently cannot state a monetary figure on these documents by any wording. Whether 6.1 also shrinks
the key-figure set (headline eligibility also inspects typed currency) was **not** established and
should be checked before anything here is changed.

## 7. FinOps report (section 17)

### 7.1 Spend against the cap

| | USD |
|---|---|
| Settled, known | **0.049018** |
| Committed for the unknown-cost transport failure | 0.022685 |
| **Total charged to document budgets** | **0.071703** |
| Hard cap | 0.250000 |
| Used | **28.7%** of cap (19.6% on known settled spend) |
| Reserved across 6 admitted attempts | 0.138373 |
| Upper bound on what was actually billed by the provider | 0.071703 |

Protocol expectation was ≈$0.08. Actual known settled spend came in at $0.049018, below it, and the
total charged including the conservative bound landed at $0.0717 — just under the estimate.

### 7.2 Per-call cost distribution

Over the 5 calls that returned a response:

| | USD |
|---|---|
| min | 0.008879 |
| max | 0.011130 |
| mean | 0.009804 |
| median | 0.009547 |
| stdev | 0.000899 |
| total | 0.049018 |

Tight: a 9.2% relative standard deviation, and the max is the cold-cache call. Cost is predictable
per document at this context size.

### 7.3 The quote is conservative by about 2.4× — always in the safe direction

| | |
|---|---|
| Settled as a share of reserved | **42.4%** mean |
| Quote error (settled − reserved) | mean **−0.013334**, range −0.014224 to −0.011550 |
| Calls where settled exceeded reserved | **0 of 5** |

`AiPricing::reserve(..., cacheWrite: true)` prices the full input bound as a cache write and assumes
`max_output_tokens` = 1500; actual output ran 382–486 tokens, a quarter of the cap. The reserve never
under-quoted, which is the property that matters for a spend ceiling.

The cost of that conservatism is **availability, not money**: B2 is admitted or denied on the
reserve, not the actual. At $0.0234 reserved against a real $0.0098, B2 needs ~2.4× the headroom it
consumes, so it will be denied on documents where it would comfortably have fitted. Against the
shipped per-document budget formula this is immaterial (section 11 of the readiness report measured
B2 at 1.9–3.7% of budget at every document size). It becomes material only under an AI-credits quote,
where `provider_cost_cap_usd` lowers `budget_usd` directly — credits mode is off in this environment
(`ai_credits.enabled` false), so this remains identified and unmeasured, as before.

### 7.4 Prompt caching worked, and the measurement is optimistic

The 947-token system prompt was written to cache once and read on all four later calls.

| | tokens | USD |
|---|---|---|
| Cache write (call 1) | 947 | 0.00236750 |
| Cache read (calls 3–6) | 947 each | 0.00018940 each |
| Same tokens as plain input | 947 | 0.00189400 |
| **Saving, warm vs cold, per call** | | **0.00217810** |

That is **22.2% of the mean call cost**. But the window was artificial: the write landed at 11:06:25
and the first read at 11:10:58 — **4m33s later, inside the 5-minute ephemeral TTL by 27 seconds**,
only because the run was resumed promptly after the guard trip.

In production, documents reach B2 after extraction and Stage A, at intervals that will usually exceed
5 minutes. **Plan on a cold cache:** mean per-call cost ≈ **$0.0120**, not $0.0098. Call 1, the only
genuinely cold call in this evaluation, settled at $0.011130 and is the better production estimate.

The system prompt is the only cached segment. At 947 tokens it is worth caching only under sustained
throughput; a batch of documents processed back to back would benefit, a trickle would not.

### 7.5 A transport failure is the most expensive possible outcome

| Outcome | Settled | Committed to budget | Narrative | Identity reusable |
|---|---|---|---|---|
| Verified | ~$0.0098 | actual | yes | n/a |
| Verifier-rejected | ~$0.0098 | actual | no | no (terminal, by design) |
| **Transport failure** | **unknown** | **$0.022685 (full reserve)** | **no** | **no (terminal)** |

`IncrementalPipeline::settleCost()` commits the full reserved bound when usage is unknown, with
`actual_known: false`. That is the right fail-safe — it cannot under-charge a call that may have been
billed — but the consequences are worth stating plainly:

1. A transport failure costs the document's budget **2.3× a successful call** while producing nothing.
2. Because `timeout` is terminal (section 4), the document can **never** get a B2 narrative for that
   evidence set, and has permanently spent 45% of a $0.05 budget for it.
3. On this run the transport-failure rate was **1 in 6**. On a local WSL1 machine that is not a
   production figure, but the cost asymmetry is structural and does not depend on the rate.

**Recommendation, not implemented:** classify a transport timeout as retryable (`pending`) so the
configured `attempts = 2` can be reached, and release rather than commit the reservation when no
provider request id and no usage were returned. Both are production-code changes and were explicitly
out of scope here.

### 7.6 Latency

| | ms |
|---|---|
| min | 3 617 |
| max | 15 262 |
| mean | 8 822 |
| median | 9 180 |

Comfortably inside the 60s HTTP timeout and the 120s job timeout. The two slowest successful calls
(12.1s, 15.3s) were the cold-cache call and the first call after it; the three fast calls (3.6–9.2s)
all read from cache.

### 7.7 Unit economics, if the grounding defects are fixed

On a cold cache, B2 adds **≈$0.012 per document** at this context size (~2 000 context tokens,
15–18 supplied records). At the shipped per-document budget that is ~2% of the ceiling.

**At the current accepted-narrative rate of 0%, the cost per accepted narrative is undefined and the
spend is pure loss.** Nothing about the cost structure is the problem; the grounding defects in
section 6 are. Until they are addressed, enabling `DOCINTEL_V2_BRIEF_NARRATIVE` in production would
spend ~$0.012 per document to produce nothing a reader ever sees.

## 8. Limitations

Stated so the numbers are not read as stronger than they are.

- **n = 5 returned calls, 2 evidence sets, one source document.** Enough to establish that the
  defects in section 6 exist and are reproducible; not enough to estimate a rejection rate across a
  document corpus, and not enough to tune any threshold. No threshold was tuned.
- **The reviewer is an AI of the same family as the generator**, so correlated leniency is a real
  risk. Three things bound it: the reviewer independently found the one genuine defect the verifier
  also caught (section 5); it judged 3 items short of fully supported rather than waving everything
  through; and every mechanism it implicitly disputed was then **confirmed in the verifier's own
  code** (section 6), which does not depend on the reviewer's judgement at all. A human review of
  the 29 items would still be worth having before anything is changed.
- The reviewer treated a **dropped period** as support ("over $120 billion" without the record's
  "over 10 years"; "675,000 children" without "in five years") on the grounds that omission is not
  contradiction. That is a defensible reading but it is a judgement call, and a brief that drops the
  period from a cumulative figure is arguably misleading. **3** of the 19 apparent false positives
  involve such an omission (the two "$120 billion" wordings and one "675,000 children"). Even
  excluding all three, 16 apparent false positives remain and the "looks too strict" threshold of 2
  is still met by a wide margin.
- The packet is **heavily duplicated**: 29 items carry only **14 distinct claims** (grouped by the
  set of figures asserted; 23 distinct exact wordings), because the three runs per evidence set
  produced near-identical narratives. Identical claims received identical judgements, which is a
  consistency check but means the 29 items are not 29 independent observations. Counted as distinct
  claims: **12 of 14** were rejected and **10 of those 12 rejections were apparent false positives**
  — the same conclusion, at a defensible denominator.
- The **transport failure is undiagnosed** beyond its telemetry, and its real provider cost is
  unknown, only bounded.
- The three runs per evidence set differ in opaque per-document citation handles, as the readiness
  report's section 13 already corrected; they are not literally identical requests.

## 9. Environment

No repository file, configuration or `.env*` was changed, read or printed. `git diff HEAD` was empty
before and after the evaluation; HEAD was `e31555d` throughout.

Local, throwaway, outside the repository:

- the PostgreSQL 16 instance on port **54329** from the previous task, databases `b2eval` (6 seeded
  documents, already present and verified clean: 0 `document_ai_runs`, 0 `brief_synthesis` units)
  and `ca_document_intelligence_test`;
- a throwaway **Redis on port 63799** (`--save '' --appendonly no`), started because
  `document_intelligence.provider_gate.driver` is `redis` and this machine has none running. The
  shipped `ProviderGate` therefore ran exactly as in production rather than being bypassed; its
  snapshot showed `max_inflight: 2`, 0 active, and no admission denials during the run;
- database and Redis coordinates were supplied as process environment variables for the harness
  only, so the repository `.env` (a symlink to the main checkout) was neither read nor edited by
  hand and no credential was printed.

The harness is scratchpad-only: `run-eval.php` (unchanged from the readiness report,
`sha256 e62802d5…`), the packet builder, the diagnostic probes, `eval-results.json`,
`review-packet.json`, `review-key-sealed.json`, `review-judgements.json`, `unblinded.json` and
`diagnosis.json`. Nothing in the harness lives in the repository.

## 10. Recommendation

**Do not enable `DOCINTEL_V2_BRIEF_NARRATIVE` in production.** At the measured accepted-narrative
rate of 0% it would spend about $0.012 per document and show a reader nothing. The flag is off and
should stay off.

**The B1 defect is the urgent one and is not about B2 at all.** Section 6.1 and 6.2 are already
removing every figure-bearing block from B1 Briefs on both evaluation documents, in production,
today. That deserves verification against real production documents before anything else here is
acted on: the question to answer first is how many live documents currently serve a Brief with no
substantive blocks.

In priority order, none of it implemented here:

1. Check how widely 6.1 affects live B1 Briefs — one query over documents whose Brief yields only
   `headline` and `coverage_note` would size it.
2. Reconcile `currency` and `unit` between the values parser and `validNumericValue()` (6.1).
3. Fix the claim-entity extractor's leading-article and `of` handling (6.2).
4. Investigate why chart-derived monetary records type to null (6.3).
5. Strip durations before number extraction, or ground them against the record label (6.4).
6. Re-run this evaluation afterwards. Expect it to cost about the same, ~$0.07.
7. Separately, reconsider terminal classification and reservation release for transport timeouts
   (7.5), and the `in_flight` reason string for terminal units (section 4).

`max_rejected_claims` stays 0. Nothing on this sample justifies loosening it, and the evidence is
that the rejections are caused by grounding bugs rather than by a threshold.
