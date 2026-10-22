# DocIntel: source-backed reporting-entity ownership

## Result

The newer saved V10 response has 16 model observations. Its 12 financial observations now gain a persisted Northstar governing-owner relationship through the source document; `identity.scope` stays null. With evidence resolution off, seven financial observations are eligible. With it on, five independently checked row expansions bring that count to 12. The other nine observations in the flagged replay remain refused. No provider request was made.

This restores only the financial facts that have a complete path from the reporting entity to the cited table cell. It does not reinstate the two operational claims that were among the 14 unsupported acceptances in the original live evaluation.

## Root cause and source relationship

The first owner gate correctly rejected blank company scope. It could prove an organization printed in a named table caption, in the value row, or in a nearby cited heading for an explicit model claim. The newer Northstar response leaves every company `identity.scope` blank, while the financial caption says only “Financial results by quarter (KES million).” Therefore its 20/21 independently matched row/value/period observations produced no eligible claims after that gate.

The structured extraction contains a narrower relationship than a free-floating page banner:

1. `E001` is the page 1 report header naming **NORTHSTAR DISTRIBUTION LTD** and the Q3 2026 board pack.
2. `E002` names that same organization in the cover's “Q3 2026 Board Performance Review” title. This establishes the report's reporting entity.
3. `E025` repeats the exact report header, apart from its page number, on the financial-table page.
4. `E027` is the immediately preceding “2. Financial performance / Financial results by quarter” section and caption; `E028` is its native column header. The PDF table verifier binds the cited value rows `E029`–`E032` to the correct columns.

The new proof records this six-span path for each financial value: cover header, cover title, repeated local report header, financial section/caption, table header and value row. It stores their source IDs, pages and exact offsets, the extraction version, the text revision and relationship `reporting_entity_section_table`. For example, the Q3 revenue proof ends at the `51.0` cell in `E029`. The claimed metric population and the governing organization remain distinct; no model scope is filled in.

The relationship is accepted only when the cover names one recognizable legal organization in a report title, the current page repeats the same report identity, the financial section/caption immediately precedes a verified table header, and no different named organization appears on the cover path or table page. A specific subsidiary caption takes precedence. A contradictory model citation, an ambiguous caption, a stale extraction, or a bare page header cannot supply proof. A continued table needs its own local financial caption and repeated report header; a competing named entity on the preceding page blocks inherited ownership.

This is a reporting-entity rule for a bounded financial section, not a rule that every document value belongs to its cover company. Operational populations still require their own verified subject relationship. A subsidiary or business unit in a specific caption can govern its own table, even inside a parent report.

## Code and tests

- `GoverningOwnerProof` resolves the cover-to-section-to-table relationship after the existing `EvidenceResolver` and native PDF cell check. It also requires named table captions to state an actual table relationship, so a nearby company narrative does not become a table owner.
- `MeasurementIntegrity` recognizes the new persisted relationship while retaining source ID, role, offset, revision and extraction-version checks.
- `GoverningOwnerProofTest` now covers a single-entity report with a generic caption and null model scope; parent/subsidiary precedence; two organizations sharing a page; conflicting citation and adjacent narrative; same values under separate companies; a continued table with and without local support, including a subsidiary on its preceding page; no defensible owner; population metrics; row expansion; and stale or tampered source proof.

The affected offline tests passed: **75/75 tests, 1,085 assertions**. The complete safe backend suite passed: **1,860 tests, 1,845 passed, 15 skipped, 13,316 assertions**. The skips include opt-in live work. Five pre-existing PHP 8.5 `imagedestroy()` deprecations were reported. `git diff --check` passed.

## Offline replay, separate denominators

“Owner proof” counts observations with a persisted governing-owner relationship, including rows that later fail another field gate. “Fully verified” counts eligible observations whose label, unit, period and basis match the independent gold and whose governing owner matches it through source proof. For the newer response this source-backed measure replaces the old strict scorer's model-scope equality test; that old test remains 0 because the model scope is still null.

