# DocIntel governing-owner proof closure

22 October 2026. Local code and offline replay only. No Anthropic request, production database, new PDF parser, prompt activation, push, merge, or deployment was used.

## Root cause and contract

V10 validation permits `identity.scope: null` and requires a `subject_span_id` key but does not establish what entity governs the value. `EvidenceResolver` skipped blank scope; `NormalKpiGrounder` skipped the subject role when scope was blank; persistence then recorded value, unit and period as verified. `MeasurementIntegrity` required a subject only for a nonempty incremental claim. `RowExpander` inherited the blank scope, `ChartCandidateBuilder` accepted the resulting `analyticalEligible` flag, and the composite API serialized charts and source excerpts without an owner relationship. In the newer Northstar response this admitted 9/16 model observations with resolution off and 14/21 including five server expansions with resolution on. All 14 eligible flagged observations had null scope and no persisted owner role.

The contract now separates the measured **population** (`identity.scope` and the existing subject citation) from a **governing organization** (`governing_owner`). A reporting organization in a document heading is document context; it can support an explicit company claim only through a short, unbroken source path to the cited table and with no competing organization. An immediately preceding company-specific table caption governs its table and takes precedence over the page banner. A subsidiary or business unit named in that caption is the local owner, not its parent banner. A company named in the value row is local owner evidence. A named organization without a supported table or row relationship, an ambiguous caption, a competing entity, and a bare document heading with null scope are not owner proof. A non-company metric population can be verified through its own nonempty scope and subject source without requiring a company scope. Blank scope never becomes a guessed organization in KPI identity.

## Changes

- `GoverningOwnerProof` resolves exact company names from a value row, specific table caption, or bounded explicitly cited section-to-table path. It checks the original extraction hash, competing names, specific captions and contradictory citations. A bare company heading cannot fill null scope. The recognition deliberately requires a printed corporate suffix; unsupported names abstain.
- `NormalKpiGrounder` now requires owner proof for company-attributed concepts or company-scoped claims, and a verified population subject for other normal-route claims. It stores the owner entity, relationship type, source span IDs, pages, character offsets, text hash and span-segmenter extraction version in metric evidence; `governing_owner` is a distinct source role. No owner proof means no metric evidence row.
- `MeasurementIntegrity` rechecks persisted owner references and revision before normal-route analytical eligibility, including older normal-route evidence without the new proof field. Legacy and incremental routes retain their existing policy. `MetricCollector` and `KpiResource` carry this decision consistently; KPI cards expose owner verification and governing owner. The composite evidence map retains exact source excerpts and their `governing_owner` roles.
- Chart and comparison grouping includes the proven owner, preventing two companies with otherwise identical metrics and values from being combined.

Evidence resolution still defaults off. The new owner gate runs in the ordinary grounder in both flag modes.

## Adversarial regressions

`GoverningOwnerProofTest` covers null scope on a financial KPI; a correct explicit company; a parent banner over a subsidiary caption; two companies on one page with identical values; a heading contradicted by the table caption; a valid delivery population; sibling expansion that re-verifies the same owner; stale text and segmenter revisions; ambiguous ownership; and tampered persisted offsets. Existing DOCX, PDF, Northstar, and normal-route tests were aligned with the stricter source contract. Tests that previously accepted a generic `Business Metric` heading or an ownerless workbook revenue row now require real owner evidence or expect refusal. Person-scoped payroll rows remain population metrics.

Affected regression set: **45 passed, 750 assertions**. The complete safe backend suite passed: **1,856 tests, 1,841 passed, 15 skipped, 13,300 assertions**, with no failures or errors. The opt-in paid test remained skipped. The final suite log is restricted at `storage/framework/testing/governing-owner-safe-suite-complete.log`.

## Separate offline replays

Both saved V10 responses were replayed without provider calls. Each mode persisted a separate local evaluation document. The earlier 20-observation response and newer 16-observation response are separate denominators. “Owner proof” counts persisted metric evidence with a verified governing-owner relation, including rows that lack another field needed for analytics. “Fully verified” requires eligibility and owner proof for company claims or a verified population role for population claims. Independent gold is the 29-case Northstar transcription; it has no negative-claim set.

| Captured response and mode | Observations | Eligible before → after | Owner proof before → after | Fully verified after | Financial series / charts / comparisons before → after |
| --- | ---: | ---: | ---: | ---: | ---: |
| Earlier 20, resolution off | 20 | 0 → 0 | 0 → 0 | 0 | 0 / 0 / 0 → 0 / 0 / 0 |
| Earlier 20, resolution on | 24 (20 + 4 expanded) | 14 → 14 | 0 → 18 | 14 | 4 / 4 / 8 → 4 / 4 / 8 |
| Newer 16, resolution off | 16 | 9 → 0 | 0 → 0 | 0 | 3 / 3 / 5 → 0 / 0 / 0 |
| Newer 16, resolution on | 21 (16 + 5 expanded) | 14 → 0 | 0 → 0 | 0 | 4 / 4 / 8 → 0 / 0 / 0 |

The older response already claimed Northstar and cited its page heading. The bounded relationship check verifies 14 eligible facts after resolution; its 18 owner-proved rows include four observations limited by other fields. Its 14 eligible observations match 14 of the 29 independent gold facts on value row, page, label, unit, period, subject and basis. Composite API evidence mappings after the change are 18 in its flagged mode. The newer response left every eligible claim's scope blank. Its financial table has a generic caption, so the Northstar page banner alone does not establish that each value belongs to Northstar. All 14 formerly eligible flagged observations are now refused, including the five expansions; its API evidence mappings, financial series, charts and comparisons are zero. The 16 original model decisions remain identical between its flag modes. These losses are the intended fail-closed result, not a reason to relax the proof gate.

For the newer response, the independent review still finds 20/21 row/value/period matches and 16/21 cited source cells in flagged mode, with no demonstrated wrong printed number. It finds **0 fully matched label/unit/period/owner/basis claims** because owner was absent from the model identity. Thus the 14 previous acceptances were unsupported company-scoped acceptances, not 14 demonstrated wrong numerical values. After the change there are zero such acceptances. Against the available strict positive gold, neither response has a newly rejected fully matched claim; the older flagged response keeps its 14 strict matches, and the newer response has none. A false-acceptance rate or broad false-rejection rate cannot be estimated without independently reviewed negative claims and held-out documents. Six newer gold-matched numerical candidates were already refused for other missing field proof; the additional 14 refusals are numerically source-correct but semantically owner-incomplete.

Final restricted artifacts: `storage/framework/testing/docintel-local-evaluation/run-20261022-165434-06cb3951df/` (earlier response) and `run-20261022-165445-a1e22b14a5/` (newer response). Both report `paid_request_count: 0` and keep raw provider exchanges private. Baseline figures come from the retained controlled live result and latest complete local report, not from combining their populations.

## Limits

This resolver abstains on legal names without a recognizable suffix, PDF tables whose caption or row cannot be tied to the cited cell, irregular layouts, document-level ownership without a local relationship, and cross-page or nested group relationships. Native cell and span proof is reused where present; no new PDF cell parser or entity graph was introduced. The Northstar gold is a fictional aligned PDF, not a real-document accuracy estimate. Evidence resolution remains disabled by default pending broader reviewed, multi-entity and multi-format gold.
