# FinOps memo: why the 4- and 10-credit bands are unreachable on the incremental route

Inputs: code (`QuoteService`, `AnthropicClient::synthesisReservation`, `config/ai_credits.php`), the documented synthesis reservations (USD 0.33 at 1k tokens, 0.37 at 5k, 0.50 at 20k, 0.85 at 60k, 1.05 at 150k), and one post-fix production data point (n = 1). Nothing here is a measured distribution.

## 1. Mechanism

`QuoteService::classify` picks the band by tokens, then moves it up while `cap(band) < minCompletionCost`, where
`minCompletionCost = max(0.05, synthesisFloor) + tokens/1000 x 0.006` and `cap = credits x 0.027 USD`.
For incremental-route documents (above 14k tokens or 60k characters) `synthesisFloor` is the pipeline's *conservative reservation* (primary synthesis + one fallback + one repair, each at full output budget). Caps are 0.108 (4 credits), 0.27 (10), 0.81 (30), 2.16 (80). The floor alone (0.50 at 20k) already exceeds the 10-credit cap, so **no incremental document can be quoted at 4 or 10 credits**; 20k to 27k tokens lands on 30, 60k+ on 80.

## 2. The Oct 6 data point (27,325 tokens, coarse route)

Real spend USD 0.355 (extraction 0.2132, synthesis 0.1186, visuals 0.0235). Synthesis real 0.1186 against a reservation of roughly 0.5 to 0.6: the gate is about 4x to 5x conservative on synthesis. Quoted today: 30 credits (cap 0.81). That is 0.355 / 30 = USD 0.0118 per credit, **44% of the ceiling**. Customer value of 30 credits is KES 450 on Starter (KES 15 per credit at full use) against a provider cost of about KES 46. Even a 10-credit quote (cap 0.27) would have been below the true cost (0.355), so relaxing the reservation alone cannot make 10 credits reachable for a 27k document: the *real* cost needs about 13 credits at 0.027.

## 3. Options (computed credit prices)

Floors for the sizes below use the documented reservations (5k is the legacy route, floor 0). "Half floor" means the gate reserves primary synthesis only (about 50% of today's floor; the 50% is an assumption to confirm with `--what-if`, not a measurement).

| Option | Change | 5k | 20k | 60k | 150k |
|---|---|---:|---:|---:|---:|
| O0 today | none | 4 | 30 | 80 | 80 |
| O1 | gate on half floor, bands unchanged | 4 | 30 | 30 | 80 |
| O2 | half floor + bands 4 / 15 / 30 / 60 (same tokens limits, same 0.027) | 4 | 15 | 30 | 60 |
| O3 | half floor + ceiling 0.040 per credit, bands unchanged (plan credits cut to 67 / 169 to hold max spend) | 4 | 10 | 30 | 80 |

How each is derived (O2): 20k need = 0.25 + 0.12 = 0.37 <= cap(15) 0.405; 60k need = 0.425 + 0.36 = 0.785 <= cap(30) 0.81; 150k need = 0.525 + 0.90 = 1.425 <= cap(60) 1.62. O3: 20k need 0.37 <= cap(10) 0.40; 150k need 1.425 <= cap(80) 3.2 but the 30-credit cap (1.2) is too low.

## 4. Documents per month

| Option | Starter 100 credits: 5k / 20k / 60k / 150k | Professional 250: same sizes |
|---|---|---|
| O0 | 25 / 3 / 1 / 1 | 62 / 8 / 3 / 3 |
| O1 | 25 / 3 / 3 / 1 | 62 / 8 / 8 / 3 |
| O2 | 25 / 6 / 3 / 1 | 62 / 16 / 8 / 4 |
| O3 (67 / 169 credits) | 16 / 6 / 2 / 0 | 42 / 16 / 5 / 2 |

For comparison, today (flag off) Starter buys 20 documents and Professional 100 regardless of size.

## 5. Margin at full use (after 2.9% fee, FX 129.79)

Ceiling-based (every credit spent at its cap, identical for O0 to O2 because the per-credit ceiling is unchanged): Starter monthly 73.7%, Professional monthly 72.1%, Starter annual 69.1%, Professional annual 67.1%. O3 keeps the same maximum spend only because plan credits are cut; per-credit ceiling and plan sizes change together, so margins match but O3 starves large documents.

Indicative realised margin if the Oct 6 ratio (44% of cap) held: Starter about 86.9%, Professional about 86.1% at 100% use. n = 1, so treat it as an upper bound.

## 6. Recommendation

O2, but only after the soak: it keeps the safety model (hard caps), gives a 20k document 15 credits instead of 30, and leaves margin at the ceiling unchanged. Then decide annual allowances (decision D-8). Do not adopt O3. Whatever is chosen, the confirmation step for large and very large analyses protects customers from the price jump.

## 7. What was added to measure it

`php artisan docintel:credit-economics-report --what-if` prints the table above from the live config and the documented floors, read-only; options `--floor-share=` (default 1.0), `--per-credit=` and `--bands=credits:maxTokens,...`. It changes nothing.

## 8. Caching, Batch, effort and `invalid_evidence` (evaluation only)

Checked against the Anthropic documentation on 2026-10-06.
- **Prompt caching:** minimum cacheable prompt is 4,096 tokens for Haiku 4.5 and 512 for Sonnet 5.5; cache writes cost 1.25x (5 minutes) or 2x (1 hour) base input, reads 0.1x. Zero cache tokens in every production row is expected for extraction: the stable prefix (instructions plus schema) is likely under Haiku's 4,096-token minimum and the document slice differs per call. Synthesis runs once per document, so repeats (fallback, repair) are the only reads. Expected saving is small today; revisit if the extraction prompt prefix is moved ahead of the document and grows past 4,096 tokens, then one write is amortised across the chunks of a document (they run within minutes). Do not implement before measuring cache-eligible prefix size with `count_tokens`.
- **Batch API:** 50% discount, most batches finish within an hour. Suitable for non-interactive re-analysis or backfills, not for a customer waiting on a first result; it stacks with caching. Needs a separate submit/poll pipeline and different failure semantics: not a quick win.
- **Synthesis effort:** `ANTHROPIC_SYNTHESIS_EFFORT=medium` today. With synthesis only 33% of the Oct 6 document cost, dropping to low saves at most about 0.03 USD per document and risks coverage; leave it, compare quality on a sample first.
- **`invalid_evidence` share:** the validator requires each quote to appear in the source. Production PDFs contain line breaks and repeated whitespace, so a verbatim match fails when the model re-flows text. A safe improvement that does not loosen validation is to match on a whitespace-normalised form of both the quote and the source (collapse runs of whitespace, normalise hyphenation at line ends and Unicode quotes) and then map back to original offsets. Needs a corpus replay of historical `invalid_evidence` leaves to measure how many become valid; not implemented.
