# B2 paid evaluation — evidence

Supporting records for `docs/tasks/b2-paid-evaluation-results.md` (2026-10-10). Audit material:
read-only, not inputs to anything.

| File | What it is |
|---|---|
| `calls-merged.json` | the six per-call records, merged across the two passes the guard trip split the run into. `india_wash-1`'s provider response comes from the first pass; the second pass's row for it records the reuse (no call). Includes the re-verification of each teed raw response, which is where the rejected claims' own text comes from. |
| `review-packet.json` | the blinded review packet as the reviewer received it. 29 items, claim text plus the full content of each cited and supplied record, shuffled under a fixed seed. No verdict, no reason code, no document or run identity, citation handles relabelled per item. |
| `review-key-sealed.json` | the item id → (call, claim index) mapping, withheld from the reviewer. |
| `review-judgements.json` | the reviewer's 29 judgements, as written before unblinding. |
| `unblinded.json` | the join of the three above: per claim, the verifier verdict and reasons beside the independent judgement. |
| `diagnosis.json` | for each of the 19 apparent false positives, which number and which entity failed to ground, with the cited records' typed values. Produced by calling `BriefVerifier`'s own extractors; read-only. |

`sha256`, as recorded in section 5 of the report at 2026-10-10T11:18:47Z, **before** unblinding:

```
e5d6aa61573712131e77b0a29a2131191035b4d8d30c12b2a5c80f7108ec577d  review-packet.json
c8e5e5431a753018b68753bb34b84fb0e2211d90916580739471b98b30249ea7  review-key-sealed.json
51ecff871d546921f7aa89debd97e15b366cfe173d4bdd7386d7ced9f7f81869  review-judgements.json
```

The evaluation harness itself is deliberately not in the repository, as the protocol requires. It
was `run-eval.php`, unchanged from the readiness report, `sha256 e62802d5c00c34042c7303be0664a2e8eee716ea6c97a8a1692c7d677b866445`.
