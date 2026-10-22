import copy
import unittest

from tools.evidence_first_gate import (compact_runs, numeric_gold_match, render_markdown,
                                       score_report, semantic_review)


class EvidenceFirstGateScoringTest(unittest.TestCase):
    def setUp(self):
        self.case = {"source_sha256": "source", "extraction_sha256": "text",
                     "ground_truth_sha256": "gold", "source_span_revision": "revision",
                     "source_span_count": 1}
        self.truth = {"source_gold_fact_count": 1, "expected_financial_series": {},
                      "analytical_eligibility_expectations": {"eligible_gold_ids": []}}
        self.candidate = {"source_id": "kpi:a", "candidate": {"label": "Revenue", "value": "51.0",
                           "unit": "KES", "identity": {"period": "Q3 2026", "scope": "ACME",
                                                       "value_basis": "actual"}},
                          "rejection": "missing_source_reference", "evidence": None,
                          "api_kpi": {"analyticalEligible": False}}
        self.report = {"outcome": "success", "requests": 1, "source_sha256": "source",
                       "extracted_text_sha256": "text", "source_revision": "revision",
                       "span_count": 1, "gold_sha256": "gold",
                       "candidates": [self.candidate], "model_observation_count": 1,
                       "server_expanded_observation_count": 0,
                       "source_linked_observation_count": 0,
                       "structurally_proven_observation_count": 0,
                       "independently_eligible_count": 0,
                       "source_gold_score": {"gold_coverage": [
                           {"gold_id": "fact", "candidate_indexes": [0]}]},
                       "api_evidence": {}, "strict_complete_financial_series_count": 0,
                       "trusted_chart_count": 0, "deterministic_comparison_count": 0,
                       "rejection_reasons": {"missing_source_reference": 1},
                       "latency_ms": 1, "pipeline_latency_ms": 2}

    def test_only_independently_matched_candidates_have_a_correctness_denominator(self):
        self.report["candidates"].append({**self.candidate, "source_id": "kpi:b"})
        self.report["model_observation_count"] = 2
        scored = score_report(self.report, self.case, self.truth)
        self.assertEqual({"count": 1, "denominator": 2, "unknown": 1},
                         scored["numerically_correct_exact_gold"])
        self.assertEqual({"count": 1, "denominator": 1},
                         scored["correct_claims_rejected_for_grounding"])
        self.assertEqual(0, scored["incorrect_claims_accepted"]["denominator"])

    def test_analytical_eligibility_without_evidence_fails_the_gate(self):
        self.report["candidates"][0]["api_kpi"]["analyticalEligible"] = True
        self.report["independently_eligible_count"] = 1
        with self.assertRaisesRegex(ValueError, "lacks persisted metric evidence"):
            score_report(self.report, self.case, self.truth)

    def test_source_revision_mismatch_fails_the_gate(self):
        self.report["extracted_text_sha256"] = "changed"
        with self.assertRaisesRegex(ValueError, "extracted_text_sha256"):
            score_report(self.report, self.case, self.truth)

    def test_repeated_output_signatures_detect_variation(self):
        first = score_report(self.report, self.case, self.truth)
        second = copy.deepcopy(first)
        second["signature"]["comparisons"] = 1
        self.assertTrue(compact_runs([first, second])["nondeterministic"])

    def test_numerical_match_does_not_promote_incomplete_period_or_scope(self):
        candidate = copy.deepcopy(self.candidate)
        candidate["source_context"] = {"value_span_id": {"page": 3,
                                  "text": "Revenue\t42.0\t51.0"}}
        candidate["candidate"]["identity"]["period"] = "Q3"
        candidate["candidate"]["identity"]["scope"] = "Group revenue"
        fact = {"id": "revenue-q3", "page": 3, "row": "Revenue\t42.0\t51.0",
                "value": "51.0", "period": "Q3 2026", "subject": "ACME",
                "value_basis": "actual", "unit": "KES"}
        self.assertEqual(fact, numeric_gold_match(candidate, [fact]))
        review = semantic_review(candidate, fact)
        self.assertTrue(review["period_incomplete"])
        self.assertFalse(review["period_conflict"])
        self.assertTrue(review["scope_differs_from_gold_subject"])

    def test_human_report_keeps_denominators_and_unknown_verdict(self):
        scored = score_report(self.report, self.case, self.truth)
        compact = compact_runs([scored, scored])
        report = render_markdown({"source_case": "fixture", "source_classification": "synthetic",
                                  "real_authorized_document_count": 0,
                                  "feature_off": compact, "feature_on": compact,
                                  "synthetic_adversarial": {
                                      "incorrect_claims_accepted": {"count": 0, "denominator": 2},
                                      "correct_claims_rejected_by_grounder": {"count": 0, "denominator": 2}},
                                  "generalization_verdict": "not_assessable"})
        self.assertIn("Source linked | 0/1 | 0/1", report)
        self.assertIn("0/2", report)
        self.assertIn("not_assessable", report)

    def test_operator_and_sign_must_match_printed_gold(self):
        candidate = copy.deepcopy(self.candidate)
        candidate["source_context"] = {"value_span_id": {"page": 1, "text": "Complaints | ≤ 90"}}
        candidate["candidate"]["value"] = "90"
        fact = {"id": "ceiling", "page": 1, "row": "Complaints | ≤ 90",
                "value": "90", "period": "Q3 2026", "comparison_operator": "≤"}
        self.assertIsNone(numeric_gold_match(candidate, [fact]))
        candidate["candidate"]["value"] = "≤ 90"
        self.assertEqual(fact, numeric_gold_match(candidate, [fact]))
        candidate["candidate"]["value"] = "-KES 90"
        self.assertIsNone(numeric_gold_match(candidate, [fact]))


if __name__ == "__main__":
    unittest.main()
