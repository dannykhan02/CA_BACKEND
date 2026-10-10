# B1 grounding fix — report

The four grounding defects the paid B2 evaluation found, fixed in application code only. No
migration, no change to extraction, extraction prompts, Compact JSON, Candidate Inventory, routing,
Stage A materiality, key-figure rules, pricing, `max_rejected_claims` or any B2 strictness setting.
No provider call was made, no Railway command was run, and no `.env*` file was read. Nothing was
pushed, merged or deployed.

Sections 1-9 are the diagnosis, verified against the code and the evaluation evidence before
anything was changed. Sections 10 onwards are the result.

## 1. Starting state (section 2 of the task)

| | |
|---|---|
| Worktree | `/home/dan/Development/code/Wu-Tang/flask/January/CA/backend` |
| Branch | `fix/b1-grounding-defects` |
| HEAD | `b97c8599d0b9a1d50f5cda940ca8af9043cfa340` |
| `origin/main` | `b97c8599d0b9a1d50f5cda940ca8af9043cfa340` (identical) |
| `b97c859` in history | yes (HEAD *is* b97c859) |
| `git status` at start | clean |

Confirmed present: B1 API wiring (`BriefReadService`, `BriefAssembler`, the
`/api/documents/{id}/intelligence` read path) and B2 infrastructure (`StageASnapshot`,
`NarrativeContextBuilder`, `NarrativeSynthesizer`, `NarrativeVerifier`, `NarrativeCheckpoint`).

**No DIAGNOSIS_MISMATCH.** All four defects reproduce in the code as described, and the evidence
artifacts agree with the code. Two details in the evaluation report are refined below (§3.2, §6).

## 2. Baseline test counts

One full backend suite, on the unmodified tree (HEAD `b97c859`, `git status` clean), against an
isolated database. This is the **before** column for task §21.

| | |
|---|---|
| Tests | **1166** |
| Passed | **1161** |
| Failed | **2** |
| Skipped | **3** |
| Assertions | **8654** |
| Duration | 567 s (9m27s) |
| Exit | `failed` (the two failures below) |

Both failures are the machine's known pre-existing environmental ones, unrelated to grounding:

- `HealthCheckTest::test_healthy_response_shape_and_status_code` — 503, `Connection refused` (no Redis)
- `HealthCheckTest::test_storage_check_does_not_touch_the_real_disk` — same cause

So the real baseline to hold is **1161 passed / 2 environmental failures / 3 skipped / 8654
assertions**. The after-change suite must not move the failure set and must not reduce passes other
than by the two intentional assertion updates in §7.2.

Run identity, for reproduction:

- DB: `b1fix_baseline_test` on the throwaway PostgreSQL 16 at `127.0.0.1:54329` (instance started by
  an earlier task; `b1fix_after_test` was created for the after-change run and is still unused)
- command: `DB_HOST=127.0.0.1 DB_PORT=54329 DB_DATABASE=b1fix_baseline_test php artisan test`
- phpunit PID: `20906`
- log: `<session scratchpad>/logs/baseline-full.log` (`artisan test` emits one JSON line)

## 3. Defect 1 — currency grounding. Root cause confirmed

`BriefVerifier::validNumericValue()` (`app/Services/Intelligence/Brief/BriefVerifier.php:296-299`)
requires a `money` value's `currency` to match `/^[A-Z]{3}$/D`.

`ValueParser::parse()` (`app/Services/Intelligence/Values/ValueParser.php:43-44`) writes
`currency = $measurement->currency` and `unit = $record['unit']`. `MeasurementParser::currency()`
(`MeasurementParser.php:165-179`) resolves `$valueText` **before** `$unitText` and deliberately
returns the literal `'$'` for a bare dollar sign ("not a knowable code"). So for the evaluation's
records — value `"$120 billion"`, unit `"USD"` — `currency` is `'$'` and the ISO code sits in
`unit`. `validNumericValue()` returns false and the record grounds nothing.

Verified from `review-packet.json`: every monetary record on both evaluation documents has
`typed.value.currency = "$"` and `typed.value.unit = "USD"`.

### 3.1 Planned fix (not applied)

1. `MeasurementParser`: add `public function isoCurrency(?string $text): ?string` — `currency()`
   filtered to `/^[A-Z]{3}$/D`, so a bare `'$'` and the 2-letter `'UA'` return null. Reuses the
   existing `CURRENCIES` table; no second implementation.
2. `BriefVerifier`: add `recordCurrency(array $value): ?string` — return `currency` when it is
   already ISO; otherwise, **only** when `currency === '$'`, resolve from that same record's own
   `unit`; else null. A bare `'$'` with no unit stays unusable, and `'$'` + `CAD` resolves to `CAD`,
   never `USD`.
3. Use it in three places: the `money` branch of `validNumericValue()`; the claim-unit comparison in
   `numbersGrounded()` (`:227`), so a claim written `USD 97.4 million` is compared against the
   record's *resolved* code and a `USD` claim against an `AUD` record still fails; and `comparable()`
   (`:498`), where `'$' === '$'` currently makes a USD record and a CAD record comparable for growth
   derivations.
4. `NarrativeVerifier::currencies()` (`:250-261`) reads the same resolution, so B2's money gate
   cannot disagree with B1's grounding. This only widens what `statesMoney()` catches — it tightens
   the key-figure gate, it does not loosen it.

The existing re-parse cross-check at `BriefVerifier:303-311` (`$parsed->currency === $value['currency']`)
is **left untouched**, which is what keeps `currency` faithful to the raw text: the ISO code is read
off the record's own `unit`, never substituted into `currency`.

### 3.2 Refinement to the evaluation report

Report §6.5 left open whether defect 1 also shrinks the key-figure set. **It does not.**
`KeyFigureSelector::select()` (`:26`) requires only `is_string($value['currency']) && !== ''`, which
`'$'` satisfies. Headline eligibility never inspected the ISO code, so the key-figure set on both
evaluation documents is unaffected by this fix — and `KeyFigureSelector` must not be changed anyway.

`Materiality/SignalEvaluator::numericGroup()` and `ForcedItemRules::currencyMagnitude()` have the
same symbol/ISO conflation (they group by `currency` equality, so two different real currencies both
stored as `'$'` are treated as one family). Materiality is frozen by the task, this affects scoring
only and never grounding, so it is **reported, not changed**.

## 4. Defect 2 — claim entity extraction. Root cause confirmed