| Saved response and mode | Observations | Numeric source correctness | Owner proof | Fully verified | Eligible financial | Refused | Financial series / charts / comparisons |
| --- | ---: | --- | ---: | ---: | ---: | ---: | ---: |
| Older 20, resolution off | 20 | 20/20 original values manually source-correct | 0 | 0 | 0 | 20 | 0 / 0 / 0 |
| Older 20, resolution on | 24 (20 + 4) | 20/20 original; 4/4 expansions matched gold | 18 | 14 | 12 | 10 | 4 / 4 / 8 |
| Newer 16, resolution off | 16 | 15/16 in enumerated gold; one source-supported target outside gold | 7 | 7 | 7 | 9 | 3 / 3 / 4 |
| Newer 16, resolution on | 21 (16 + 5) | 20/21 in enumerated gold; one source-supported target outside gold | 12 | 12 | 12 | 9 | 4 / 4 / 7 |

The older response includes two eligible delivery observations with an explicit company claim; its other four owner-proved rows are refused for missing units. The newer response's 12 eligible facts are exactly the twelve Q1–Q3 revenue, operating-cost, operating-profit and operating-margin cells. Its five expanded siblings each reverify the same reporting-entity path with their own value cell. All twelve have null model scope, source-backed owner proof, and a gold-matched label, unit, period and basis.

Before the first owner gate, the newer response accepted 9 original observations with resolution off and 14 including expansions with it on. The completed proof leaves **two previously accepted operational observations refused** in either mode because their metric-population relationship is unverified. Relative to the first gate's zero eligible newer observations, the bounded report proof recovers seven off and twelve on. The older response's eligibility is unchanged.

The newer flagged replay refuses eight claims present in the 29-fact gold: on-time delivery actual Q2/Q3 and target Q3, complaints actual Q2/Q3 and ceiling Q3, shipments actual Q3, and the proposed service-recovery total. It also refuses a source-supported shipments target outside that gold. The first recorded refusal is missing source reference for all nine; deeper limits include unit, period, population or owner evidence. The response still omits nine gold facts. The older flagged replay refuses ten observations, including four proposed funding values outside or incompletely tied to the gold and four unit-limited counts.

Against the available independent checks, **no accepted financial value has a demonstrated wrong source cell or gold field**, and all 12 newer accepted financial facts match the enumerated gold under the new owner proof. The adversarial ownership tests found **zero false acceptances and zero false rejections** in their stated fixtures. No fully proved gold claim was newly refused. Eight newer source-value-correct gold claims remain refused because the complete field or population relationship is not proved; they cannot be counted as false rejections under the analytical contract. The gold has no reviewed negative-claim corpus, so these results do not estimate a general false-acceptance or false-rejection rate.

The final replay artifacts are restricted local files: newer `storage/framework/testing/docintel-local-evaluation/run-20261022-175912-f15d9c6b46/report.json` and older `storage/framework/testing/docintel-local-evaluation/run-20261022-175922-12b94eb8eb/report.json`. Each replay made **zero paid requests**. The independent newer-response review is `storage/framework/testing/docintel-local-evaluation/run-20261022-151940-6fac7c658a/independent-review.json`; the enumerated source gold is `tests/Fixtures/intelligence-v2/northstar-source-gold.json`.

## Limits

The proof is deliberately narrow. It abstains when a legal name lacks a recognizable suffix, a cover/report identity is not structurally extracted, a table section is irregular or ambiguously segmented, a report header is not repeated, a continuation lacks a local section, or other organizations appear on the bounded path. It does not establish ownership for generic operational indicators or financial values in subsidiary/function tables from the report cover alone. The Northstar PDF is a fictional, aligned document; these replays do not measure accuracy on real multi-entity reports, scans or other formats. Evidence resolution remains disabled by default, and no production configuration, database, deployment, push or merge was changed.
