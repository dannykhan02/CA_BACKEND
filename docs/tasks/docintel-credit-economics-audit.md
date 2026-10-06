# DocIntel credit economics audit

**Date:** 6 October 2026. **Scope:** read-only review of checked-in backend/frontend code, tests, historical cost note, Railway service metadata, and public FX/payment-fee references. **Decision status:** provisional design for evaluation; no price, allowance, or code change is approved by this report.

## Executive finding

The current customer unit is one completed document, irrespective of AI cost; paid plans also have a separate comparison quota. This exposes margin to expensive documents and unmetered optional work. The incremental pipeline has a USD document budget, but the default maximum is **USD 10**, or **KES 1,297.90** at the reference FX rate—nearly the whole Starter monthly price for one document. The cap is not a customer credit price and does not encompass every legacy, OCR, comparison, or Q&A cost path.

**No production cost quantiles can be responsibly reported yet.** Railway status confirmed project `superb-emotion`, production, and online `CA_BACKEND`, `Postgres`, and `ca-horizon-worker`. A metadata-only Postgres count and a bounded backend SSH connectivity check did not return a shell/result; the latter timed out after 25 seconds. No production row, secret, document text, or provider call was read. The only historical spend datum available is the prior cost-control note's account-balance fall from USD 15.87 to 8.99 (USD 6.88 across roughly 12 hours); it cannot be allocated to successful documents. Every cost distribution below is therefore **unmeasured**, and the proposed credit schedule is **conditional on cost caps and production validation**, not a finding that observed usage fits those bands.

## 1. Current credit and billing system

| Item | Current checked-in behavior |
|---|---|
| Starter | KES 1,500 monthly or 15,000 annually; 20 document analyses and 5 AI comparisons per monthly usage period; 1 GiB storage. |
| Professional | KES 3,500 monthly or 35,000 annually; 100 documents and 30 AI comparisons per monthly usage period; 5 GiB storage. |
| Free | 5 one-time **saved document credits** for eligible new Personal users, subject to email/IP/fingerprint trial guard; no monthly refresh. |
| Saved balance | `workspace_credits.documents_remaining`; free, legacy purchased, and referral credits share one balance and cannot currently be distinguished after grant except by ledger history. Existing referral reward is 10 saved document units. |
| Historical pack | `documents-100` at KES 2,000 remains configuration/legacy purchase metadata; current checkout accepts plans, not this pack. |
| Paid priority | Active subscription-period document allowance pays first. Saved credits remain untouched and become usable after paid access ends. Monthly allowances do not roll over. Annual purchases create 12 monthly usage periods. Purchase-time allowance snapshots govern active periods, so changing config alone does not rewrite existing contracts. |
| Document debit | One document unit is reserved at upload/processing admission; the counter is debited only on Ready, once per `documents.credit_accounted_at`. Failure/Needs Review and expired 24-hour reservations are released. A saved balance is not decremented on reservation; availability subtracts reserved operations. |
| Comparison debit | Structured comparison without selected AI terms has no AI quota charge. AI terms comparison reserves one **monthly comparison** unit immediately by incrementing `comparisons_used`; completion records a debit, failure decrements it once. Free/saved credits do not buy comparisons, except a separate unknown-legacy-access path. |
| Re-analysis | Incremental explicit re-analysis reopens failed/budget units and synthesis checkpoints while retaining old spend and does not create a new customer debit. Legacy optional intelligence retry is free; core retry retains once-per-document semantics. A paid document with no remaining allowance can encounter a new-request gate on some legacy paths despite the once-only debit. |
| OCR, visual, premium | Vision OCR is one provider call per rasterized page; embedded chart vision is separately called. Neither has a customer surcharge. There is no distinct premium/deep customer tier; direct/coarse/deep are internal routing modes. |
| Other AI | Document Q&A checks AI access but does not reserve/debit a credit; repeated Q&A can incur provider spend. Search access is also gated, though vector search itself is not an Anthropic charge. |

