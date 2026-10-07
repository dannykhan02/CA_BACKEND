# CR-011: Presentation-independent takeaway deduplication

| | |
|---|---|
| **Author** | Backend implementation |
| **Date** | 2026-10-08 |
| **Status** | deferred design item; no implementation approval |
| **Supersedes** | none |

## Problem

Takeaway deduplication operates on rendered candidate text. CR-010 showed that a formatter change can alter which candidates meet the unchanged overlap threshold and therefore change the selected set.

## Future decision

Evaluate a canonical, presentation-independent deduplication key and its compatibility effects. Specify normalization, tokenization, precedence, tie handling and migration of conformance goldens before implementation. This item authorizes no changes to the formatter, threshold, quotas or Stage A Part 2 selection logic.
