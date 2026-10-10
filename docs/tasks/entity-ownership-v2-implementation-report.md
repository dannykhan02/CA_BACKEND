# Entity Ownership V2 implementation

Date: 2026-10-10. Local implementation and zero-cost replay only. No provider request, extraction change, migration, flag change, deploy, push, or merge.

## 1. Checkout and scope

Worktree: `/home/collins/boys/CA_BACKEND`. Starting branch `main`; starting HEAD and `origin/main` both `eeadcd10ae4c60fdfa28c5a5ed404345c4ce8398`. Starting `git status --short` contained only the pre-existing untracked `docs/tasks/b2-post-grounding-rerun-report.md` and `docs/tasks/b2-post-grounding-rerun-evidence/`, plus the prior task's `docs/tasks/entity-ownership-v2-investigation.md`. A dedicated branch, `fix/entity-ownership-v2`, was created before editing. Those three pre-existing untracked paths were not staged or changed. Implementation commit: **`98cd7f580659957364d1fa5ef09095f9aeb5746b`**.

## 2. Files changed and architecture

The implementation commit contains exactly these nine files:

1. `app/Services/Intelligence/RecordOwnedMentionProjector.php` — new deterministic, ephemeral projector.
2. `app/Services/Intelligence/Brief/BriefVerifier.php` — consumes typed mentions, binds newly owned names to cited record subjects and numeric/action relations.
3. `config/intelligence_v2.php` — B1 and B2 verifier versions `1` → `2`.
4. `tests/Fixtures/intelligence-v2/entity-ownership-replay.json` — frozen compact canonical records and the six stored fresh narrative texts/citations, derived locally from the final evaluation artifacts.
5. `tests/Unit/IntelligenceEntityOwnershipAdversarialTest.php` — positive and negative ownership fixtures.
6. `tests/Unit/IntelligenceEntityOwnershipReplayTest.php` — seven paired full-claim and 37 fresh stored-claim/whole-narrative replays.
7. `tests/Unit/IntelligenceB1GroundingReviewedClaimsTest.php` — current expected B1 grounding on the 29 reviewed paired claims.
8. `tests/Unit/IntelligenceB1GroundingAdversarialTest.php` — current record-local concept expectation.
9. `tests/Unit/IntelligenceNarrativeVerifierTest.php` — B2 version assertion.

This report is the sole subsequent repository file. `NarrativeVerifier` needs no code change: it already passes only cited, supplied Stage A records to `BriefVerifier` and retains the independent key-figure gate. `BriefAssembler`, `KeyFigureSelector`, extraction, `EvidenceSchema`, prompts, Stage A materiality, pricing, thresholds, flags, and database schema were untouched.

`RecordOwnedMentionProjector` returns a transient tuple with `surface`, `canonical`, `role`, `basis_field`, `source_quote_match`, and source record ID. It reads one canonical record at a time; no database read or model call occurs. The only roles are `metric_concept` and `program_or_initiative`. `BriefVerifier` considers those tuples only from the claim's cited records. Existing subject/entity matching remains as before.

## 3. Derivation and provenance binding

**Metric concept.** A document-origin `metric` must have a nonempty subject, typed numeric money observation, a label shaped as a multiword capitalized funding category immediately followed by `income` (optionally `from ...`), and a same-record stored source quote containing that exact category followed by `income`, allowing only whitespace/case normalization. `Core Resources income` and the reviewed partner breakdown qualify. A generic label prefix, single word `Core`, heading `Annual Report`, slogan `Important Results`, or text only in a different record does not. The projector does not turn the concept into an organization or alias.

**Program or initiative.** A document-origin `fact` must have a label shaped as a named `... Mission launch`, a value with an actor `launched the ... Mission`, and the same action/name construction in its own stored source quote. The named base and full Mission surface are emitted. A parenthetical alias is emitted only when the fact value and quote both contain that alias in the same name construction. This covers `Swachh Bharat (Clean India) Mission` and `Jal Jeevan (Water is Life) Mission`. A metric label by itself cannot derive a program. The F19 toilet metric's quote is only “110 million toilets constructed, more”, so it yields no program mention.

**Relation binding.** Newly owned names do not enter a document-wide confirmed-name bag. The verifier checks the tuple against each cited record in context. If the claim names a cited subject, the owner must be that subject; a shorter subject embedded in a longer name is not treated as a second actor. For a metric concept, every numeric claim figure must match a cited metric with the same derived concept **and the same subject**. For the reviewed program-plus-number construction, the numeric record must have the exact `Toilets constructed under [Mission]` label, the same subject as the launch fact, and the claim must state both `toilets` and `constructed`. A launch claim must name the same actor as the launch fact's value. Thus a correct program fact beside an unrelated metric, or a correct concept beside another subject's amount, cannot supply the missing relation. The existing independent number, period, unit, provenance, key-figure, attribution, negative-claim, and citation checks still run.