The code evidence is [`config/billing.php`](../../config/billing.php), [`config/credits.php`](../../config/credits.php), [`EntitlementService`](../../app/Services/EntitlementService.php), [`WorkspaceCreditService`](../../app/Services/WorkspaceCreditService.php), [`DocumentReprocessor`](../../app/Services/Documents/DocumentReprocessor.php), [`IncrementalPipeline`](../../app/Services/AI/Incremental/IncrementalPipeline.php), and [`CompareDocumentsJob`](../../app/Jobs/CompareDocumentsJob.php). Production environment overrides were **not** inspected, so the table states checked-in defaults rather than independently verified effective production prices/allowances.

### Exact grant, reserve, debit, release, check, and display map

| Action | Code location and semantics |
|---|---|
| Grant saved | `WorkspaceCreditService::grantTrial`, `completePurchase` for legacy purchases, and `grantReferralReward`; `WorkspaceObserver` initializes zero balance. |
| Grant monthly | `SubscriptionService::completePurchase` creates usage periods and ledger credits from checkout snapshots; grandfathered live legacy purchase can create ongoing Starter periods. |
| Reserve document | `DocumentUploadController::store`; `ExtractDocumentTextJob`, `GenerateEmbeddingsJob`, `OcrPageBatchJob`, `AnalyzeEmbeddedVisualsJob`, guarded intelligence jobs, and legacy core reprocess call `EntitlementService::reserveDocument`. |
| Debit document | Ready transitions in `GenerateInsightsJob` and `MergeDocumentEvidenceJob` call `WorkspaceCreditService::accountForReadyDocument`, which settles the period or saved balance. |
| Release document | `EntitlementService::summary` calls `releaseFailures` for failed/Needs Review, missing documents, or reservations older than `pipeline_hours` (default 24). This is a lazy release during summary, not an immediate failed-job refund in every path. |
| Reserve/debit/release comparison | `DocumentComparisonService::create`, `CompareDocumentsJob`, and `EntitlementService::{reserveComparison,settleComparison,releaseComparison}`. |
| Check allowance | `EntitlementService::{summary,assertProcessing,assertAiAccess,reserveDocument,reserveComparison}`; upload checks before storage and again under transaction. Q&A calls `assertAiAccess`. |
| Display allowance | Backend `WorkspaceCreditController`, `BillingController`, `SubscriptionService::catalog`, purchase status; frontend `billingApi.ts`, `documents.ts`, `WorkspaceCreditBalance.tsx`, `PlanCards.tsx`, `BillingPage.tsx`, `UploadModal.tsx`, `DocumentConnections.tsx`, `ReferralSection.tsx`, and checkout return page. Current copy explicitly says one saved credit covers one completed analysis and one AI comparison consumes one monthly comparison. |

`billing_operations` has unique `(kind,resource_id)` and attempt number; workspace row locks serialize admissions. `credit_ledger` has unique `reference` and append-only `insertOrIgnore` entries for credit/reserve/debit/release; counters and periods are authoritative, especially for pre-ledger history. `credit_accounted_at` prevents repeat document debits, and completed comparison status prevents repeat comparison debits. Purchase reference, provider transaction ID, webhook event key, row locks, and period uniqueness guard payment completion. These are valuable invariants to preserve, though a variable-price system needs explicit reserved/debited **amount**, funding bucket, and price snapshot on each operation; the current operation row records neither.

## 2. Real provider-cost distribution and data quality

`document_ai_runs` records model, purpose, status, document/chunk, input/output/cache tokens, attempt, and `estimated_cost_usd` derived from **actual provider response usage** and configured model rates. A missing usage response leaves cost null. Incremental `document_chunks.cost_accounting` records estimate, settled cost, and `actual_known`; timeouts retain the conservative bound for sent requests. `reserved_cost` accumulates settled spend plus an active reservation, so it must not be summed alongside run cost. `documents.ai_pipeline` has token count, density/routing, budget, and revision metadata.

