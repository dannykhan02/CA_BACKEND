# AI credits pricing analysis and alternative schedules

**Date:** 2026-10-23. **Status:** documentation only. **Nothing here is activated.** No price, grant, band, allowance or flag default was changed by this review. Every schedule below is a proposal for the product owner.

**Method.** Arithmetic from values in the repo only: `config/billing.php` (plan prices), `config/ai_credits.php` (bands, USD ceiling per credit 0.027, FX 129.79 KES/USD, fee 2.9%, target 70%), `config/document_intelligence.php` (model rates, route thresholds), and the five historical cost points in `docs/tasks/ai-credits-implementation.md:14-25`. Arithmetic was done with a throwaway calculator; no application code, test, database or provider was run.

**Data limits, stated plainly.**

1. There is **no production cost distribution**. The only measured fully-known costs are five documents, one Ready (legacy DOCX, 8,000 tokens, USD 0.101); four incident-era Needs Review/Processing PDFs at USD 0.453 (15,385 tokens), 1.411 (62,125), 0.634 (63,393), 1.319 (77,176) (`docs/tasks/ai-credits-implementation.md:16-22`). n = 5. No percentiles can be responsibly computed and none are given.
2. There is **no OCR, comparison, Q&A or reanalysis cost sample** (same file, line 24).
3. The synthesis reservation floors (USD 0.33 at 1k tokens, 0.37 at 5k, 0.50 at 20k, 0.85 at 60k, 1.05 at 150k; `docs/tasks/ai-credits-implementation.md:25`) were derived on 2026-10-06 and may be stale (the default synthesis model has since changed to Sonnet 5.5 at USD 2/10 per million). Band thresholds below that use them interpolate linearly between those five points and are labelled ASSUMPTION.
4. "Utilisation" `u` (realised provider cost as a share of a quote's provider cap) is **not measured**. The only anchors: 0.101 / 0.27 = 0.37 for the one Ready legacy document (it would be the 10-credit band), and 0.29-0.65 for the four incident-era documents against the 30/80 caps. These are the source of the ASSUMPTION values `u = 0.40` (expected) and `u = 0.65` (stress), not measurements.

## 1. Formulas

For a plan with monthly revenue `R` (KES; annual price / 12), `A` credits per month, per-credit provider ceiling `c` (USD), FX, fee `f`:

* Provider ceiling per plan-month (KES) = `A * c * FX`.
* Margin at ceiling = `1 - f - A*c*FX / R`.
* Margin at a scenario `k` = `1 - f - k * A*c*FX / R`, with `k = r * (u + phi/(1-phi) * psi)` where `r` = share of the allowance redeemed, `u` = utilisation of caps, `phi` = share of attempts that fail, `psi` = share of a cap a failed attempt burns before it is released (failed work earns nothing: credits are released, `docs/billing-credits.md`).
* Largest `A` for target margin `m` = `R * (1 - f - m) / (k * c * FX)`.
* Documents per plan for a category priced `q` credits = `floor(A / q)`.
* **Invariant:** documents per plan is `A*c / (provider USD a document needs)`. Capacity depends on the **USD ceiling per plan-month** (Starter USD 2.70, Professional USD 6.75), not on the credit label. Scaling the credit unit changes the headline, not the value.

Reference constants: ceiling = 0.027 USD = KES 3.50433 per credit. Revenue per credit: Starter monthly 15.00, annual 12.50; Professional monthly 14.00, annual 11.67 KES.

## 2. Findings that shape every schedule

1. **Band edges are not aligned with cost.** A document just above the route edge (14k tokens) needs about USD 0.53 to finish (interpolated floor 0.448 + 0.006 x 14), which a 20-credit cap (0.54) would fund, but the schedule jumps from 10 (cap 0.27) straight to 30 (cap 0.81). Again at about 33k tokens: 30 (cap 0.81) to 80 (cap 2.16), a 2.7x step for a one-token difference. (ASSUMPTION: interpolated floors.)
2. **Quote predicts the wrong route for some documents** (ledger N1). Affected documents are priced at 30+ although they run the legacy route whose one measured cost is USD 0.101.
3. **Professional customers are worse off than under the legacy plan for large documents.** Legacy: 100 documents/month for KES 3,500 = KES 35 each. Workload: standard (10 credits) = KES 140; large (30) = KES 420; very large (80) = KES 1,120 (12x and 32x). Starter: legacy KES 75 each; workload 60 / 150 / 450 / 1,200 for 4 / 10 / 30 / 80 credits. This is the main fairness exposure of switching.
4. **The free grant cannot analyse most documents.** 20 credits fund at most five 4-credit documents or two 10-credit ones; zero incremental documents (minimum 30), and no scan (OCR 20 + 4 = 24).
5. **OCR is priced far above its own cost ceiling for short scans.** One page: 20 credits (Starter KES 300) against a provider cap of USD 0.05 (KES 6.5) after this review's cap-floor fix (was 0.02). Long scans approach the cap per credit (60 pages: 60 credits, cap USD 1.20 = 0.02 per credit). The flat 20-credit base is a cliff.
6. **Reanalysis is priced at the full band** (30-80 credits for most documents) although the same evidence may be reusable; `reanalysis.pricing=fixed` (10 credits) exists but is off (`config/ai_credits.php:55`). No cost data exists to say which is right.
7. **Failure amplification is not bounded across attempts.** Cost is capped per quote (`OperationSpend::spentUsd`, `:92-102`). A document that spends part of its cap, fails and releases gets a **new** quote with a **new** cap on retry (`QuoteService::issue`, `:155-162`). I found no cumulative unpaid-spend limit per document across released attempts. Under the `k = 2` row below, one failed attempt at cap per success halves the margin; repeated retries on a deterministic failure are unbounded in this trace. Treat as an open risk (report section 5).

## 3. Per-category cost ceilings under the current schedule (S0)

Provider cap = credits x USD 0.027 for document bands and comparison; OCR uses its own per-page cap (now with the 0.05 floor).

| Category | Credits | Worst-case provider cost (cap) | Expected at u = 0.40 (ASSUMPTION) | Docs/month Starter (100) | Docs/month Professional (250) |
|---|---:|---|---|---:|---:|
| <= 6k tokens (legacy route) | 4 | USD 0.108 = KES 14.0 | KES 5.6 | 25 | 62 |
| 6k-14k (legacy route) | 10 | USD 0.270 = KES 35.0 | KES 14.0 | 10 | 25 |
| 14k-~33k (incremental; ASSUMPTION floors) | 30 | USD 0.810 = KES 105.1 | KES 42.1 | 3 | 8 |
| ~33k-~175k (incremental; ASSUMPTION floors) | 80 | USD 2.160 = KES 280.3 | KES 112.1 | 1 | 3 |
| One-page OCR + simple analysis | 24 | USD 0.158 (0.05 + 0.108) = KES 20.5 | KES 8.2 | 4 | 10 |
| AI comparison | 12 | USD 0.324 = KES 42.1 | KES 16.8 | 8 | 20 |

Contribution margin per category (after 2.9% fee), Starter monthly / Professional monthly:

| Category | Worst-case (ceiling) | Expected (u = 0.40, ASSUMPTION) |
|---|---|---|
| Document bands and comparison | 73.7% / 72.1% (identical for every band: cap per credit is constant) | 87.8% / 87.1% |
| One-page OCR + simple analysis | 91.4% / 91.0% | 94.8% / 94.7% |

Because the cap per credit is constant, every document band has the same ceiling margin; **fairness differences come from realised `u` by category, which is unmeasured**, and from quote accuracy (finding 2).

## 4. Plan economics under scenarios (current allowances 100 / 250)

Scenarios (all inputs ASSUMPTION except the config constants): Expected `r=0.70, u=0.40, phi=0.05, psi=0.50` gives `k=0.2984`. Stress `r=1, u=0.65, phi=0.15, psi=0.75` gives `k=0.7824`. Ceiling `k=1`. Ceiling plus one failed attempt at cap per success `k=2`.

| Plan (per month) | Revenue KES | Credits | Expected | Stress | Ceiling | Ceiling + 1 failed at cap |
|---|---:|---:|---:|---:|---:|---:|
| Starter monthly | 1,500 | 100 | 90.1% | 78.8% | 73.7% | 50.4% |
| Starter annual | 1,250 | 100 | 88.7% | 75.2% | 69.1% | 41.0% |
| Professional monthly | 3,500 | 250 | 89.6% | 77.5% | 72.1% | 47.0% |
| Professional annual | 2,917 | 250 | 88.1% | 73.6% | 67.1% | 37.0% |

The ceiling column reproduces the prior audit's 73.7 / 72.1 / 69.1 / 67.1 (verified independently). Annual plans fall below 70% at the ceiling even today.

## 5. Alternative schedules

Credits, USD ceilings and margins use the formulas in section 1. "Capacity" = documents per plan-month per category.

### Schedule A1: headline scale x10, economics unchanged

| Item | S0 (today) | A1 |
|---|---|---|
| Starter / Professional monthly credits | 100 / 250 | **1,000 / 2,500** |
| Bands | 4 / 10 / 30 / 80 | **40 / 100 / 300 / 800** |
| OCR | 20 + 1 per page after 20 | **200 + 10 per page after 20** |
| Comparison | 12 | **120** |
| Free trial | 20 | **200** |
| Referral reward | 100 | **1,000** |
| Provider ceiling per credit | USD 0.027 | **USD 0.0027** |
| Capacity, margins, fairness, retry/OCR/failure behaviour | | **identical to S0** (same USD ceiling per plan) |

*What it buys.* A bigger number on the page with zero economic change. *Why it is safe.* `credits x c` is invariant, so every cap in USD, every margin and every document count is unchanged (section 1). *Why do it now.* Workload billing is dormant, so no customer holds AI credits yet; after enablement the same change requires converting live balances. *Preconditions.* A new `quote_version` (`config/ai_credits.php:11`, stored per quote so history is untouched), integer rounding checked per band, existing saved units converted at 10 -> 100 credits per legacy document, and a display that never mixes units. **Does not create value; it must not be marketed as more capacity.**

### Schedule A2: cost-faithful fine bands (no price increases)

Same allowances, same ceiling per credit, same OCR and comparison. Replace the four bands by seven so each band is the smallest whose cap funds the documents it covers. Token upper bounds use the interpolated floors (ASSUMPTION) and require the route-prediction fix (ledger N1):

| Band | Credits | Cap USD | Covers (tokens, ASSUMPTION) | Price change vs S0 | Starter / Professional docs |
|---|---:|---:|---|---|---|
| simple | 4 | 0.108 | <= 6k | none | 25 / 62 |
| standard | 10 | 0.270 | <= 14k (legacy route) | none | 10 / 25 |
| medium | **25** | 0.675 | 14k-23k | **30 -> 25** | 4 / 10 |
| large | 30 | 0.810 | 24k-32k | none | 3 / 8 |
| **xlarge** | **50** | 1.350 | 33k-77k | **80 -> 50** | 2 / 5 |
| very_large | 80 | 2.160 | 78k-175k | none | 1 / 3 |
| **huge** | **100** | 2.700 | 176k-241k | **declined today -> accepted** | 1 / 2 |

Margins are identical to S0 at every scenario (same ceiling per credit and allowance). Effects: removes the 2.7x cliff, lowers the price of 14k-23k and 33k-77k documents, creates no new price increase, and accepts some documents that are refused today. Fairness problem it leaves: dense spreadsheets still shift a band (ledger finding a) unless the density rule is also changed. Retries, failures, OCR: unchanged versus S0. Requires re-deriving the floors on the current synthesis model first, otherwise the thresholds above are wrong by the stale-floor error.

### Schedule A3: larger allowances gated on measured cost (conditional; not activatable today)

Largest allowances that still meet 70% **under the stress scenario** (`k = 0.7824`, ASSUMPTION): Starter 148 monthly / 124 annual-per-month; Professional 346 monthly / 288 annual-per-month. Rounded down to a proposal:

| Plan | Credits / month | Expected | Stress | Ceiling | Ceiling + 1 failed at cap |
|---|---:|---:|---:|---:|---:|
| Starter monthly | **145** | 87.0% | 70.6% | 63.2% | 29.3% |
| Starter annual | **120** | 87.1% | 70.8% | 63.5% | 29.8% |
| Professional monthly | **340** | 86.9% | 70.5% | 63.1% | 29.0% |
| Professional annual | **285** | 86.9% | 70.3% | 62.9% | 28.6% |

Capacity at 145 / 340 with S0 bands: 36 / 14 / 4 / 1 simple / standard / large / very large documents on Starter; 85 / 34 / 11 / 4 on Professional. **Activation gate (all required):** (1) measured p90 utilisation `u <= 0.65` and failure share `phi <= 0.15` from a Ready-cohort of adequate size (n to be set by the product owner; none exists today), reconciled to the provider invoice; (2) a per-document cumulative unpaid-spend limit (section 2, item 7); (3) the ceiling margin of 63% accepted explicitly, because it is below the 70% target by design and only holds if the stress inputs are right. If measured `u` is higher, this schedule is unprofitable at its target; the same table with measured `k` replaces it.

### Schedule A4: raise price instead of the allowance (documented alternative)

To hold 70% at the **ceiling** with 150 / 375 monthly credits the monthly price must be about KES 1,940 (Starter, +29%) and 4,849 (Professional, +38%); for annual plans the per-month equivalents are 55% and 66% above today's annual prices. Under the **stress** scenario the required monthly prices are about KES 1,518 (+1.2%) and 3,794 (+8.4%). This is a pricing change and a Paystack plan-code change; it is a product and commercial decision only.

### Schedule A5: annual parity (documented alternative)

Annual plans miss 70% at the ceiling (69.1 / 67.1). Lowering annual credits to 96 / 225 per month restores about 70% at the ceiling (largest allowances: 96.7 and 225.6). Alternatives from `docs/tasks/ai-credits-implementation.md:66`: raise annual prices (KES 15,600 / 38,800) or reduce the annual discount. Customer-visible either way.

### Component proposals (combine with any schedule above)

| Component | Today | Proposal | Reason and numbers |
|---|---|---|---|
| OCR | 20 + 1/page after 20, max 60 pages | **2 + 1 per page** (1 page = 3, 20 pages = 22, 60 pages = 62) | Per-page cap USD 0.02 is below USD 0.027 per credit, so ceiling margin is at least the plan margin; removes the 20-credit cliff for short scans. No measured OCR cost exists, so the proposal rests on the cap only. |
| Free grant | 20 credits | **40** (USD 1.08 / KES 140 provider ceiling per signup, vs 0.54 / KES 70 today) | 40 funds one incremental document (30), or four standard, or one scan plus analysis (24). Cost per signup is a CAC decision. |
| Reanalysis | full band | **fixed 10 credits** or **50% of band** | Evidence reuse is not measured; the fixed mode already exists. |
| Dense documents | +1 band by file type | **only when measured density and size justify it** | Ledger finding a. |
| Larger Free/Starter/Professional headline | 20 / 100 / 250 | **A1 scale (x10)** now; **A3** only after the gate | Section 1 invariant. |

## 6. Effect of retries, OCR, failures and heavy users, by schedule

* **Retries (same operation).** Free to the customer and to the cap: same quote, same cap, no new debit (`QuoteService::issue` `:149-151`). All schedules identical.
* **Failed work.** Credits are released, cost is absorbed. Margin impact is `phi/(1-phi) * psi * cap` per success, folded into `k`. The `k = 2` row shows the downside if each success follows a failure at full cap. A1 and A2 do not change it; A3 makes it worse in absolute terms because the allowance is larger.
* **OCR.** Each scan pays its own quote plus the analysis band; the OCR cap is separate (page based). The default extraction model's per-request ceiling is about USD 0.028, which the pre-fix 0.02 one-page cap could not fund (fixed in this PR). OCR followed by an analysis that quotes 30/80 can be refused after OCR spend (audit C4): OCR spend is then unrecovered. A combined pre-OCR range removes that exposure.
* **Heavy users.** Fully redeeming the allowance at cap yields the ceiling column. Ceiling margin is below 70% for annual plans today and for every enlarged allowance scenario in the prior audit (its section 5). The only bounded heavy-user path is credit exhaustion; the unpaid-retry path (section 2, item 7) is unbounded in this trace.
* **Dense and large documents.** Dense shifts one band; the synthesis floor forces 30/80 for incremental documents. A2 reduces price for the mid-range without lowering any cap.

## 7. Recommendation

1. **Do not publish new numerical allowances yet.** The data required by `docs/tasks/ai-credits-implementation.md:70-74` does not exist, and the margin cannot be validated on n = 5.
2. **Do now, safe and reversible while the flag is off:** fix quote accuracy (route prediction, ledger N1) and recompute the floors on the current synthesis model; adopt A2 bands (no price rises); adopt the OCR 2 + 1/page proposal if approved; choose the credit scale (A1) **before** anyone holds AI credits.
3. **How to offer much larger headline credits safely:** apply a pure scale factor (A1) so the USD ceiling is untouched. Genuine extra capacity comes only from lower worst-case provider cost per document: tighter reservation ceilings (output token caps sized to measured p95, smaller fallback reservations), route-correct quoting, and measured utilisation. Only then raise allowances (A3), with the failure-amplification limit in place.
4. **Do not enable workload billing for Professional customers without a transition story** (section 2, item 3): their large-document price rises 12-32x against today's flat per-document price.
5. **Before enabling:** cumulative per-document unpaid-spend limit, Ready-cohort cost data with p50/p75/p90/p95 by band x route x dense x OCR, invoice reconciliation, and the product-owner decisions listed in `docs/audits/docintel-ai-credits-independent-review.md`.

Nothing in this document was activated, and no value here is a measurement unless a line cites a repo source.