No path scans document text, retrieves other evidence, uses embeddings/fuzzy matching, promotes arbitrary quote phrases, or invents an alias. The source quote is evidence data, never an instruction. Ambiguous or missing local fields produce no newly owned mention. Presence is broadened narrowly; absence and inferred relations are not created.

## 4. Versions

B1 verifier version: **1 → 2**. B2 verifier version: **1 → 2**. `NarrativeContextBuilder::inputHash()` already includes B2 verifier version, so it naturally produces a new identity for the changed verification semantics. B2 contract version remains `1`, B1 template version remains `1`, and canonical evidence/extraction versions remain unchanged. No payload, stored evidence, or extraction output schema changed.

## 5. Focused positives and adversarial results

Ownership-only tests: **7/7 passed, 41 assertions**. They cover same-record Core Resources, Swachh Bharat and Clean India, Jal Jeevan and Water is Life, with exact roles and surfaces. Negatives cover uncited/other-record names, wrong program swap, wrong organization/country, Government/India and Core/Swachh non-expansion, injected `Acme Mission` text, headings/slogans, missing quote corroboration, F19-equivalent label-only program, same amount with wrong concept, same concept and amount with wrong subject, a shorter subject nested inside `Government of India`, and a correct program adjacent to an unrelated same-number metric. Existing wrong currency, scale, year, residual 5%, duration/span leakage and partial-claim negatives remain in the B1/B2 regression group.

Focused B1/B2/API gate on the final code: **120/120 passed, 1,212 assertions**. This includes rich and thin direct-B1-versus-API equality, money grounding, entity adversarial cases, one Stage A projection per GET, GET with zero provider/dispatch, fallback for rejected/failed/in-progress/timeout states, and no rejected-text leakage. It ran against the single task-owned isolated local PostgreSQL 18 database `docintel_ownership_test` on port 55433; no concurrent full suite used that database. The first attempt to run feature tests failed solely because the default local server on port 5432 was down; it reported connection errors, not application assertion failures. The test cluster was then initialized for this task. No PHP processes were broadly killed.

## 6. Frozen zero-cost replay

The replay uses the existing reviewed paired fixture, final `unblinded-v2.json` canonical records, and stored fresh provider responses copied into the compact test fixture. It calls local `NarrativeVerifier` only. No Anthropic or other provider was constructed or called. The B2 key-figure input retains the frozen six-figure selection: partner amounts are out-ranked and therefore remain subject to `unsupported_key_figure`. `max_rejected_claims=0` is unchanged.

| Cohort | Recovered supported claims | Still rejected supported claims and exact reasons |
|---|---|---|
| Paired seven | **5/7**: C01, C02, C17, C18, C23 | C21, C24: `unsupported_key_figure` |
| Fresh nine | **5/9**: F02, F05, F10, F18, F22 | F19: `brief_entities_grounded`; F28, F37, F44: `unsupported_key_figure` |

Previously accepted supported claims regressed: **0/22 fresh**, **0/7 original paired grounding cases**. Newly accepted partial or unsupported claims: **0**. All **6/6 fresh partial claims** remain rejected; the three paired residual-percentage partial claims C13/C20/C27 remain rejected on the numeric/unit checks. No independently unsupported fresh claim was present to newly admit. Had a partial/unsupported claim been accepted, this task would have stopped with `OWNERSHIP_SAFETY_REGRESSION`; that condition did not occur.

Whole fresh narratives: **0/6 current → 2/6 post-implementation replay**. India 1/3 and India 2/3 verify. India 3/3 remains blocked by F19. UNICEF 1/3 remains blocked by its false 46%-of-total relation and F28 key-figure gate; UNICEF 2/3 and 3/3 also retain false 46%-of-total, unsupported 5%, and partner-figure blockers. No rejected narrative text is served by the read path. F19 and its focused equivalent both remain rejected on entity grounding.

## 7. Final backend suite and Git checks

After adding the cross-subject relation fixture and fix, the **final** full backend suite passed: **1,253 tests; 1,252 passed; 0 failed; 1 skipped; 9,113 assertions**. A provisional full run before that final relation-binding fix had passed 1,253 tests with 9,111 assertions; it was superseded by the final code-state run. The final full suite ran alone against the same isolated database.

Before the implementation commit, explicit-path staging contained only the nine files listed in §2. `git diff --cached --stat`, `git diff --cached --name-only`, and `git diff --cached --check` were reviewed; `git diff --cached --check` was clean. No `git add -A` or `git add .` was used. The report is staged and committed separately so it can name the implementation commit hash. No migration, provider call, paid retest, deploy, push, or merge was performed.

ENTITY_OWNERSHIP_V2_READY_FOR_PAID_RETEST