| Successful document analysis, all billable calls for its initial result | USD | KES at 129.79/USD |
|---|---:|---:|
| p10 | Unavailable | Unavailable |
| p25 | Unavailable | Unavailable |
| Median | Unavailable | Unavailable |
| p75 | Unavailable | Unavailable |
| p90 | Unavailable | Unavailable |
| p95 | Unavailable | Unavailable |
| p99 | Unavailable | Unavailable |
| Maximum | Unavailable | Unavailable |

**Required extraction:** use successful initial Ready documents, include *all* their provider calls (including failed/retried calls that incurred usage), separate later revisions by time/revision, and reconcile to chunk settled cost without double counting. Segment by token-count decile, type, `routing.dense`, route/mode, OCR presence/page count, extraction call count, synthesis model, and partial/coverage state. Report both known-actual and conservative-unknown cohorts; do not treat null cost as zero. Historical provider invoices should reconcile aggregate computed USD spend and current configured rates, especially where model prices changed.

## 3. Cost by operation and coverage gaps

| Operation | Persisted evidence | Actual distribution | Accounting issue |
|---|---|---|---|
| Initial legacy analysis | `document_ai_runs` purposes for insights, entities, risk, deadlines, classification, summary | Unavailable | Cost not linked to one incremental budget; distinguish required and optional stages. |
| Incremental extraction, repair, context, synthesis | Run rows linked to chunk/stage; chunk `cost_accounting` settles known usage or unknown bound | Unavailable | Include split parents, failed outputs, retries, fallback synthesis, and partial result. |
| AI comparison | Run purpose `document_comparison`, linked to **base document** | Unavailable | No comparison ID on run; attribution to comparison requires time/context join or new instrumentation. Non-AI structured comparison has no provider call. |
| Vision OCR | Run purpose `ocr`, one per page | Unavailable | OCR precedes normal text-based band selection; page-count preflight needed. Tesseract has no Anthropic token cost but has infrastructure cost. |
| Embedded visual | Run purpose `chart_vision`, capped at 12 visuals by config | Unavailable | Included in document work but not separately charged. |
| Re-analysis | New run rows and `ai_pipeline.analysis_revision` | Unavailable | Revision-to-run linkage is indirect; explicit re-analysis can spend without a new customer debit. |
| Q&A | Run purpose `document_qa` where a document context is present | Unavailable | Access gate only; no customer debit or per-user limit found. |

The previous cost-control note documents one 62,125-token PDF with extensive splitting and a merge timeout and a USD 6.88 provider-balance decline over ~12 hours. This demonstrates a tail risk, **not** a per-document price or percentile. The active incremental budget formula is `min(USD 10, USD 0.50 + USD 0.025 × document_tokens/1,000)` and protects synthesis reservation, but it may stop with partial coverage. It is not a guarantee across all document/AI routes or later re-analysis.

## 4. Cost bands and pre-processing estimate

Use the **fewest understandable bands that production costs validate**. A provisional four-level ladder is below. Its boundaries are **provider budget ceilings for a future product**, not measured cost quantiles or a claim that current documents complete beneath them. The same charge must be shown and accepted before AI spend starts.

| Proposed band | Provisional trigger after extraction | Credits | Maximum allowed provider spend at KES 3.50/credit |
|---|---|---:|---:|
| Simple | Short text-layer prose and direct route | 4 | KES 14 (USD 0.108) |
| Standard | Ordinary text-layer, non-dense document | 10 | KES 35 (USD 0.270) |
| Large/dense | High tokens, dense data, or coarse route | 30 | KES 105 (USD 0.809) |
| Very large | Deep route or high preflight commitment | 80 | KES 280 (USD 2.157) |
| OCR surcharge | Add to document band; page cap required | +20 | Additional KES 70 (USD 0.539) |
| AI terms comparison | Two already processed documents | 12 | KES 42 (USD 0.324) |
| Explicit full re-analysis | Same current document band | 4/10/30/80 | Same budget as initial analysis; internal recovery retry remains free |
| Optional deep/premium, if ever productized | Explicit opt-in only | +20 | Additional KES 70 (USD 0.539) |

