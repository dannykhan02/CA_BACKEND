import argparse
import copy
import hashlib
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from tools import evidence_corpus as corpus


class PrivateCorpusIntakeTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.root_patch = patch.object(corpus, "CORPUS", self.root / "corpus")
        self.root_patch.start()
        self.addCleanup(self.root_patch.stop)
        self.source = self.root / "synthetic-control.pdf"
        self.source.write_bytes(b"%PDF synthetic unit control")
        self.text = "ACME | Q3\nRevenue | 51"
        self.snapshot = {"format": "PDF", "parser_version": "unit.synthetic",
                         "text": self.text, "text_sha256": hashlib.sha256(self.text.encode()).hexdigest(),
                         "source_span_revision": "unit-revision", "capabilities": {},
                         "spans": [{"key": "E001", "start_offset": 0, "end_offset": len(self.text),
                                    "page": 1, "type": "table_row", "ordinal": 1, "quote": self.text}],
                         "nodes": [{"id": "page:1", "type": "page", "start": 0,
                                    "end": len(self.text), "text": self.text}]}
        self.extract_patch = patch.object(corpus, "extract", return_value=self.snapshot)
        self.extract_patch.start()
        self.addCleanup(self.extract_patch.stop)

    def intake(self, category="real_human_observations"):
        return corpus.intake(argparse.Namespace(id="synthetic-unit-control", source=str(self.source),
                                              category=category, authorization="unit-test-only",
                                              provenance="generated within this unit test", reviewer="tester"))

    def reference(self):
        start = self.text.index("51")
        return {"role": "value", "span_id": "E001", "start_offset": start,
                "end_offset": start + 2, "quote": "51", "page": 1, "node_id": "page:1"}

    def claim(self):
        return {"id": "claim-1", "metric": "Revenue", "value": "51", "unit": "KES",
                "period": "Q3", "basis": "actual", "entity": "ACME",
                "population": "group", "expected_decision": "accept",
                "analytical_eligible": True, "source_references": [self.reference()]}

    def truth(self, *, source_facts=None, candidate_id=None, candidate_index=None):
        path = corpus.case_dir("synthetic-unit-control") / "truth.json"
        truth = corpus.load(path)
        truth.update({"review_status": "human_verified", "reviewer": "independent tester",
                      "reviewed_at": "2026-10-22", "source_facts": source_facts or [],
                      "claims": [self.claim()]})
        if candidate_id is not None:
            truth["claims"][0]["candidate_id"] = candidate_id
        if candidate_index is not None:
            truth["claims"][0]["candidate_index"] = candidate_index
        path.write_text(json.dumps(truth))
        return truth

    def test_intake_keeps_immutable_original_hash_and_draft(self):
        result = self.intake()
        self.assertEqual("draft", result["status"])
        manifest, snapshot, _ = corpus.validate("synthetic-unit-control", ready=False)
        self.assertEqual(corpus.digest(self.source), manifest["source_sha256"])
        self.assertEqual(self.snapshot, snapshot)
        self.assertEqual(0o600, (corpus.case_dir("synthetic-unit-control") / "original.pdf").stat().st_mode & 0o777)
        with self.assertRaisesRegex(ValueError, "completed human"):
            corpus.validate("synthetic-unit-control")
        (corpus.case_dir("synthetic-unit-control") / "original.pdf").write_bytes(b"changed")
        with self.assertRaisesRegex(ValueError, "Original source"):
            corpus.validate("synthetic-unit-control", ready=False)

    def test_backend_only_category_never_counts_as_model_extraction(self):
        self.intake()
        directory = corpus.case_dir("synthetic-unit-control")
        candidate = self.root / "candidate.json"
        candidate.write_text(json.dumps({"origin": "human_prepared_observations", "claims": [{
            "id": "prepared-1", "label": "Revenue", "value": "51", "unit": "KES",
            "period": "Q3", "concept": "Revenue", "scope": "ACME", "metric_type": "amount",
            "basis": "actual", "source_context": {"value_span_id": "E001"}}]}))
        corpus.attach("synthetic-unit-control", str(candidate), response=False,
                      provenance="human candidate authored for unit test")
        self.truth(candidate_id="prepared-1")
        corpus.review("synthetic-unit-control")
        manifest, _, truth = corpus.validate("synthetic-unit-control")
        row = {"candidate_index": 0, "human_candidate_id": "prepared-1",
               "origin": "human_prepared", "label": "Revenue", "value": "51", "unit": "KES",
               "period": "Q3", "identity": {"value_basis": "actual", "scope": "ACME"},
               "source_linked": True, "exact_cell_proven": True,
               "analytical_eligible": True, "rejection": None}
        report = {"case_id": manifest["id"], "category": manifest["category"],
                  "source_sha256": manifest["source_sha256"],
                  "extraction_sha256": manifest["extraction_sha256"],
                  "source_span_revision": manifest["source_span_revision"],
                  "paid_inference_calls": 0, "model_observations": 0,
                  "human_prepared_observations": 1, "server_expanded_observations": 0,
                  "feature_enabled": False, "candidates": [row], "chart_count": 0,
                  "comparison_count": 0, "api_evidence_count": 1, "latency_ms": 2}
        scored = corpus.score_real_run(report, manifest, truth)
        self.assertEqual(0, scored["observations"]["recorded_model"])
        self.assertEqual(1, scored["observations"]["human_prepared"])
        self.assertEqual({"count": 0, "denominator": 0}, scored["false_acceptance"])
        report["model_observations"] = 1
        with self.assertRaisesRegex(ValueError, "miscounted"):
            corpus.score_real_run(report, manifest, truth)
        truth["claims"][0]["source_references"][0]["quote"] = "52"
        (directory / "truth.json").write_text(json.dumps(truth))
        with self.assertRaisesRegex(ValueError, "truth hash"):
            corpus.validate("synthetic-unit-control")

    def test_draft_truth_rejects_wrong_offset_quote_and_unexplained_missing_period(self):
        self.intake()
        self.truth(candidate_id="prepared-1")
        path = corpus.case_dir("synthetic-unit-control") / "truth.json"
        truth = corpus.load(path)
        truth["claims"][0]["source_references"][0]["quote"] = "52"
        path.write_text(json.dumps(truth))
        with self.assertRaisesRegex(ValueError, "quote/offset"):
            corpus.validate("synthetic-unit-control", ready=False)
        truth["claims"][0]["source_references"][0]["quote"] = "51"
        truth["claims"][0]["period"] = None
        path.write_text(json.dumps(truth))
        with self.assertRaisesRegex(ValueError, "Missing period"):
            corpus.validate("synthetic-unit-control", ready=False)
        truth["claims"][0]["period_status"] = "missing"
        path.write_text(json.dumps(truth))
        corpus.validate("synthetic-unit-control", ready=False)

    def test_recorded_model_requires_independent_source_gold_and_frozen_envelope(self):
        self.intake("real_recorded_model")
        response = self.root / "response.json"
        response.write_text(json.dumps({"content": [{"type": "text", "text": "{}"}]}))
        corpus.attach("synthetic-unit-control", str(response), response=True,
                      provenance="synthetic frozen envelope for unit test", prompt_version=10)
        claim = self.claim()
        fact = {key: claim[key] for key in ("metric", "value", "unit", "period", "basis",
                                            "entity", "population", "analytical_eligible",
                                            "source_references")}
        fact["id"] = "fact-1"
        self.truth(source_facts=[fact], candidate_index=0)
        path = corpus.case_dir("synthetic-unit-control") / "truth.json"
        truth = corpus.load(path)
        truth["claims"][0]["source_fact_id"] = "fact-1"
        path.write_text(json.dumps(truth))
        corpus.review("synthetic-unit-control")
        manifest, _, truth = corpus.validate("synthetic-unit-control")
        self.assertEqual(1, len(truth["source_facts"]))
        self.assertEqual("real_recorded_model", manifest["category"])
        with self.assertRaisesRegex(ValueError, "immutable"):
            corpus.attach("synthetic-unit-control", str(response), response=True,
                          provenance="synthetic frozen envelope for unit test", prompt_version=10)

    def test_recorded_model_can_report_zero_observations_against_source_gold(self):
        self.intake("real_recorded_model")
        response = self.root / "response.json"
        response.write_text(json.dumps({"content": [{"type": "text", "text": "{}"}]}))
        corpus.attach("synthetic-unit-control", str(response), response=True,
                      provenance="synthetic zero-observation unit control", prompt_version=10)
        path = corpus.case_dir("synthetic-unit-control") / "truth.json"
        truth = corpus.load(path)
        fact = {key: self.claim()[key] for key in ("metric", "value", "unit", "period", "basis",
                                                  "entity", "population", "analytical_eligible",
                                                  "source_references")}
        fact["id"] = "fact-1"
        truth.update({"review_status": "human_verified", "reviewer": "independent tester",
                      "reviewed_at": "2026-10-22", "source_facts": [fact], "claims": []})
        path.write_text(json.dumps(truth))
        corpus.review("synthetic-unit-control")
        manifest, _, sealed = corpus.validate("synthetic-unit-control")
        report = {"case_id": manifest["id"], "category": manifest["category"],
                  "source_sha256": manifest["source_sha256"],
                  "extraction_sha256": manifest["extraction_sha256"],
                  "source_span_revision": manifest["source_span_revision"],
                  "paid_inference_calls": 0, "model_observations": 0,
                  "human_prepared_observations": 0, "server_expanded_observations": 0,
                  "feature_enabled": False, "candidates": [], "chart_count": 0,
                  "comparison_count": 0, "api_evidence_count": 0, "latency_ms": 2}
        scored = corpus.score_real_run(report, manifest, sealed)
        self.assertEqual({"count": 0, "denominator": 1}, scored["numerically_matched_source_facts"])


if __name__ == "__main__":
    unittest.main()
