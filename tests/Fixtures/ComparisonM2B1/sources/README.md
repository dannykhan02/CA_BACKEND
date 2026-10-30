# Financial comparison source fixtures

These unmodified public Apple PDFs are required by `ComparisonM2B1RealContentTest`.
Keep both in version control so clean CI checkouts can run the test offline.
Their hashes match the previously captured `../baseline-real.json` records.

| File | Original publisher URL | SHA-256 |
|---|---|---|
| `apple-fy26-q2.pdf` | [FY26 Q2 statements](https://www.apple.com/newsroom/pdfs/fy2026q2/FY26_Q2_Consolidated_Financial_Statements.pdf) | `28fa56187d6fd8fe8b0a67185f62b51fd0bb3932d8413a93f29c7455fc06b4dd` |
| `apple-fy24-q2.pdf` | [FY24 Q2 statements](https://www.apple.com/newsroom/pdfs/fy2024-q2/FY24_Q2_Consolidated_Financial_Statements.pdf) | `19fe6ef8359aa55b7f0609dbbc772f1d4a572b045cd2510413cfcb7384cbd21e` |

Copyright remains with Apple. These files are retained as bounded regression
evidence. The test replays deterministic extraction and proof with locally
constructed publication pointers; it makes no model calls and does not establish
completed normal-analysis or extraction-quality acceptance.