`BriefVerifier::entities()` (`:400-405`) matches runs of *consecutive capitalised* words
(`\b(?:\p{Lu}[\p{L}\p{M}]+\s+){1,}\p{Lu}[\p{L}\p{M}]+\b`), and `entitiesGrounded()` (`:431-435`)
compares each run by exact normalised equality against record-owned names. Two consequences, both
confirmed in `diagnosis.json`:

- a sentence-initial function word is swallowed into the run: `In India`, `In Sierra Leone`;
- a lowercase particle breaks the run: `The Government of India` yields only `The Government`.

### 4.1 Planned fix (not applied)

Extraction only; the `known` set is **not** widened.

1. Allow a narrow set of lowercase particles inside a run (`of de del della da dos van von der den
   bin al`), so `Government of India` is extracted whole. `and`/`the` are deliberately excluded —
   they would merge two distinct entities into one run.
2. Ground a run if **either** its own normalised form **or** its form with one leading grammatical
   wrapper removed (`the a an in on at by for from to of with within during under over across into
   after before since between through per and but as that this these those`) matches a record-owned
   name. Checking both forms is monotone: everything that grounds today still grounds, so a record
   whose subject genuinely begins with an article (`The Hague`) cannot regress.

This resolves `In India` → `India`, `In Sierra Leone` → `Sierra Leone`, and
`The Government of India` → `Government of India`. It invents no alias, extends no entity, and
borrows nothing from another record.

### 4.2 Two reviewed-supported claims will still be rejected, deliberately

- **C18 / C23** (`Swachh Bharat`, `Clean India`). Those names appear only inside the free-text
  `data.value` of a non-entity record (`"Government of India launched the Swachh Bharat (Clean
  India) Mission"`). The grounding contract reads entity names from `subject`, an `entity` record's
  `value`/`aliases`, `confirmed_entity_names`, and a confirmed `entity_ref` — not from arbitrary
  record prose. Grounding them would require substring search inside a record's value, which task
  §3 forbids ("turning strict provenance checks into fuzzy document-level searches"). Task §8's
  proviso applies: supported claims pass *provided the cited evidence satisfies the existing
  grounding contract*. These do not. Reported, not forced.
- **C13 / C20 / C27** (the residual-percentage claims) stay rejected — see §6.

## 5. Defect 3 — chart-derived monetary records type to null. Root cause confirmed

Not an extraction gap. `ValueParser::cited()` (`:115-124`) requires `str_contains($quote, $raw)`,
an exact byte match. Chart labels reach the quote with the source's own line wrapping:

| record `value` | how the record's own quote states it |
|---|---|
| `$1.584 billion` | `...Total\n$1.584\nbillion\nPrivate sector...` |
| `$8.263 billion` | `...$8.263 \nbillion\n...` |

`cited()` fails, so `parse()` never types the value and `typed.value` is null. Verified for all six
null-typed monetary records in `review-packet.json`: **0 of 6 match verbatim, 6 of 6 match with runs
of whitespace collapsed.** Every record that *did* type has its value contiguous in its quote.

### 5.1 Planned fix (not applied)

In `ValueParser::cited()`, compare with runs of whitespace collapsed on both sides. Nothing else is
normalised: the digits, the scale word and the currency marker must all still be present, in order,
in **this** record's own quote. The number, scale and currency continue to come from the record's own
`value` string.

Paired tightening, in the same function: require the match not to sit inside a longer number
(`(?<![\d.,]) … (?![\d])(?![.,]\d)` — the same digit guard `BriefVerifier` already uses at `:251`,
`:374`, `:443`). This is what makes §6 work, and it cannot cost a legitimate record: the only values
it drops are those whose sole occurrence is a substring of a different figure.

## 6. Known-negative residual percentage — it is already one of the 29

Task §9 asked whether the `100 − 46 − 49` case is among the reviewed 29. **It is**: items
**C13, C20 and C27**, all `rejected` / `partially_supported` with `components_failing: ["figure"]`.
It is therefore not duplicated as a separate adversarial fixture.

How it currently fails matters. Those three were rejected on `brief_entities_grounded` **only** —
their numbers all grounded, because the `5%` record *does* carry `typed.value.number = 5`. It does
so because `cited()` found `"5%"` inside `"45%"`: the chart quote's percentages are
37/41/30/49/46/45/20/17/14/33/49/18/31/50/19 and the axis labels 0–100%, with no standalone `5%`.

So after the defect-2 fix the entity rejection survives only because `Core Resources` ≠ `UNICEF` and
≠ the record label — an incidental reason. The digit-boundary guard in §5.1 is what makes the
rejection principled: `"5%"` is no longer cited, the record types to null, and the claim is rejected
on `brief_numbers_grounded`, which is the actual defect the independent reviewer identified. Both
rejections should be asserted, with the numeric one named as the load-bearing guard.

## 7. Defect 4 — incidental duration numbers. Root cause confirmed

`numbers()` (`:194-215`) strips dates and periods but not durations, so `"over the last 10 years"`
yields `10`, which then has to match a typed value. The fallback at `:243-257` cannot help: it needs
a typed `duration` date, and these records' spans live in `data.period` (`"10 years"`, `"five
years"`, `"last decade"`) with `typed.dates === []` — confirmed in `review-packet.json`, because
`PeriodParser` does not parse a bare span.

`diagnosis.json` confirms the ungrounded number for C26 is exactly `{"n": 10}`.

### 7.1 Planned fix (not applied)

Represent a duration as a time span and validate it as one:

1. `durations()`: extract a qualifier (`over within for in during across after before past last next
   previous every per of`), an optional short chain of determiners/fillers, then a **mandatory**
   count (digits or `one`…`twelve`) and a duration unit (`second`…`year`, `decade`). Requiring the
   count is what keeps `"for the year ended 2024"` out of the duration path.
2. Strip those spans in `numbers()` alongside dates and periods, so the count never becomes a
   standalone metric.
3. New `durations_grounded` check: normalise each span to `"<count> <unit>"` (number words mapped to
   digits, units singularised) and require a **cited** record to own the same span, from either its
   own `data.period` — additionally requiring that the record's own quote states it, whitespace
   collapsed — or a typed relative `duration` date. Record-local; no neighbour supplies a span.
4. **Remove** the duration fallback in `numbersGrounded()`. It currently lets a duration satisfy a
   number (a claim `"Revenue was 30."` grounds against a typed duration `"within 30 days"`), which
   is precisely what task §7 forbids. The B1 `timeline.relative_due` template path keeps working,
   now through `durations_grounded`.

