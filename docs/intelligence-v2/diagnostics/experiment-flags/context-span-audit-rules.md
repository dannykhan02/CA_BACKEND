# Frozen offline context-span audit rules

These rules were written before running the classification script. The input population is the 12 saved paid-study response bodies (raw model records, including validator-rejected records), matched to each call's exact saved request payload. The audit makes no provider calls. Repeated records across runs are counted as record instances; they are not independent source facts.

## Period accuracy

1. Parse `content[0].text.records` from each saved response. Audit every record with a non-empty model-supplied `period`. Keep the original raw record and cited IDs; do not substitute the post-validation period.
2. `CORRECT_AND_SUPPORTED`: the cited value span contains a single applicable explicit period, or the cited spans together contain the period and value with clear structural ownership. Exact wording and faithful equivalents (`10 years`/`last 10 years`, `decade`/`decade-long`) count. A mere annual-report title does not establish a period for a particular observation.
3. `CORRECT_BUT_UNSUPPORTED_BY_CITATION`: the period is absent from cited IDs, but a unique, structurally owned explicit period is present in another source span. That span need not be safely addable as a citation; context safety is assessed separately. The period must apply to the record's claim, not just occur nearby.
4. `WRONG`: an explicit period applying to the claimed observation conflicts with the model period. Record both and the likely source of misattribution. A section/report year alone cannot prove a claim-specific year wrong.
5. `AMBIGUOUS`: two or more plausible periods apply, or the source text lacks reliable row/column ownership. In particular, E103 is OCR-flattened multi-year chart data; its individual years and numbers cannot be paired deterministically from this saved payload, even when a year string appears in the same span. A record whose period is the **entire caption range 2020–2024** and whose value explicitly describes the multi-year series is supported as to its period range; this does not validate its individual value-year pairings.
6. `NO_EXPLICIT_PERIOD_CONTEXT`: no explicit period can be assigned to the observation from the cited text or a structurally owned nearby span. This includes model-added `2024` inferred only from the annual-report year. It is unsupported; the audit does not assert that the real-world event occurred in a different year.

The `WRONG` rate denominator is only `CORRECT_AND_SUPPORTED + CORRECT_BUT_UNSUPPORTED_BY_CITATION + WRONG`; ambiguous and no-context records are not evaluable for correctness. Unsupported-but-correct rate uses the same evaluable denominator and is also shown over all period-bearing records.

## Context citation classification

The configured cap is 3 evidence IDs and the ordinal locality limit is 12 spans (`config/document_intelligence.php`). The rule also requires the **same page**. Candidate IDs are matched against the exact `messages[0].content` `evidence_spans` list in that call's saved request. A span outside it is recorded as outside chunk and can never be an actionable prompt-only opportunity.

The script records every candidate examined: candidate ID, explicit period expression, page, ordinal distance, payload membership, citation count after adding it, and boundary/ownership decision. Candidate classes use this order:

1. `NO_CONTEXT_FOUND`: no explicit candidate for the model field was identified.
2. `AMBIGUOUS_CONTEXT`: competing periods/units, an OCR-flattened multi-year header without column structure, or uncertain ownership.
3. `OUTSIDE_LOCALITY`: a candidate exists but is outside the request, on another page, or more than 12 ordinal spans from the cited value span(s).
4. `STRUCTURALLY_UNSAFE`: a different table/block, intervening heading/caption/page footer, different country or programme, misleading numeric values, or another deterministic ownership conflict.
5. `SAFE_CONTEXT_CANDIDATE`: in the same request, same page, within 12 spans, no boundary/conflict, exactly one appropriate period, and adding the citation stays within the 3-ID cap. A case already citing the period span has no *additional* citation opportunity.

If the otherwise safe addition would exceed the cap, record `CAP_EXCEEDED`; it is excluded from prompt-only opportunity. With 2 existing IDs, adding one exactly reaches the cap. With 1, adding one remains below it. No replacement or alternative representation is assumed.

To avoid treating nearby periods as automatically applicable, the allowed ownership relationships are limited to: continuation of the same sentence/paragraph across adjacent spans, an explicit table row/header with only one period and no competing column, or an explicit repeated subject/metric in adjacent text. New country/section headings, captions, footers and the E103 chart block are boundaries. If these relationships cannot be established, the candidate is ambiguous or structurally unsafe. A period present in the same cited span is period accuracy evidence, not an extra context-citation opportunity.

## Manual review

After rule-based output is saved, inspect at least 10 cases across available classes using the cited value spans and candidate span text. Record the initial rule label, manual judgment and every disagreement. Do not change generated labels silently.

## Closeout implementation note

The same category definitions and safety gates above were applied to the earlier E033–E186 fixture. Detector coverage was extended to recognize the literal phrase `since 2000`, the E066 flattened multi-year table as the same ambiguity pattern already specified for E103, and explicit candidates up to 50 spans away so `OUTSIDE_LOCALITY` remains visible. This did not change the 12-span acceptance limit, three-ID cap, same-payload requirement, structural-ownership rule, or the later 12-response totals. The initial and final earlier category counts, and one manual disagreement left unchanged, are disclosed in `context-span-grounding-audit.md`.
