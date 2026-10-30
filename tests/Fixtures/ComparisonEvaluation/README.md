# Offline comparison evaluation

`cases.json` contains 59 synthetic labelled scenarios: 55 fact cases and four integration boundaries. `build-fixtures.py` is the reproducible fixture generator. Original target labels are unchanged by M0.

From CA_BACKEND:

```
php tests/Fixtures/ComparisonEvaluation/evaluate.php baseline
php tests/Fixtures/ComparisonEvaluation/evaluate.php baseline-current
php tests/Fixtures/ComparisonEvaluation/evaluate.php post-m0
php tests/Fixtures/ComparisonEvaluation/evaluate.php post-m2a
python3 bin/test-matters.py --filter 'CrossDocumentSafety|ComparisonTrust|ComparisonRecoveryConcurrency|MatterPublishedRevision'
```

No provider is called. The evaluator reads fixture arrays and invokes the real public `changes()` method. Its baseline projection is the original snapshot contract: KPI value/unit only and no comparison context. The post projection also supplies period and verified or missing context. Production snapshot projection is separately covered by feature tests; these synthetic arrays do not measure extraction quality.

`baseline-engine.php` is the exact service at c53d254 with only its class name changed. It preserves the original engine for repeatable offline measurement after application changes. It is never registered or used by the application. Re-running baseline verifies the existing frozen output without overwriting it. `baseline.json` was recorded before M0 against the preserved cases-v1.json; the baseline command reproduces it exactly. Current cases.json corrects illustrative passages to state their varied values without changing labels, values, units or periods. baseline-current.json and post-m0.json bind the identical corrected fixture SHA-256 and have the same counts as the pre-change measurement.

The four `integration` cases are not passed to a pure diff with fake authorization flags. Their assertions live in ComparisonTrustTest, MatterIntelligenceTest, MatterPublishedRevisionTest and ReportSnapshotTest. State cases have no classification precision denominator. The report in CA/docs/audits/matters-comparison-evaluation.md defines the counts and remaining target mismatches.

M2A preserves every existing input/label and frozen output. `m0-engine.php` archives the M0 service at c99e713 with only its class name changed; `post-m0` checks the frozen report for equality. `post-m2a.json` is a separate current-engine result. The new identity suite and real-document manifest live in `../ComparisonIdentity/`; their scores are not combined with this suite.