`durations_grounded` must **not** be added to `BriefAssembler`'s drop list (`:139`), which stays
`cites_available, numbers_grounded, dates_grounded, periods_grounded, units_consistent`.

### 7.2 Two existing unit-test assertions will need updating

Both in `tests/Unit/IntelligenceBriefVerifierTest.php`, and both are assertions about the new
check's existence rather than relaxations:

- `:43` `assertCount(11, $result['checks'])` → 12, once `durations_grounded` exists.
- `:234-240` expects `numbers_grounded` *failed* for `"Submit within 30 days."`; it becomes
  `skipped` (the span is no longer a number) and `durations_grounded` *failed*. The test's intent —
  the claim must not ground — is preserved and made more precise.

## 8. What B1 restoration actually depends on

`BriefAssembler` drops a block only on `cites_available`, `numbers_grounded`, `dates_grounded`,
`periods_grounded`, `units_consistent` — **not** on `entities_grounded`. So the live B1 degradation
reported in §6.1/§6.2 of the evaluation is caused by defects **1 and 3 only**; the entity defect
affects B2 claims and `verify()`'s reasons, not which B1 blocks survive. Expect defect 3 to also
change *which* measures appear, because the newly typed `$1.584bn` / `$8.263bn` records become
key-figure eligible and `key_figures.max` is 6 — an expected consequence, not an unexpected change.

## 9. Test gaps A, B, C and E are largely closed at HEAD already

`tests/Feature/IntelligenceBriefApiWiringTest.php` (added in b97c859) covers more than the task
assumed. Remaining work is extension, not construction:

| Gap | State at HEAD | Still needed |
|---|---|---|
| A (direct vs API, rich **and** thin) | `test_rich_and_thin_b1_match_the_direct_assembler_with_b2_off`, with a recursive `ksort` canonicaliser | add stable float formatting to `canonical()` |
| B (10 reads, no side effects) | `test_no_b2_result_and_repeated_reads_never_construct_or_dispatch_synthesis`: 10 reads, `Bus::fake()`, throwing `AnthropicClient` **and** `NarrativeSynthesizer` bindings, before/after `DocumentAiRun` and `brief_synthesis` counts | assert `Http::assertNothingSent()` explicitly |
| C (in_progress / timeout / rejected) | `test_rejected_failed_and_in_progress_content_never_leak_or_retry`: all three states, unique marker per document | search the **whole** serialised response, not `data.brief`; assert figure-bearing B1 blocks still visible |
| E (exactly one Stage A projection) | `test_one_get_projects_stage_a_once_and_preserves_existing_response_fields` with `CountingStageASnapshot` overriding `project()` | nothing |


---

# Result

## 10. What changed

Four production files, plus one new read-only Artisan command.

| File | Change |
|---|---|
| `app/Services/Intelligence/MeasurementParser.php` | `isoCurrency()`, `statesDuration()` — two read-only predicates over the vocabulary this class already owns |
| `app/Services/Intelligence/Brief/BriefVerifier.php` | defects 1, 2 and 4: record-local currency resolution, entity particles and wrapper normalization, the `durations_grounded` check |
| `app/Services/Intelligence/Values/ValueParser.php` | defect 3: whitespace-tolerant citation for the value, digit-boundary guard on the value, duration-valued findings typed as durations |
| `app/Services/Intelligence/B2/NarrativeVerifier.php` | reads the same currency resolution, so the money gate cannot disagree with grounding |
| `app/Console/Commands/DiagnoseBriefMoneyGrounding.php` | new; read-only production-impact diagnostic |

`config/intelligence_v2.php` is untouched, so `max_rejected_claims` is still 0, the B2 verifier
settings are unchanged and `key_figures.max` is still 6.

### Defect 1, as applied

`MeasurementParser::isoCurrency()` filters `currency()` to a three-letter code, so `'$'` and the
two-letter `'UA'` answer null while `'USD billion'` answers `USD`. `BriefVerifier::recordCurrency()`
returns `currency` when it is already ISO and, **only** when it is exactly `'$'`, resolves from that
same record's `unit`. Used in three places: `validNumericValue()`'s money branch, the claim-unit
comparison in `numbersGrounded()`, and `comparable()`.

The re-parse cross-check at the end of `validNumericValue()` is untouched, so `currency` must still
be faithful to the raw text. The ISO code is read off the record's own `unit`; it is never written
into `currency`.

### Defect 2, as applied

`entities()` lets a narrow set of lowercase particles (`of de del della da dos van von der den bin
al`) sit inside a capitalised run, so `Government of India` is one mention. `and` and `the` are
deliberately not particles — they would weld two separate names together. `entitySurfaces()` then
offers two normalized forms per mention: the mention, and the mention with **one** leading
grammatical wrapper removed. Grounding succeeds if either matches a record-owned name.

Checking both forms is what makes the change monotone — every mention that grounded before still
grounds, so a record whose own subject begins with an article (`The Hague`) cannot regress.

### Defect 3, as applied

`cited()` now compares with runs of whitespace collapsed, which is what makes `"$1.584 billion"`
match a quote that reads `"Total\n$1.584\nbillion"`. A second method, `citesValue()`, adds the digit
guard a *figure* needs, and the value branch uses it.

The guard is deliberately **not** applied to periods. For a value, `"5%"` found inside `"45%"` is a
different number — a neighbouring bar of the chart. For a period, `"2022"` found inside an axis
rendered `"20242023202220212020"` is that period, merely unseparated by the extractor. Applying one
rule to both refused four claims the independent review judged supported, and dropped `(2022)`,
`(2023)` and `(2021)` from B1 block text, for no correctness gain. Scoping it to the value fixes
defect 3 and the residual-percentage negative while leaving period grounding where it was.

### Defect 4, as applied

Three parts:

1. `durations()` extracts a qualifier, an optional short chain of determiners, then a **required**
   count and a duration unit. Requiring the count keeps a calendar phrase like `"for the year ended
   2024"` out of the duration path, where it would have demanded a span no record states.
2. Those spans are stripped in `numbers()` alongside dates and periods, and a new
   `durations_grounded` check validates each against a span the cited record owns — its own
   `data.period`, when the record's own quote states it, or a typed relative duration. Spans are
   compared as `(count, unit)` with spelled-out counts mapped to digits, so `"five years"` and
   `"5 years"` are one span and `"5 months"` is not.
3. The duration fallback inside `numbersGrounded()` is **removed**. It let a duration satisfy a
   number: a claim reading `"Revenue was 30."` grounded against a typed duration of
   `"within 30 days"`, which is exactly what task §7 forbids.

