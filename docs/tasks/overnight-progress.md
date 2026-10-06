# Overnight progress (resume from here)

| WS | Status | Reason / notes | Commit |
|---|---|---|---|
| 0 Plan + Phase 0/1 analysis | done | `docs/tasks/overnight-plan.md`. Dev DB down and production off-limits, so the measurements are modeled; WS3 adds the report command | (see git log) |
| 1 Approved-model guard | done | `approved_models` config, runtime warning, verify-models fails on an unapproved model, ModelRoutingGuardTest (3 tests); 106 AI tests pass | (this commit) |
| 2 Test stray-request guard | done | `Http::preventStrayRequests()` in the base TestCase plus StrayHttpRequestGuardTest; full suite 652/652 with the guard on, so no existing test reached the network | see git log |
| 3 `docintel:ai-usage-report` | done | Read-only (READ ONLY transaction), metadata-only Phase 0 report; DocumentAiUsageReportTest 2/2 | see git log |
| 4 Per-document split budget | done | `max_split_parents_per_root` = 2, checked under the document lock; CostGuardrailsTest 3/3; 114 split-related tests pass | see git log |
| Finish: validation + report | pending | | |