There is **no evidence yet** that these caps can produce acceptable quality for each band. In particular, a simple document may cost more than USD 0.108 after synthesis, and an OCR document may need more than USD 0.539 extra. Reject or revise the schedule if observed p90/p95 or minimum viable completion cost exceeds its cap. Do not silently deliver a truncated result as a normal completed analysis.

**Recommended charging method:** hybrid. Before upload, show a likely range and the file-size/OCR conditions. Extract text and classify density/route locally, then show an **exact, fixed** credit price and obtain customer confirmation before paid model calls. For scanned files, determine page count and OCR requirement via local raster/preflight before vision OCR; reserve the full quoted document-plus-OCR price before the first vision call. Reserve atomically, settle exactly that price on successful contracted result, release on failed work. Internal retries/fallbacks consume DocIntel's provider budget, never additional customer credits. If the preflight predicts beyond the top band/cap, decline or offer an explicit separately quoted path. Charging by final actual USD cost would create surprise bills and is unsuitable for launch.

## 5. Candidate internal exchange rates

An internal **cost ceiling** per credit is a risk-control parameter, not a token exchange rate and not the customer's KES value. The following uses illustrative KES costs only; none is an observed percentile.

| Hypothetical provider cost | KES 1/credit | KES 2/credit | KES 3.50/credit | KES 5/credit | KES 10/credit |
|---:|---:|---:|---:|---:|---:|
| KES 10 | 10 | 5 | 3 | 2 | 1 |
| KES 35 | 35 | 18 | 10 | 7 | 4 |
| KES 105 | 105 | 53 | 30 | 21 | 11 |
| KES 280 | 280 | 140 | 80 | 56 | 28 |

Formula: `ceil(provider KES cost / internal KES ceiling per credit)`. Once the missing median/p75/p90/p95, comparison, OCR, and synthesis distributions exist, apply this formula to each measured value with a separate margin buffer and round to a simple customer table. KES 1 creates large charges; KES 10 allows unacceptable full-use spend at the candidate allowances. KES 3.50 makes 4/10/30/80 understandable **if** those budget ceilings are operationally feasible. Actual customer revenue per fully used credit is KES 15 on Starter and KES 14 on Professional at 100/250; it is not KES 3.50.

## 6. Margin targets and plan budgets