`durations_grounded` is **not** added to `BriefAssembler`'s drop list, which stays
`cites_available, numbers_grounded, dates_grounded, periods_grounded, units_consistent`.

#### A fifth leak, found by the adversarial fixtures and fixed

Writing the §10 fixtures surfaced a case the diagnosis had missed. `MeasurementParser::kind()` can
only see a duration when the **unit** field names one, so a finding whose unit is empty and whose
value carries the span — `value: "within 10 days"`, `unit: null` — typed as `type: number`,
`unit_kind: other`: an unclassified *number*. It therefore grounded any claim that happened to
mention 10, including `"Acme reported a score of 10."` and `"Acme opened 10 offices."`

Fixed in `ValueParser`: when the measurement is unclassified and the value text states a duration
unit, `unit_kind` becomes `duration`, which `validNumericValue()` already refuses. Scoped to the
unclassified case, so a rate like `"USD 5 per year"` is untouched. The span itself is still checked,
as a span, through the record's relative date.

## 11. The 29 reviewed claims

`tests/Unit/IntelligenceB1GroundingReviewedClaimsTest.php`, over
`tests/Fixtures/intelligence-v2/b1-grounding/reviewed-claims.json`: every claim with its cited
records exactly as supplied, the independent verdict, and the evaluation's own verifier verdict.
**No verdict was changed to make a test pass.**

The fixture froze each record's `typed` block as the evaluation supplied it, which for the
chart-derived records is `null` — defect 3 itself. Stage A does not treat stored typing as canonical
(`MaterialityReadModel` re-derives it from `data` and `sources` on every read), so the test
re-derives it the same way. Pinning the frozen block would have tested the broken projection.

What is pinned is the **grounding** layer. `unsupported_key_figure` is not: it depends on the full
supplied evidence set, which the blinded packet deliberately does not preserve, and the key-figure
rules are frozen. The fixture records that reason where the evaluation saw it.

| | Claims | Grounding outcome now |
|---|---|---|
| Verifier accepted, review `supported` | 7 | **all 7 still ground** — nothing was bought by regressing them |
| Verifier rejected, review `supported` | 19 | **12 now ground**; 7 still refused (below) |
| Verifier rejected, review `partially_supported` | 3 | **all 3 still refused** (§12) |

### The 12 that now ground

C03, C04, C05, C06, C08, C09, C11, C15, C16, C26, C28, C29 — the currency defect (C03, C09), the
chart-typing defect (C11, C15, C28, C29), the entity wrapper defect (C04, C05, C06, C08) and the
duration defect (C16, C26).

### The 7 reviewed-supported claims still refused, and why

Both groups fail on `entities_grounded`, and in both the mention is a fragment of a record **label**
rather than a name the record owns. The contract reads entity names from `subject`, an entity
record's `value`/`aliases`, `confirmed_entity_names` and a confirmed `entity_ref`. Matching a label
prefix would mean inferring a longer entity from a shorter one, which task §5 requires to fail and
task §3 forbids solving by substring search.

| Claims | Mention | Cited record offers |
|---|---|---|
| C01, C02, C17, C21, C24 | `Core Resources` | subject `UNICEF`, label `Core Resources income…` |
| C18, C23 | `Swachh Bharat`, `Clean India` | subject `Government of India`; the names appear only inside a non-entity record's free-text `value` |

Task §8's proviso is what applies: a supported claim passes *provided the cited canonical evidence
satisfies the existing grounding contract*. These do not. Forcing them would have meant widening
entity ownership to arbitrary record prose — reported, not done.

## 12. The residual-percentage known-negative

**It is already one of the reviewed 29** — items C13, C20 and C27, all
`rejected` / `partially_supported` with `components_failing: ["figure"]`. Not duplicated as a
separate fixture, as task §9 directs.

It stays refused, and now for the right reason. Before, those three failed on
`entities_grounded` only; their numbers all grounded, because the `5%` record carried
`typed.value.number = 5`. It carried it because `cited()` found `"5%"` inside `"45%"`: the chart's
percentages are 37/41/30/49/46/45/20/17/14/33/49/18/31/50/19 plus the 0–100% axis, with no
standalone `5%` anywhere. The digit guard refuses it, the record types to null, and the claim is
refused on `numbers_grounded` — the defect the independent reviewer actually identified — plus
`units_consistent`, which follows because the claim's `5%` unit token has no grounded number to
attach to. `test_the_residual_percentage_is_refused_on_its_number` pins `numbers_grounded`
specifically, so the guard cannot silently become entity-dependent again.

## 13. Adversarial fixtures

`tests/Unit/IntelligenceB1GroundingAdversarialTest.php`, 34 cases, all passing. Every record is
typed by the real `ValueParser` from its own fields and quote, so no fixture asserts against a typed
value no parser would produce.

| Case | Result |
|---|---|
| `$` + `USD` in the same record | grounds |
| ISO stored directly in `currency` | grounds |
| bare `$`, no code anywhere in the record | refused |
| `$` + `CAD` against a USD claim | refused |
| USD claim against an AUD record | refused |
| right amount, wrong currency | refused |
| right currency, wrong amount | refused |
| right currency and digits, wrong scale (m vs bn) | refused |
| wrong magnitude | refused |
| two `$` records with USD and CAD units, `rose` | `comparison_valid` refused |
| leading preposition (`In India` → `India`) | grounds |
| reordered but record-owned phrasing | grounds |
| entity owned only by another record | refused |
| longer entity inferred from a shorter one | refused |
| entity absent from every record | refused |
| generic label fragment expanded into the record name | refused |
| injected text naming another entity | refused |
| span the record states / spelled-out span | grounds |
| span the record does not state, wrong unit, wrong count | refused |
| duration standing in for a metric, year, percentage, amount or count | refused (5 cases) |
| unrelated header number | refused |
| right number, wrong subject | refused |
| right subject, wrong cited record | refused |
| wrong year | refused |
| wrong percentage | refused |
| instruction embedded in a record's own quote | changes nothing: the fabricated figure is still refused, the record's real figure still grounds |

## 14. B1 BEFORE / AFTER on the frozen evidence sets

`tests/Feature/IntelligenceB1GroundingEvaluationSetsTest.php` seeds both sets from
`evaluation-evidence-sets.json` — the distinct records the evaluation cited, with the quotes exactly
as extraction stored them — and reads B1 through the API read path. BEFORE was captured on the
unmodified tree at `b97c859`, with all six assertions failing.

