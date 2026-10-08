# CR-015: Narrow numeric-equivalence provenance grounding

| | |
|---|---|
| **Author** | Intelligence V2 implementation |
| **Date** | 2026-10-08 |
| **Status** | approved by user |
| **Approver** | User, 2026-10-08 |
| **Supersedes** | none; narrows the notation exception to CR-001 |

## Contract change

Affected section: §2.4, origin directness. CR-001's same-quote period and due-date gates remain in force. The prior rule required a non-empty normalized value substring in the cited quote. The approved exception also accepts a metric whose stored value is pure quantitative notation and whose single cited-quote quantity parses to the same magnitude, sign, percentage semantics, currency, scale, unit kind and qualifier. `6%` and `6 %`, `99%` and `99 per cent`, comma grouping, and parser-supported written scale abbreviations can qualify. A currency or scale available only in neighboring context cannot qualify. Generated explanatory wording, multiple quote quantities, mismatched denominators, unsupported currency codes, and unsupported periods do not qualify. A substring match with an explicit contradictory percentage, scale, currency or qualifier is not direct evidence.

## Reason and alternatives

The read-only UNICEF provenance diagnostic found five numeric-notation false negatives among 103 unknown-origin metrics. Keeping CR-001's exact substring rule would leave these visibly equivalent citations unknown. Broad text similarity, neighboring-header inheritance and extraction value-shape cleanup were rejected because they can assert facts not established by the cited quote. The real-document outcome requires a later approved Railway read-only measurement; no count is hard-coded.

## Safety and implementation impact

The projector remains a read-only projection of accepted stored evidence. It reuses `MeasurementParser` and `EvidenceMerger` and changes no stored rows, extraction prompt/schema, parser behavior, scorer, chart builder, Brief generator, provider call, queue job, migration, cache, feature flag or Power BI component. The `period` and `due_date` checks are unchanged. Existing flag-off output and fixtures 25/26 remain unchanged. Normal-route legacy adapter directness is unchanged. Rollback is reverting the projector exception; no data cleanup is needed.

Focused tests cover accepted notation and mismatched percent, currency, scale, qualifier, label meaning, context-only support and period. The diagnostic command test assertion is updated because its synthetic `$1.9M` citation is now document-origin; it still guards against writes and provider calls. No protected test is modified.
