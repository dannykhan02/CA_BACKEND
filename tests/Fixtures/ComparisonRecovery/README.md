# M2B.1 acceptance recovery evidence

Real-positive verdict: **blocked**, not an extraction success fixture.

`sources/` retains the two original Texas Comptroller PDFs. `independent-source-review.py` checks their bytes through Poppler and writes independent annotations to `independent-source-review.json`. These annotations are never read by the application or its extraction replay.

`ComparisonAcceptanceRecoveryTest` feeds the actual PDFs through DocumentTextExtractor, StructuredExtractionStore, EvidenceMerger (without fabricated provider checkpoints), both available deterministic PDF collectors, provenance, field verification, publication scaffolding and the production comparison job/API. It records `actual-pipeline.json`. The current absence of structured obligations is deliberately characterized; a future supported extractor will require updating this diagnostic and the acceptance verdict. Zero output is not semantic equivalence.

Published pointers and completed extraction-stage records are constructed locally to inspect the highest unpaid Tier 2 path. They do not prove Tier 3 upload/provider/publication execution. The source PDFs, rather than authored facts, are the replay inputs.

`current-frozen-regression.json` is a separate Tier 1 replay of the preserved 55 fact cases and 30 identity cases. The synthetic guard tests exercise the existing comparator conditional on supplied facts and reviewed identity. They do not produce real contract facts or prove real section alignment.

Run from the backend with the disposable PostgreSQL database required by `bin/test-matters.py`:

```sh
python3 tests/Fixtures/ComparisonRecovery/independent-source-review.py
python3 bin/test-matters.py --filter ComparisonAcceptanceRecovery
```

The audit is `CA/docs/audits/matters-m2b1-acceptance-recovery.md`. Original M2B.1 reports and frozen M0/M2A artifacts remain historical records.