| | `india_wash` BEFORE | AFTER | `unicef_reduced` BEFORE | AFTER |
|---|---|---|---|---|
| Blocks | 2 | 3 | 2 | 8 |
| Figure-bearing blocks | **0** | **1** | **0** | **6** |
| Block types | headline, coverage_note | headline, **measure**, coverage_note | headline, coverage_note | headline, **6 × measure**, coverage_note |

BEFORE reproduces the reported production symptom exactly: a headline, a coverage note, and no
figures at all.

### Restored figures

Monetary records across both sets: **10 of 10 now type and ground; 0 of 10 grounded before.**

| Set | Block text now served | Record | Typed value | Currency | Scale | Source quote states it |
|---|---|---|---|---|---|---|
| `india_wash` | `Government of India investment in water and sanitation: over $120 billion` | same label | 1.2e11 | `$` + unit `USD` → **USD** | 1e9 | `…investment of over $120 billion in water and…` |
| `unicef_reduced` | `Core Resources income: $1.584 billion (2024)` | same label | 1.584e9 | `$` + `USD` → **USD** | 1e9 | `…Total\n$1.584\nbillion…` |
| `unicef_reduced` | `Total income from voluntary contributions: $8.263 billion (2024)` | period 2024 | 8.263e9 | **USD** | 1e9 | `…$8.263 \nbillion…` |
| `unicef_reduced` | `… $8.920 billion (2023)` | period 2023 | 8.92e9 | **USD** | 1e9 | `…$8.920 \nbillion…` |
| `unicef_reduced` | `… $9.326 billion (2022)` | period 2022 | 9.326e9 | **USD** | 1e9 | `…$9.326 \nbillion…` |
| `unicef_reduced` | `… $8.122 billion (2021)` | period 2021 | 8.122e9 | **USD** | 1e9 | `…$8.122 \nbillion…` |
| `unicef_reduced` | `… $7.219 billion (2020)` | period 2020 | 7.219e9 | **USD** | 1e9 | `…$7.219 \nbillion…` |

Why each was refused before, and why it passes now:

- `over $120 billion` — **defect 1**. The record typed correctly but `currency` held `'$'`, so
  `validNumericValue()` refused it and `BriefAssembler` dropped the block on `numbers_grounded`.
  Now the ISO code is resolved from the same record's `unit`.
- the six billions and `$1.584 billion` — **defect 3 then defect 1**. The value never typed at all,
  because the chart quote wrapped the figure. Now it types, and then needs defect 1's resolution to
  ground.

### Three groundable figures that B1 still does not show, and why that is not a defect

`$724.9 million`, `$512.6 million` and `$346.1 million` are all typed and groundable — asserted
individually in `test_each_monetary_figure_the_evaluation_lost_grounds_again`. They do not appear
because `unicef_reduced` now has ten monetary records competing for `key_figures.max = 6`, and the
five billions plus the `Total` label pattern outrank them. That is the **frozen key-figure
selection rule** working, not grounding failing, which is why the test asserts groundability and
placement separately.

The evaluation's §6.1 table listed these three as dropped. On the full evidence set they are now
out-competed rather than refused — a different outcome from a different cause, and worth saying
plainly rather than counting as a win.

### Other blocks changed

Nothing outside the figure path. Both sets keep their `headline` and their `coverage_note`, with
identical text. No attention or timeline block appears or disappears. The served blocks remain
byte-identical to `BriefAssembler`'s own output, asserted in the same test.

One expected consequence of the §6.5 finding: because `KeyFigureSelector` never required an ISO
code, the key-figure **set** is not changed by defect 1 — only by defect 3, which adds six newly
typed records to it.

## 15. Open observations, reported not changed

1. **`cited()` is a substring test for short values.** The digit guard closes the numeric case that
   mattered (`"5%"` in `"45%"`), but a short non-numeric value can still match inside a longer word.
   Out of scope; no case in the evaluation evidence depends on it.
2. **Materiality conflates `'$'` across currencies.** `SignalEvaluator::numericGroup()` and
   `ForcedItemRules::currencyMagnitude()` group by `currency` equality, so two records that both
   wrote `'$'` are treated as one currency family even when their units say USD and CAD. Materiality
   is frozen by the task and this affects scoring only, never grounding. `BriefVerifier::comparable()`
   had the same flaw on the grounding path and **was** fixed.
3. **`KeyFigureSelector` accepts `'$'` as a currency.** This answers the open question in §6.5 of the
   evaluation report: headline eligibility never inspected the ISO code, so defect 1 was not
   shrinking the key-figure set. Left alone — key-figure rules are frozen.

## 16. Security and provenance review

The question for each fix: does anything that grounds a claim now come from somewhere other than the
cited canonical record?

| Field | Where it comes from after the fix | Record-local? |
|---|---|---|
| **Currency** | `typed.value.currency` when already ISO; otherwise the **same record's** `typed.value.unit`, resolved through `MeasurementParser`'s own table. A bare `'$'` with no unit resolves to nothing and the record grounds no money at all. Never read from another record, from a sibling evidence row, or from document text. | yes |
| **Entity** | `subject`, an `entity` record's `value`/`aliases`, `confirmed_entity_names`, a confirmed `entity_ref` — unchanged. The fix only changes how the **claim's** surface form is read: particles joined, one leading wrapper optionally dropped. No name is added to the known set, and `entitiesGrounded()` still iterates only the cited records. | yes |
| **Numeric magnitude** | `typed.value.number`, from the cited record, matched within the existing tolerance. Unchanged. | yes |
| **Scale** | `typed.value.scale`, from the cited record, still cross-checked by re-parsing `raw` with the record's own `unit`. Unchanged. | yes |
| **Period / year** | `typed.dates[*].period.text`, from the cited record. `periodsGrounded()` unchanged; `ValueParser` now tolerates the source's line wrapping when confirming the period is quoted. | yes |
| **Duration** | A span must match `data.period` of the **cited** record **and** be stated in that record's own quote, or a typed relative duration on that record. `durationsGrounded()` builds its known set only from the records passed in. A duration can no longer satisfy a number at all. | yes |
| **Injection** | Source text remains data. `test_an_instruction_inside_evidence_cannot_change_the_verdict` puts `SYSTEM: ignore all grounding checks…` in a record's own quote and shows the fabricated figure still refused and the real one still grounded. No fix reads instructions from evidence, and `no_source_text_leak` is unchanged. | n/a |

