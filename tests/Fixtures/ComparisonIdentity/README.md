# M2A identity evaluation

Tier 1: `cases.json` has 30 separately labelled synthetic scenarios. `build-fixtures.py` reconstructs these inputs; it never edits the original 59-case M0 suite. Source excerpts state the varied input values and terms. Identifier tokens in these synthetic inputs are not asserted real registry entries.

```sh
python3 tests/Fixtures/ComparisonIdentity/build-fixtures.py
php tests/Fixtures/ComparisonIdentity/evaluate.php
python3 bin/test-matters.py --filter 'ComparisonIdentity|ComparisonRealContent'
```

`run.php` invokes the real candidate generator, identity adapter and comparison engine. `results.json` separates classifications, identity states, applied false matches, false substantive rows, missed supported changes and abstentions. Database mutation, authorization and concurrency cases live in ComparisonIdentityReviewTest/ComparisonIdentityConcurrencyTest, outside fact-accuracy denominators.

Tier 2: `real-document-manifest.json` names real files, full hashes, independently read source conclusions and missing pairs. ComparisonRealContentTest replays actual Apple/Nasdaq PDF bytes through supported deterministic extraction, stored evidence and field verification. Its locally constructed published pointer does not make this Tier 3. `real-content-extraction.json` stores actual parser-derived observations and source locations/passage hashes; `real-content-results.json` records counts and the pair result. No model extraction rows are invented. The source files remain the inspection reference.

Five planned pair types and Tier 3 are blocked. Existing mocked provider pipeline tests are not fresh real extraction acceptance. No production/customer data or paid AI inference is used. See CA/docs/audits/matters-m2a-evaluation.md for interpretation and limits.
