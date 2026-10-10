# B2 key-figure gate investigation and implementation

Date: 2026-10-11. Zero provider calls. The saved six-call evidence is the replay source.

## Gate trace before implementation

`NarrativeVerifier::claim()` emitted `unsupported_key_figure` if a money claim cited none of `StageASnapshot::key_figure_ids`. `NarrativeSynthesizer` passed those IDs into verification. `KeyFigureSelector` ranks canonical monetary metrics, deduplicates them, then caps B1 display at six. The check applied only to monetary prose, not counts, physical quantities, percentages, or ratios. It required just **one** selected cited ID, even for a claim with several amounts.

The initial B2 infrastructure introduced the gate as a conservative headline eligibility rule. Its report lists the rejection reason and uses it as an additional reason to reject an injected fabricated monetary claim. That fabricated amount independently fails `brief_numbers_grounded`. The gate was not a provenance validator: B2 already checks cited/supplied IDs and document origin, then calls shared `BriefVerifier` as `docintel_ai` / `stated` for typed number, currency, scale, period, entity, comparison, origin, attribution, and citation checks. But the first removal attempt exposed a separate weakness: the shared verifier could match a number to one cited typed value while failing to bind a following semantic unit to that same value. `Revenue reached 12.4 billion people` could pass against a USD 12.4 billion revenue record. The original money trigger also missed that exact wording because it contained no currency marker. I stopped that candidate and recorded `KEY_FIGURE_GATE_CHANGE_FAILED` before the added safety requirements authorized the local binding work below.

## Implementation and scope

`NumericClaimGrounder` now runs inside B2 claim verification after the existing checks. It reads **only cited canonical records**. For each numeric mention it requires one record whose typed value independently matches the magnitude/scale, currency when stated, explicit semantic unit, composite denominator when represented, qualifier, relevant local period, entity, and metric wording. Distinct labels such as private/public partner income are checked in each mention's local text; a shared label can carry across a multi-value sentence. No unit, entity, period, or number may be assembled from separate records. No arithmetic path was added. Existing `BriefVerifier` checks still run and a failure of either layer rejects the claim. The only removed predicate is B1 selected-key-figure membership; `KeyFigureSelector` and its top-six display behavior are unchanged.

The unit-omission rule is conservative: a bare quantity can pass when the claim names the cited record's metric and competing cited metrics are disambiguated by local wording. `Revenue reached 12.4 billion` passes against a lone USD 12.4 billion revenue record. `12.4 billion were reached` and `Revenue reached 12.4 billion people` reject. The explicit allowlist covers person/people, beneficiaries, customers, employees, households, cases, toilets, litres/liters, tonnes/tons, days, years, percent/%, and percentage points. The money aliases tested are USD, US dollars, and `$` when the record itself wrote `$` and its own unit resolves USD. Duration singular/plural normalization remains in the shared duration verifier. No open-ended synonym expansion was added.

The current canonical schema/typing limits are explicit:

- USD per person, cost per unit, and people per household retain their denominator in `typed.value.unit` and can be checked as composite units. A total cannot substitute for the rate, or vice versa.
- `tonnes per year` currently types as a duration because `year` in the unit selects the duration kind; B2 rejects it as a numeric rate. This task did not alter extraction or Stage A typing.
- `basis points` currently type as a count rather than a rate; B2 rejects such a numeric claim rather than treating it as percent or percentage points.
- A range such as `10–12 million` does not become a single valid typed numeric value; B2 rejects attempts to ground an exact endpoint from it.
- Percent, percentage-point, and ratio values are distinguished when the canonical unit retains the distinction. `40%` and `46%` do not license an unrecorded `6%` or `6 percentage points` derivation.
- More than, less than, at least, up to, and approximate wording are compared against the cited record's own raw value. Strict and inclusive bounds are distinct. The broader language of arbitrary semantic nouns is not inferred from the typed schema; the explicit adversarial noun classes above are covered.

This change is **B2 only**: `BriefVerifier`, `ValueParser`, Stage A, and B1 deterministic output code were not changed. B1 verifier version remains `2`. B2 verifier version changes **2 → 3** because admission semantics changed; it is part of the B2 input hash, so old stored results are not reused under the new verifier. B2 serialized contract version stays `1`; prompt version stays `1`; extraction version and model/settings stay unchanged. `max_rejected_claims` stays `0`.

## Zero-cost replay

The fresh replay reconstructs all 36 claims from the saved review packet, unblinded claim/citation mapping, and seed key-figure IDs. It does not construct or call a provider. The prior paired fixture and entity-ownership fixture were replayed too.

| Measure | Before | After |
| --- | ---: | ---: |
| Fresh supported claims admitted | 25 | 28 |
| Fresh partial claims admitted | 0 | 0 |
| Fresh unsupported claims admitted | 0 | 0 |
| Fresh whole narratives verified | 1/6 | 1/6 |
| Prior entity-ownership whole narratives verified | 2/6 | 2/6 |

F28, F35, and F44 all recover. F03 and F19 remain rejected by `brief_entities_grounded`. The six fresh calls still have eight rejected claims: F03/F19 and the six independently partial claims. The earlier paired C21/C24 partner amounts recover; its partial residual claims remain rejected. Whole-narrative counts do not rise because other claims in the affected narratives still fail, with `max_rejected_claims=0`.

## Adversarial results

The deterministic matrix exercises multiple figures in one claim, swapped revenue/beneficiary and revenue/expense values, same magnitude across money/people/litres/tonnes/percent, a number borrowed from an uncited record, ambiguous omitted units, wrong currency, unit, scale, year, entity, program relation, sign, direction, qualifier, unsupported residual and percentage relation, percent versus percentage points, composites, malformed/unsupplied citations, and unknown-origin evidence. All named negatives remained rejected; valid aliases and record-local composites passed where the current typing supports them. The existing F19-equivalent and cross-record ownership tests also run with an **empty B1 key-figure list**, proving the rejection does not depend on ranking. The B1 duration tests cover day/days and year/years aliases.

The focused verifier/replay/B1 evaluation/API invariant/fallback/idempotency/read-safety run passed **142/142 tests, 806 assertions** against an isolated local PostgreSQL 18 database. Later focused matrix additions passed **23/23 tests, 227 assertions**. The one full backend suite passed **1,263 tests, 1 skipped, 9,264 assertions**. Pint passed on changed PHP files. `git diff --check` and `git diff --cached --check` passed. An initial feature-test attempt could not connect to the default local port; the tests passed after using the isolated local cluster on port 55435. No paid provider call, deployment, Railway change, production flag change, push, or merge occurred. Pre-existing staged paid-evidence files were left intact.

## Recommendation

Keep the narrow B2 guard and B2 verifier version 3. The stored sample establishes recovery without observed unsafe admission in the specified zero-cost replay and deterministic adversarial matrix. Treat unsupported composite/range/basis-point forms as explicit limitations until a separately scoped Stage A typing change can represent them.

KEY_FIGURE_GATE_FIX_PASSED