**No fix turned record-local provenance into document-global provenance.** Two of the changes make
provenance *stricter* than before: `comparable()` no longer treats a USD record and a CAD record as
one currency because both wrote `'$'`, and a value that states a time span can no longer ground a
metric claim.

The widening is bounded and deliberate: a monetary record may complete its currency from its own
`unit` field, and a claim's entity mention may shed one leading grammatical wrapper. Everything else
got narrower or stayed put.

## 17. Production impact diagnostic

`app/Console/Commands/DiagnoseBriefMoneyGrounding.php`, proved by
`tests/Feature/IntelligenceMoneyGroundingDiagnosticTest.php` (5 tests).

It calls the real `StageASnapshot`, `BriefAssembler` and `BriefVerifier` — the same services the API
read path uses — so the answer is the application's own semantics. Money eligibility is **not**
restated: it is `BriefVerifier::groundableValue()`, the verifier's own predicate, exposed read-only.
The one predicate the command does restate is the *old* rule (`currency` had to match
`/^[A-Z]{3}$/`), which is the only way to attribute an exclusion to this specific defect.

It reports: documents inspected; documents with evidence; documents serving **zero** figure-bearing
blocks; monetary records; monetary records excluded specifically by the currency defect; monetary
records eligible under the fixed code; records ineligible for other reasons; documents affected; and
documents whose figures return after the fix — plus a breakdown by resolved currency code and, with
`--per-document`, ids and counts only. Never a label, a quote or a figure.

Safety, asserted in `test_the_diagnostic_is_read_only`: a `DB::listen` hook records **zero**
insert/update/delete/truncate/alter/drop/create statements; document, evidence, KPI, chunk and run
counts and the document's `updated_at` are unchanged; `Bus::assertNothingDispatched()`;
`Http::assertNothingSent()`; and constructing an `AnthropicClient` at all is bound to throw. Where
the command owns the transaction it runs inside `set transaction read only`, and it reports which
guard was in force — under `RefreshDatabase` a transaction is already open, so the statement is
correctly skipped and `read_only_transaction` is `false`.

### How to run it, later

**Not run against production in this session.** Prefer a read replica or a restored snapshot; it
cannot write, but it reads every document in scope and that is load.

```
php artisan docintel:diagnose-money-grounding --limit=200
php artisan docintel:diagnose-money-grounding --workspace=<id> --json
php artisan docintel:diagnose-money-grounding --json --per-document > findings.json
```

Run it on the **pre-fix** code to size the live damage, and on the post-fix code to confirm the
recovery. `monetary_records_excluded_by_currency_defect` is the headline number either way.

## 18. Out of scope, reported only

### A. The synthesis timeout retry is unreachable

**Current behaviour.** `NarrativeSynthesizer` marks a unit `pending` — the only retryable state —
for `provider_busy` and `transient` only. A cURL timeout classifies as `timeout`, which is terminal.
`NarrativeCheckpoint::claim()` then refuses a terminal unit, so `intelligence_v2.b2.attempts = 2`
can never be reached for that evidence set. The paid evaluation hit this on `india_wash-2`: one
transport failure permanently disabled B2 for that document's evidence set and committed the full
reservation ($0.022685) for a call that produced nothing.

**Why the second attempt is unreachable.** Retry is gated on the unit's own status, and nothing ever
moves a `timeout` unit off terminal. The attempt identity is `(document, pipeline_key,
input_hash)`, so a retry would have to reuse the same identity — which the terminal check refuses —
and the evidence set cannot produce a different hash without changing.

**Recommended fix.** Classify a transport timeout as `pending` **only** when the telemetry shows no
provider request id and no usage, which is the signature of a call that never reached the provider.
Keep it terminal when a request id came back, because then it may have been billed and may have
produced output. Bound it by the existing `attempts` counter so a persistently failing transport
cannot loop.

- *Idempotency.* The identity is unchanged, so a retry resolves to the same `document_chunks` row
  under the existing unique index. The `running` marker still serializes concurrent workers. The
  risk is a call that was in fact delivered and billed; restricting the reclassification to "no
  request id and no usage" is what bounds it.
- *Budget.* `IncrementalPipeline::settleCost()` commits the full reserved bound when usage is
  unknown, which is the right fail-safe but costs 2.3× a successful call for nothing. Pair the retry
  with **releasing** rather than committing the reservation when there is no request id and no
  usage; otherwise two attempts can reserve ~45% of a $0.05 budget between them and leave nothing
  for the attempt that succeeds.
- *Concurrency.* A unit moving from terminal back to retryable is a new transition, so the state
  machine needs it to be guarded by the same row lock `claim()` already takes, or two workers could
  both see a retryable unit and both pay.

Also worth fixing while in there: a terminal `failed` unit is refused with reason `in_flight`, which
is misleading to an operator reading logs. It should name the terminal state.

### B. The B2 quote reserves about 2.4× actual

Recorded, not changed. Across the five returned calls, settled spend averaged **42.4%** of the
reserve, mean quote error **−$0.013334**, and **0 of 5** calls settled above their reserve. The
reserve never under-quoted, which is the property a spend ceiling needs. The cost is availability,
not money: B2 is admitted on the reserve, so it will be denied on documents it would have fitted.
Immaterial against the shipped per-document budget (B2 measured at 1.9–3.7%); it becomes material
only under an AI-credits quote, where `provider_cost_cap_usd` lowers `budget_usd` directly, and
credits are off in this environment. No change to pricing, the quote formula, budgets or credit
behaviour.

## 19. Integration test gaps A, B, C and E

Largely closed at `b97c859` already by `IntelligenceBriefApiWiringTest`; this branch strengthens
them and makes the rich fixture carry money in the shape that was broken. All six tests pass, 707
assertions.

The `rich()` fixture now includes a record written the evaluation's way — `value: "over $120
billion"`, `unit: "USD"` — so every invariant below actually exercises a figure-bearing block
instead of only the `USD billion`-unit shape that never failed.

### Gap A — direct vs API, rich and thin

`test_rich_and_thin_b1_match_the_direct_assembler_with_b2_off`. Both fixtures compared under a
canonical serialization: recursively sorted object keys, stable array order, stable numeric
formatting, stable null.