Reference FX is **KES 129.79/USD on 6 October 2026** from [Central Bank of Kenya](https://www.centralbank.go.ke/home/forex-2/). Use an analysis parameter, not a hardcoded billing rate; base FX 129.79 and adverse +10% = 142.769. [Paystack Kenya pricing](https://paystack.com/ke/pricing) lists 1.5% M-PESA, 2.9% local cards, and 3.8% international cards. Tables use **2.9% local-card fee** as a conservative ordinary channel; actual channel mix, taxes, refunds, and negotiated fees are unknown. Provider-only gross margin is `(revenue - provider cost)/revenue`; contribution margin here deducts Paystack fees too. Engineering, hosting, salaries, tax, fraud, support, and free-user acquisition are outside both figures.

| Target margin | Starter max provider cost, provider-only / after local-card fee | Professional max provider cost, provider-only / after fee | After-fee max KES/provider-cost per credit at 100/250 |
|---|---:|---:|---:|
| 50% | 750 / 706.50 | 1,750 / 1,648.50 | 7.065 / 6.594 |
| 60% | 600 / 556.50 | 1,400 / 1,298.50 | 5.565 / 5.194 |
| 70% | 450 / 406.50 | 1,050 / 948.50 | 4.065 / 3.794 |
| 80% | 300 / 256.50 | 700 / 598.50 | 2.565 / 2.394 |

**Practical floor:** seek at least **70% contribution margin at full allowed use** under baseline FX, plus explicit FX/provider-price stress. This leaves room for free acquisition, retries, unmetered legacy paths, support, and volatile document mix, but it is a proposed policy rather than an observed necessity. A 3.50 ceiling with 100/250 credits leaves KES 56.50 / 73.50 of provider headroom above its cap before the 70% after-fee threshold. A stronger operational safety buffer is to target at most 60% of each band cap in normal work and reserve the remaining 40% for retries, uncertain usage, and mix drift. The fixed customer price must never rise after confirmation.

## 7. Starter and Professional profitability: conditional balanced model

Assumptions for *these calculations only*: Starter 100 credits, Professional 250, KES 3.50 hard maximum provider spend per redeemed credit, local-card fee 2.9%. Light uses 25% of credits at 50% of cap; typical uses 65% at 60%; heavy uses 100% at 60%; adverse uses 100% at 90%; extreme maximizes permitted provider cost at 100% of cap. Fractions represent aggregate credit consumption, not a claim about observed customer mix. A system that actually enforces the cap can have these margins even when document mix changes; a system that merely posts these prices without caps cannot.

| Starter profile | Revenue | Fee | Provider cost | Provider gross profit / margin | After-fee contribution / margin |
|---|---:|---:|---:|---:|---:|
| Light | 1,500 | 43.50 | 43.75 | 1,456.25 / 97.1% | 1,412.75 / 94.2% |
| Typical | 1,500 | 43.50 | 136.50 | 1,363.50 / 90.9% | 1,320.00 / 88.0% |
| Heavy | 1,500 | 43.50 | 210.00 | 1,290.00 / 86.0% | 1,246.50 / 83.1% |
| Adverse | 1,500 | 43.50 | 315.00 | 1,185.00 / 79.0% | 1,141.50 / 76.1% |
| Extreme allowed | 1,500 | 43.50 | 350.00 | 1,150.00 / 76.7% | 1,106.50 / 73.8% |

| Professional profile | Revenue | Fee | Provider cost | Provider gross profit / margin | After-fee contribution / margin |
|---|---:|---:|---:|---:|---:|
| Light | 3,500 | 101.50 | 109.38 | 3,390.62 / 96.9% | 3,289.12 / 94.0% |
| Typical | 3,500 | 101.50 | 341.25 | 3,158.75 / 90.3% | 3,057.25 / 87.4% |
| Heavy | 3,500 | 101.50 | 525.00 | 2,975.00 / 85.0% | 2,873.50 / 82.1% |
| Adverse | 3,500 | 101.50 | 787.50 | 2,712.50 / 77.5% | 2,611.00 / 74.6% |
| Extreme allowed | 3,500 | 101.50 | 875.00 | 2,625.00 / 75.0% | 2,523.50 / 72.1% |

Maximum safe provider budget at a 70% contribution target is KES 406.50 Starter and 948.50 Professional; the candidate spends at most KES 350/875. Annual prices represent KES 1,250/2,916.67 per month before fees, so the same monthly allowances have **lower** annual-plan margins; at 100% cap the annual-plan after-fee monthly equivalent margins are approximately 69.1% Starter and 67.1% Professional. Annual plans therefore fail the proposed 70% full-use contribution target unless allowances or budgets differ, or the target is relaxed. Grandfathered free Starter periods have zero new recurring revenue and must be budgeted separately; no paid-plan gross-margin claim applies to them.

### Visible-credit scale test

At fixed KES 3.50 cost ceiling and full use, Starter 100/250/500/1,000 permits KES 350/875/1,750/3,500 provider spend. After the KES 43.50 fee, contribution margin is 73.8%/38.8%/-19.6%/-136.2%. At Professional 250/500/1,000, permitted cost is KES 875/1,750/3,500 and after-fee margin is 72.1%/47.1%/-2.9%. Larger numbers work only if every operation's charge scales proportionally too, which adds no real value and more rounding/noise. Starter 100 / Professional 250 is the clearest provisional scale.

## 8. Free credits, packs, and overages

**Provisional free grant: 20 one-time credits**: two standard analyses, or several simple documents, at most KES 70 provider spend per fully redeemed signup under the same cap. For 100 signups, the mathematical worst case is KES 7,000; a 65%-use, 60%-cap scenario is KES 2,730; an adverse 100%-use, 90%-cap scenario is KES 6,300. These are acquisition-cost scenarios, not observed free-user costs, and exclude hosting. Free credits should not renew. Trial anti-abuse, referral rewards, grandfathered balances, and existing saved credits need explicit conversion/grandfather rules rather than multiplying old units silently.

**Launch recommendation: neither new packs nor automatic overages initially.** First measure paid conversion, cost quantiles, refund behavior, and credit exhaustion. A future optional saved pack could be **50 credits for KES 900** (KES 18/credit, above the subscription's KES 15/14 at full use): 2.9% fee KES 26.10; typical provider cost KES 68.25 and contribution margin 89.5%; adverse provider cost KES 157.50 and margin 79.6%; extreme capped KES 175 and margin 77.7%. Saved packs create a long-lived liability, so conversion and expiration policy must be settled first. Do not permit unpriced automatic overages, and do not allow packs to undercut subscriptions on effective price per credit.

## 9. Sensitivity and break-even

The table uses the balanced plan, 100% of credits redeemed at the KES 3.50 cap unless otherwise stated. In FX/provider-price shocks, this assumes existing USD operation caps are **not** re-denominated; production should instead monitor and lower USD admission ceilings as FX moves.

| Stress | Starter provider cost / after-fee margin | Professional provider cost / after-fee margin |
|---|---:|---:|
| Provider cost falls 25% | 262.50 / 79.6% | 656.25 / 78.3% |
| Baseline cap | 350 / 73.8% | 875 / 72.1% |
| Provider cost rises 25% | 437.50 / 67.9% | 1,093.75 / 65.9% |
| USD/KES adverse +10% | 385 / 71.4% | 962.50 / 69.6% |
| Cost +25% and FX +10% | 481.25 / 65.0% | 1,203.13 / 62.7% |
| 50% allowance use at 60% cap | 105 / 90.1% | 262.50 / 89.6% |
| 80% allowance use at 60% cap | 168 / 85.9% | 420 / 85.1% |
| 100% allowance use at 60% cap | 210 / 83.1% | 525 / 82.1% |
| Mix 2× more expensive, cap enforced | At most 350 / at least 73.8% | At most 875 / at least 72.1% |

Without cap enforcement, 2× a median-cost mix could double realized cost per credit and make these numbers invalid. Under a hard cap, more expensive work uses a higher quoted band, yields fewer completed operations, or is declined. Full-use contribution break-even occurs at KES **14.565** provider cost per credit for Starter and **13.594** for Professional (revenue less 2.9% fee divided by credits), about 4.16× and 3.88× the proposed KES 3.50 ceiling. The 70% after-fee target is breached earlier: KES 4.065 and 3.794 respectively. At baseline cap, +25% provider cost or +10% FX already breaches Professional's 70% target if caps remain fixed in USD; profitability remains positive. Annual-plan break-even and target headroom are smaller.

## 10. Abuse and margin protection

Highest-risk current patterns are repeated dense spreadsheets or 20 MB files with huge extracted token counts; scanned PDFs with many vision OCR pages; explicit re-analysis and repeated summary regeneration; comparisons whose provider calls lack per-comparison cost cap; unmetered Q&A; timeouts with unknown billable output; duplicate uploads with different IDs; and grandfathered/free access. Current `DOC_MAX_UPLOAD_KB` defaults to 20 MB, but file bytes are not a token/page ceiling. The incremental USD 10 budget limits one pipeline yet still permits a single costly old-unit debit, and the legacy path is not shown to share that budget.

Future guardrails: local preflight token/page/density limits; page-count-based OCR quotes and hard page cap; per-operation provider commitment ceiling inclusive of extraction, repair, visual, synthesis, retries and uncertain calls; daily workspace/global provider budget; duplicate-file/idempotency key policy; re-analysis cooldown and explicit price for user-requested full repeat; separately priced or rate-limited Q&A; comparison cost cap; a top-band refusal/manual-quote path; and clear partial-result/refund policy. An internal retry, timeout, queue restart, split, or fallback must never add customer credits to the quoted price. Unknown post-timeout provider usage should consume its pre-call reserved **internal** cost bound for economics, while the customer pays only for a successful agreed result.

## 11. Three candidate systems and recommendation

All three retain KES 1,500 Starter / 3,500 Professional monthly prices and assume a provider-cost cap applied to *every* costly operation. Typical = 65% credit use at 60% of cap; adverse = 100% at 90%; margins are after 2.9% payment fee. Charges below are `simple / standard / large / XL; OCR add-on; AI comparison; optional premium add-on`; explicit full re-analysis uses the document's band, internal recovery is free.

| System | Starter / Professional credits | Free one-time | Charges | Max KES cost/credit | Typical margin S/P | Adverse margin S/P | Main trade-off |
|---|---:|---:|---|---:|---:|---:|---|
| Conservative | 80 / 200 | 12 | 4/10/30/80; +20; 12; +20 | 3.00 | 90.9% / 90.4% | 82.7% / 81.7% | Strongest spend protection; fewer normal actions and short free trial. |
| **Balanced, conditional recommendation** | **100 / 250** | **20** | **4/10/30/80; +20; 12; +20** | **3.50** | **88.0% / 87.4%** | **76.1% / 74.6%** | Clear scale and baseline 70%+ full-use margin; no proof yet that budget ceilings permit useful results. |
| Aggressive | 150 / 375 | 30 | 4/10/30/80; +20; 12; +20 | 4.00 | 81.5% / 80.4% | 61.1% / 58.5% | More visible credits; adverse margin below proposed safety floor. |

Extreme 100%-cap after-fee margins are Conservative 81.1%/80.0%, Balanced 73.8%/72.1%, Aggressive 57.1%/54.2%. **Choose Balanced only as a measurement-gated launch candidate.** The actual launch decision must wait for production p10–p99 cost and minimum viable result cost, including OCR and comparisons, and a validated cap covering every provider call. If measured costs exceed the proposed band ceilings, revise operation charges/allowances or price; do not claim the shown typical margins as observed. Customer terminology: **“monthly AI credits”** for renewable plan balance, **“saved AI credits”** for one-time/purchased balance, and **“X credits for this analysis”** in the preflight quote. Explain that credits measure work size/features and that the shown charge is final for a successful operation; failed work releases it. Avoid “one credit = one document” and avoid exposing provider tokens or USD in customer copy.

**100% usage conclusion:** under enforced KES 3.50 provider-cost ceiling per redeemed credit, the Balanced **monthly** plans remain profitable even at 100% redemption and worst allowed spend: KES 1,106.50 Starter and KES 2,523.50 Professional contribution after modeled local-card fees (73.8%/72.1%). **Current production profitability at 100% usage is unknown** because actual cost distribution, effective production configuration, and cross-operation caps were not verified; current one-document-unit billing does not support this guarantee. Annual plans miss the proposed 70% full-use target at the same monthly credit grants.

## 12. Exact later code impact and tests (no implementation)

| Area | Required later change |
|---|---|
| Plan/price config | `config/billing.php`, `config/credits.php`, `SubscriptionService::catalog/completePurchase`, checkout allowance snapshots; define monthly AI-credit quantity, annual economics, saved-credit conversion and referral units. |
| Customer ledger | `CreditLedger`, `billing_operations` migration/model access, `workspace_credits`, `subscription_usage_periods`, `EntitlementService`, `WorkspaceCreditService`; store immutable quote, unit amount, funding bucket, operation key/attempt, reserved and settled quantities. Keep forward-only entries and unique references. |
| Admission and settlement | `DocumentUploadController`, `UploadDocumentRequest`, `ExtractDocumentTextJob`, `OcrPageBatchJob`, guarded jobs, `GenerateInsightsJob`, `MergeDocumentEvidenceJob`, `DocumentReprocessor`, incremental planner/cost settlement; add local quote gate before first paid AI call and exact reservation/release. |
| Other operations | `DocumentComparisonService`, `CompareDocumentsJob`, `AnthropicClient` comparison/OCR/vision/Q&A call sites, Q&A controller; associate run cost with billable operation and enforce caps. |
| API/UI | Workspace credit and billing controllers/resources, plan catalog, `CA/src/billingApi.ts`, `documents.ts`, `PlanCards.tsx`, `BillingPage.tsx`, `WorkspaceCreditBalance.tsx`, `UploadModal.tsx`, `DocumentConnections.tsx`, pricing/landing page, checkout and return page, insufficient-credit UI; show quote and bucket priority clearly. |
| Evidence and reconciliation | `DocumentAiRun` metadata, `document_chunks.cost_accounting`, read-only usage report, payment fee/FX reporting; link initial runs, retries, re-analysis, comparison, OCR, and unknown-usage bounds to operation IDs. |

Required deterministic tests: band classification/debit at boundaries; OCR and large-document surcharge; comparison and explicit re-analysis price; premium opt-in only; exact atomic multi-credit reservation, release, and funding priority; failed/partial work and timeout refunds; retry/queue restart/no duplicate charge; no negative balances at concurrency; same quoted price despite split count/provider cost; saved balance preserved during paid period; monthly reset/no rollover and annual periods; upgrade/downgrade snapshot behavior; insufficient credit before paid AI call; changed FX/provider pricing; free/referral/legacy conversion; duplicate submissions; hard budget inclusion of OCR, visual, comparison and Q&A; unknown usage conservative settlement; ledger-to-counter reconciliation. Existing relevant suites include `WorkspaceCreditsTest`, `SubscriptionBillingTest`, `CreditPurchaseTest`, `IncrementalDocumentPipelineTest`, `DocumentIntelligenceFailureTest`, and `DocumentAiUsageReportTest`. No tests were run because this is a read-only audit and no application code changed.

## 13. Missing production data, assumptions, and release gate

1. Production counts and successful initial-run p10/p25/median/p75/p90/p95/p99/max by route, tokens, density, file type, OCR, synthesis model, and completeness; sample size and date range.
2. Per-operation actual and conservative-unknown USD cost distributions for OCR pages, embedded visuals, comparisons, synthesis, Q&A, user re-analysis, internal retries, and failed work. Comparison needs a durable comparison-to-run key.
3. Provider invoice reconciliation, current effective production model/rate/config values, non-Anthropic infrastructure OCR cost, and actual payment-channel mix/fees. No secrets or document content are needed for these aggregates.
4. Cohort usage/redemption rates, annual-plan share, grandfathered users, free-to-paid conversion, referral issuance, refunds/chargebacks, and remaining saved-credit liability.
5. Quality/coverage results under each proposed provider budget; the band table must be revised if useful results cannot fit the ceiling.

**Model assumptions:** prices and allowances are checked-in defaults; baseline FX 129.79 KES/USD; 2.9% local-card payment fee; illustrative 65% typical utilization/60% cap realization and 90% adverse realization; future per-operation hard cost caps; no tax, hosting, storage, or acquisition spend in the margin table. Prices of individual AI calls are calculated from response usage using checked-in model rates, not independently reconciled provider invoices. The proposed operation cost ceilings and quantile placeholders are **not empirical measurements**. Production SQL must run in a read-only transaction and return aggregate metadata only; never select names, extracted text, prompts, quotes, Paystack payloads, or credentials.

**Release gate:** obtain the missing production aggregates, validate whether each band can finish useful work within its cap at p95 and on pathological inputs, reconcile costs to provider billing, then rerun this model for monthly **and annual** plans at current FX and +10% FX / +25% price stress. Until then, this report is an auditable design and bounded stress test, not a verified profitability forecast.