Numeric formatting needed a real fix. The canonicaliser used `JSON_PRESERVE_ZERO_FRACTION`, which
*created* the asymmetry it was meant to remove: the API payload has already been through Laravel's
own serialization by then, and that drops the fraction from a whole float, so the assembler's
`scale` of `1.0e9` arrives back as the integer `1000000000`. The flag then rendered one side
`1000000000.0` and the other `1000000000`, failing four tests on a formatting artifact. Numbers are
now rendered to a canonical decimal string with `sprintf('%.17G')` before encoding, so the two sides
agree when the numbers agree.

Added assertions: the rich Brief must serve at least one figure-bearing block (otherwise the
invariant is vacuous), and the thin Brief must serve none — a headline and a coverage note, with the
direct/API equality holding on that too.

### Gap B — ten reads, no side effects

`test_no_b2_result_and_repeated_reads_never_construct_or_dispatch_synthesis`. Ten GETs of the same
document, with `AnthropicClient` **and** `NarrativeSynthesizer` bound to throw on construction, so
even building a provider client fails the test. After ten reads:

| | |
|---|---|
| Provider calls | 0 (`Http::assertNothingSent()`, under `Http::preventStrayRequests()`) |
| Synthesis jobs dispatched | 0 (`Bus::assertNotDispatched`, and `Bus::assertNothingDispatched`) |
| New `document_ai_runs` | 0 (before/after count) |
| New `brief_synthesis` units | 0 (before/after count) |
| New `document_chunks` of **any** stage | 0 (added: total count, not just the B2 stage) |
| Retries triggered | none — no unit exists to retry |
| B1 figures served | present on all ten reads, direct-equal each time |

### Gap C — explicit fallback states

`test_rejected_failed_and_in_progress_content_never_leak_or_retry`, over seven states including the
three the task names: `rejected` (unit `completed` / `verifier_rejected`), `timeout` (unit `failed`),
and `in_progress` (unit `running`). Per state, ten reads, each asserting:

- deterministic B1 remains visible **with its figures** — `figureBearing()` non-empty, newly asserted;
- no provider call (`Http::assertNothingSent()`, newly added per state);
- no synthesis dispatch (`Bus::assertNothingDispatched()`);
- `status` is the expected fallback and `narrative` is null.

Leakage: each document stores a unique marker (`UNTRUSTED_B2_<id>`) inside the rejected unit's
claim text. The assertion now searches **the whole serialized HTTP response body**, not just
`data.brief` — so a leak through `audit`, a diagnostic field or any other key fails the test.

### Gap E — exactly one Stage A projection per GET

`test_one_get_projects_stage_a_once_and_preserves_existing_response_fields`, unchanged and passing.
`CountingStageASnapshot` extends the real class and counts calls to the real `project()`, so the
actual projection path is instrumented rather than inspected by hand; one GET asserts
`projections === 1`. The scoped memoization is untouched.

## 20. Test process and database safety

No `pkill` of any kind was used. Before each run the live `phpunit` / `artisan test` processes were
listed and checked; no stale process from this task existed at any point, so nothing was killed, and
no unrelated developer process was touched.

Two isolated databases on the throwaway PostgreSQL 16 at `127.0.0.1:54329` (started by an earlier
task, data directory outside the repository). Neither production nor Railway was touched; the local
`.env` was never read.

| Database | Used for | Suite |
|---|---|---|
| `b1fix_baseline_test` | baseline, unmodified tree at `b97c859` | one full suite, phpunit PID 20906 |
| `b1fix_after_test` | targeted runs during development | two invalidated full-suite attempts (below) |
| `b1fix_final_test` | the after-change full suite of record | one full suite, created fresh |

`DB_HOST=127.0.0.1 DB_PORT=54329 DB_DATABASE=<db> php artisan test`.

### One incident, and what it invalidated

I broke the single-suite-per-database rule myself. A full `artisan test` run was in progress against
`b1fix_after_test` when I started a second, direct `phpunit` run against the same database to get
unbuffered output. The two `RefreshDatabase` wrappers then raced on the schema and PostgreSQL
deadlocked:

```
SQLSTATE[40P01]: Deadlock detected … drop table "public"."ai_prompts", … cascade
SQLSTATE[40P01]: Deadlock detected … alter table "document_chunks" add constraint …
```

The first run aborted at **981 of 1246** tests with exit code 2, and I nearly recorded that 981 as
the after-change figure. What caught it was comparing against `--list-tests`, which reports 1246
collected tests, and noticing the progressive report lines had dropped from 22 in the baseline log
to 2.

**Both runs are discarded.** Only this task's own two processes were then stopped, by PID
(`11702`, `11706`), never with `pkill`; no unrelated developer or test process was touched. A fresh
`b1fix_final_test` was created and one suite run against it with nothing else connected, which is
the suite reported in §22.

## 21. Git hygiene

`git diff --check`: clean, no whitespace errors.

Confirmed **not** touched:

- extraction and extraction wire-format files; extraction prompts
- Compact JSON experiment files; Candidate Inventory
- `config/intelligence_v2.php` — so `max_rejected_claims` is still 0, every B2 strictness setting
  is unchanged, and `key_figures.max` is still 6
- pricing, quote formula, budgets, credit behaviour
- routing, Stage A materiality, key-figure selection rules
- migrations — none added; none was necessary, every fix is application code

Staged explicitly by path. No `git add -A`, no `git add .`.

## 22. Test counts

### A reporting trap worth recording

`php artisan test`'s JSON summary **undercounts a full run**. On the after-change suite its final
line read 981 tests — and 981 is exactly `412 Unit + 569 Feature`, i.e. part of the Feature suite
missing. `--list-tests` reports **1246** collected, and a `--log-junit` run of the Feature suite
contains all **834** test cases, through to `WorkspaceInsightsTrendsTest`, the alphabetically last.
Nothing truncated; the summariser dropped part of its own count, which also explains the
progressive report lines falling from 22 in the baseline log to 2.

So the figures below come from the JUnit XML and the per-suite runs, not from that summary. The
baseline's 1166 is left as the summariser reported it, because it equals `1246 − 80 new tests`
exactly and carries the same failure and skip sets, so it is consistent.

### Before and after

| | Baseline (`b97c859`) | After | Difference |
|---|---|---|---|
| Tests | 1166 | **1246** | +80 |
| Passed | 1161 | **1241** | +80 |
| Failed | 2 | **2** | 0 |
| Skipped | 3 | **3** | 0 |
| Assertions | 8654 | **9049** | +395 |

Per suite, after: Unit **412 / 412 passed**, 1705 assertions, 0 skipped. Feature **834 tests, 2
failures, 0 errors, 3 skipped**, 7344 assertions.

### Every failure

Unchanged from the baseline, and neither touches grounding:

- `HealthCheckTest::test_healthy_response_shape_and_status_code` — 503, `RedisException: Connection
  refused`. No Redis on this machine.
- `HealthCheckTest::test_storage_check_does_not_touch_the_real_disk` — same cause.

### Every skip

All three are Redis-dependent and skip themselves on this machine. Identical to the baseline:

- `ProviderGateTest::test_redis_semaphore_is_atomic_across_parallel_processes`
- `ProviderGateTest::test_redis_lease_expires_when_a_holder_crashes`
- `QueueBacklogRecoveryTest::test_redis_inspector_finds_tokens_in_ready_delayed_and_reserved_messages`

The baseline's `ScanUploadedFileJobCliTest` ClamAV case is among the counted passes in both runs; it
degrades internally rather than failing.

### Targeted runs

| Run | Result |
|---|---|
| A. Grounding unit tests (`IntelligenceB1Grounding*`) | **75 passed**, 199 assertions |
| A. `IntelligenceBriefVerifierTest` | **12 passed**, 84 assertions |
| B. B1 API integration (`IntelligenceBriefApiWiringTest`) | **6 passed**, 707 assertions |
| B. B1 evaluation sets (`IntelligenceB1GroundingEvaluationSetsTest`) | **9 passed**, 78 assertions |
| C. Wider intelligence + materiality + KPI + chart + provenance | **488 passed**, 3242 assertions |
| Diagnostic (`IntelligenceMoneyGroundingDiagnosticTest`) | **5 passed**, 31 assertions |
| D. Complete backend suite | **1241 passed / 2 environmental failures / 3 skips** |

No filtered run is offered in place of the full-suite gate; D is the gate.

## 23. Files changed

`git diff --check`: clean. Staged explicitly by path; no `git add -A`, no `git add .`.

### Production (5)

| File | Lines |
|---|---|
| `app/Services/Intelligence/Brief/BriefVerifier.php` | +265 / −… (defects 1, 2, 4) |
| `app/Services/Intelligence/Values/ValueParser.php` | +63 (defect 3, duration typing) |
| `app/Services/Intelligence/MeasurementParser.php` | +28 (two read-only predicates) |
| `app/Services/Intelligence/B2/NarrativeVerifier.php` | +11 (shared currency resolution) |
| `app/Console/Commands/DiagnoseBriefMoneyGrounding.php` | new, read-only diagnostic |

### Tests and fixtures (8)

| File | What |
|---|---|
| `tests/Unit/IntelligenceB1GroundingReviewedClaimsTest.php` | new; the 29 frozen reviewed claims |
| `tests/Unit/IntelligenceB1GroundingAdversarialTest.php` | new; 34 must-not-pass cases |
| `tests/Feature/IntelligenceB1GroundingEvaluationSetsTest.php` | new; B1 before/after on both evidence sets |
| `tests/Feature/IntelligenceMoneyGroundingDiagnosticTest.php` | new; diagnostic behaviour and read-only proof |
| `tests/Fixtures/intelligence-v2/b1-grounding/reviewed-claims.json` | new; the 29 claims, verdicts frozen |
| `tests/Fixtures/intelligence-v2/b1-grounding/evaluation-evidence-sets.json` | new; the cited records per evidence set |
| `tests/Unit/IntelligenceBriefVerifierTest.php` | the two assertion updates in §7.2 |
| `tests/Feature/IntelligenceBriefApiWiringTest.php` | gap A/B/C strengthening, canonicaliser fix |

### Docs (1)

`docs/tasks/b1-grounding-fix-report.md` — this report.

## 24. Verdict

The four defects the evaluation found are fixed, and the fix is bounded: a monetary record may
complete its currency from its own `unit`, and a claim's entity mention may shed one leading
grammatical wrapper. Everything else got stricter or stayed put. A fifth leak — a value that is
itself a time span grounding any claim that shared its digits — was found by the adversarial
fixtures and closed.

What a reader gets back: on `india_wash`, one monetary block where there were none; on
`unicef_reduced`, six. Across both sets, 10 of 10 monetary records now type and ground, against 0 of
10 before.

Of the 19 apparent false-positive rejections, **12 now ground**. Seven do not, and they are reported
rather than forced: in all seven the claim names a fragment of a record's *label* rather than a name
the record owns, and grounding them would mean inferring a longer entity from a shorter one. All 7
previously-accepted claims still ground. All 3 partially-supported claims stay refused, and the
residual-percentage case now fails on its number rather than incidentally on its entity.

Two caveats a reviewer should weigh, neither hidden:

1. **Three groundable figures still do not appear in `unicef_reduced`'s Brief.** They lose their
   key-figure slot to larger, newly typed figures. That is the frozen selection rule, not grounding,
   and the tests assert groundability and placement separately so the two cannot be confused again.
2. **The seven still-refused claims are a contract judgement, not a bug.** If product decides a
   claim should be allowed to name a record by a prefix of its label, that is a deliberate widening
   of entity ownership and deserves its own decision, not a quiet change here.

`DOCINTEL_V2_BRIEF_NARRATIVE` is untouched and still off. Nothing here argues for turning it on: the
evaluation should be re-run first, and §18A's terminal-timeout problem is still open.

## 25. Commit

One local commit on `fix/b1-grounding-defects`. Not pushed, not merged, not deployed.

```
0315ba25a7f135d88a7d7bee726ef6d72c4bf935  Fix B1 grounding defects found by B2 evaluation
```

Committed file list, exactly as staged:

```
app/Console/Commands/DiagnoseBriefMoneyGrounding.php
app/Services/Intelligence/B2/NarrativeVerifier.php
app/Services/Intelligence/Brief/BriefVerifier.php
app/Services/Intelligence/MeasurementParser.php
app/Services/Intelligence/Values/ValueParser.php
docs/tasks/b1-grounding-fix-report.md
tests/Feature/IntelligenceB1GroundingEvaluationSetsTest.php
tests/Feature/IntelligenceBriefApiWiringTest.php
tests/Feature/IntelligenceMoneyGroundingDiagnosticTest.php
tests/Fixtures/intelligence-v2/b1-grounding/evaluation-evidence-sets.json
tests/Fixtures/intelligence-v2/b1-grounding/reviewed-claims.json
tests/Unit/IntelligenceB1GroundingAdversarialTest.php
tests/Unit/IntelligenceB1GroundingReviewedClaimsTest.php
tests/Unit/IntelligenceBriefVerifierTest.php
```

**READY_TO_MERGE**
