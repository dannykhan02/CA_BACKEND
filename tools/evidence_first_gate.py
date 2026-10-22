#!/usr/bin/env python3
"""Offline, guarded evidence-first replay and ground-truth scoring.

The default replay is the frozen synthetic Northstar case. --real-case delegates
to the private, independently reviewed corpus adapter; neither path makes paid calls.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import subprocess
import sys
import time
from decimal import Decimal
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
MANIFEST = ROOT / "tests/Fixtures/intelligence-v2/evidence-first-corpus.json"
TRUTH = ROOT / "tests/Fixtures/intelligence-v2/evidence-first-truth-spec.json"
ADVERSARIAL = ROOT / "tests/Fixtures/intelligence-v2/evidence-first-adversarial-claims.json"
DIAGNOSTICS = ROOT / "storage/framework/testing/docintel-v10-evaluation"
OUTPUT = ROOT / "storage/framework/testing/docintel-evidence-first-gate"
REPLAY_TEST = "tests/Feature/NorthstarV10LiveEvaluationTest.php"
ADVERSARIAL_TEST = "tests/Feature/EvidenceFirstAdversarialGateTest.php"


def read_json(path: Path) -> dict:
    value = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(value, dict):
        raise ValueError(f"Expected object: {path}")
    return value


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as source:
        for block in iter(lambda: source.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def required_path(relative: str, allowed_root: Path) -> Path:
    path = (ROOT / relative).resolve()
    if not path.is_relative_to(allowed_root.resolve()) or not path.is_file():
        raise ValueError(f"Missing or disallowed evaluation path: {relative}")
    return path


def validate_spec(manifest: dict, truth: dict) -> tuple[dict, dict]:
    if manifest.get("schema_version") != 1 or truth.get("schema_version") != 1:
        raise ValueError("Unsupported evaluation specification")
    if not isinstance(manifest.get("real_authorized_documents"), list):
        raise ValueError("Real-document inventory must be explicit")
    for document in manifest["real_authorized_documents"]:
        if not isinstance(document, dict) or document.get("review_status") != "human_verified" \
                or not isinstance(document.get("authorization_reference"), str) \
                or not document["authorization_reference"].strip() \
                or not isinstance(document.get("source_path"), str):
            raise ValueError("Real document lacks human review or authorization provenance")
        source = required_path(document["source_path"],
                               ROOT / "storage/framework/testing/docintel-evaluation-corpus")
        if sha256(source) != document.get("source_sha256"):
            raise ValueError("Real document source hash mismatch")
    coverage = manifest.get("coverage_matrix")
    if not isinstance(coverage, list) or len(coverage) != 15 \
            or len({row.get("id") for row in coverage if isinstance(row, dict)}) != 15:
        raise ValueError("Fifteen distinct corpus categories must be inventoried")
    cases = manifest.get("replay_cases")
    if not isinstance(cases, list) or len(cases) != 1:
        raise ValueError("This runner currently has exactly one frozen backend adapter")
    case = cases[0]
    if case.get("adapter") != "northstar_v10_frozen_backend_pipeline":
        raise ValueError("A corpus case lacks a guarded backend replay adapter")
    if truth.get("case_id") != case.get("id") or truth.get("review_status") != case.get("ground_truth_origin"):
        raise ValueError("Ground truth identity or origin does not match the corpus")
    for path_key, hash_key, allowed in (
        ("source_path", "source_sha256", ROOT / "tests/Fixtures"),
        ("ground_truth_path", "ground_truth_sha256", ROOT / "tests/Fixtures"),
        ("frozen_response_path", "frozen_response_sha256", DIAGNOSTICS),
    ):
        path = required_path(case[path_key], allowed)
        if sha256(path) != case[hash_key]:
            raise ValueError(f"Evaluation hash mismatch: {path_key}")
    if (truth.get("source_gold_path") != case["ground_truth_path"]
            or truth.get("source_gold_sha256") != case["ground_truth_sha256"]
            or truth.get("extraction_sha256") != case["extraction_sha256"]
            or truth.get("source_span_revision") != case["source_span_revision"]):
        raise ValueError("The truth specification points to a different source gold")
    expected_span_revision = hashlib.sha256(
        (case["extraction_sha256"] + "|1").encode("ascii")).hexdigest()[:32]
    if case["source_span_revision"] != expected_span_revision:
        raise ValueError("Source-span revision differs from the registered extraction")
    gold = read_json(ROOT / case["ground_truth_path"])["cases"][0]["gold"]
    if len(gold) != truth.get("source_gold_fact_count"):
        raise ValueError("Source-gold fact count differs from independent specification")
    ids = [fact["id"] for fact in gold]
    if len(set(ids)) != len(ids):
        raise ValueError("Duplicate source-gold fact identifier")
    for fact in gold:
        for field in ("id", "metric", "value", "unit", "period", "subject", "value_basis",
                      "page", "table", "row", "column"):
            if field not in fact:
                raise ValueError(f"Source gold missing {field}: {fact.get('id')}")
    expected = truth["expected_financial_series"]
    if set(expected) != {"revenue", "costs", "profit", "margin"}:
        raise ValueError("Financial series specification is incomplete")
    if any(len(facts) != 3 or not set(facts).issubset(ids) for facts in expected.values()):
        raise ValueError("Financial series contains missing source-gold facts")
    eligible = truth["analytical_eligibility_expectations"]["eligible_gold_ids"]
    if not set(eligible).issubset(ids):
        raise ValueError("Eligibility expectation lacks independent source gold")
    operators = truth.get("printed_comparison_operators", {})
    if not isinstance(operators, dict) or not set(operators).issubset(ids) \
            or any(value not in ("<", "≤", ">", "≥", "=") for value in operators.values()):
        raise ValueError("Printed comparison operators are not independently specified")
    populations = truth.get("quantity_population_expectations", [])
    if not isinstance(populations, list) or any(
            item.get("gold_id") not in ids or item.get("quantity_kind") != "count"
            or not item.get("population") for item in populations):
        raise ValueError("Count population expectation is incomplete")
    if truth.get("expected_comparison_membership", {}).get("review_status") != "not_reviewed":
        raise ValueError("Comparison membership needs a separately reviewed scoring rule")
    adversarial = read_json(ADVERSARIAL)
    if adversarial.get("classification") != "synthetic_adversarial_claims":
        raise ValueError("Adversarial claims must be labeled synthetic")
    if len({row["id"] for row in adversarial["cases"]}) != len(adversarial["cases"]):
        raise ValueError("Duplicate adversarial case identifier")
    if any(row["expected"] not in ("accept", "reject") for row in adversarial["cases"]):
        raise ValueError("Adversarial expectation must be explicit")
    return case, adversarial


def phpunit_result(stdout: str) -> dict:
    for line in reversed(stdout.splitlines()):
        try:
            result = json.loads(line)
        except json.JSONDecodeError:
            continue
        if isinstance(result, dict) and result.get("tool") == "phpunit":
            return result
    raise RuntimeError("Backend test did not return a machine-readable result")


def run_test(test_file: str, extra: list[str], environment: dict[str, str], timeout: int = 180) -> dict:
    command = ["php", "artisan", "test", test_file, *extra]
    process = subprocess.run(command, cwd=ROOT, env=environment, capture_output=True,
                             text=True, timeout=timeout, check=False)
    result = phpunit_result(process.stdout)
    if process.returncode != 0 or result.get("result") != "passed":
        failures = result.get("failures", [])
        names = [row.get("test", "unknown") for row in failures if isinstance(row, dict)]
        raise RuntimeError(f"Guarded backend test failed: {test_file}; failures={names}")
    return {key: result.get(key) for key in ("result", "tests", "passed", "skipped", "assertions", "duration_ms")}


def diagnostic_paths() -> set[Path]:
    return {path for path in DIAGNOSTICS.glob("recorded-*.json")
            if not path.name.endswith(".provider-response.json")}


def validate_replayed_body(report: dict) -> None:
    # Laravel's HTTP fake re-encodes the frozen JSON object before the real
    # parser receives it. The source file hash is checked separately by the
    # manifest and the backend test; this checks the actual parser input.
    name = report.get("provider_response_file")
    if not isinstance(name, str) or Path(name).name != name:
        raise ValueError("Missing or unsafe recorded parser input")
    path = DIAGNOSTICS / name
    if not path.is_file():
        raise ValueError("Recorded parser input is missing")
    if sha256(path) != report.get("provider_response_sha256"):
        raise ValueError("Recorded parser input hash mismatch")


def candidate_key(candidate: dict) -> tuple:
    claim = candidate.get("candidate") or {}
    identity = claim.get("identity") or {}
    return (str(claim.get("label", "")).casefold(), str(claim.get("value", "")),
            str(claim.get("unit", "")).casefold(), str(identity.get("period", "")).casefold(),
            str(identity.get("scope", "")).casefold(), str(identity.get("value_basis", "")).casefold())


def normalized(value: object) -> str:
    return " ".join(str(value or "").casefold().split())


def numeric(value: object) -> Decimal | None:
    # Compare printed magnitudes only. No currency or scale conversion is made.
    match = re.search(r"(?<![\d.])[+-]?\d[\d,]*(?:\.\d+)?(?![\d.])", str(value or ""))
    if match is None:
        return None
    number = Decimal(match.group().replace(",", ""))
    if "-" in str(value)[:match.start()] and number > 0:
        number = -number
    return number


def comparison_operator(value: object) -> str | None:
    match = re.search(r"≤|≥|(?<![<>=])(?:<=|>=|<|>|=)(?![<>=])", str(value or ""))
    return match.group() if match else None


def quarter(value: object) -> str | None:
    match = re.search(r"\bQ[1-4]\b", str(value or ""), flags=re.IGNORECASE)
    return match.group().upper() if match else None


def numeric_gold_match(candidate: dict, gold: list[dict]) -> dict | None:
    """Require one independently transcribed row, page, value and quarter.

    This is a numerical-source match, not analytical verification. It does not
    credit entity, basis, currency, unit or a missing reporting year.
    """
    value_source = candidate.get("source_context", {}).get("value_span_id", {})
    if not isinstance(value_source, dict) or not isinstance(value_source.get("page"), int):
        return None
    claim = candidate.get("candidate") or {}
    magnitude = numeric(claim.get("value"))
    if magnitude is None:
        return None
    claim_quarter = quarter((candidate.get("api_kpi") or {}).get("period")
                            or (claim.get("identity") or {}).get("period"))
    matches = [fact for fact in gold
               if fact["page"] == value_source["page"]
               and normalized(fact["row"]) == normalized(value_source.get("text"))
               and numeric(fact.get("source_value", fact["value"])) == magnitude
               and comparison_operator(claim.get("value")) == fact.get("comparison_operator")
               and (claim_quarter is None or quarter(fact.get("period")) == claim_quarter)]
    return matches[0] if len(matches) == 1 else None


def semantic_review(candidate: dict, fact: dict) -> dict:
    claim = candidate.get("candidate") or {}
    identity = claim.get("identity") or {}
    card = candidate.get("api_kpi") or {}
    period = card.get("period") or identity.get("period")
    unit = claim.get("unit")
    gold_unit = fact.get("unit")
    count_population = normalized(gold_unit) in ("count", "cases", "shipments", "headcount")
    same_period = normalized(period) == normalized(fact.get("period"))
    incomplete_period = not same_period and quarter(period) is not None \
        and quarter(period) == quarter(fact.get("period")) \
        and re.search(r"\b\d{4}\b", str(period or "")) is None
    return {
        # A model scope can name a population while gold names its reporting
        # owner. This difference requires review; it is not an entity error by itself.
        "scope_differs_from_gold_subject": normalized(identity.get("scope")) != normalized(fact.get("subject")),
        "basis_error": normalized(identity.get("value_basis")) != normalized(fact.get("value_basis")),
        "period_incomplete": incomplete_period,
        "period_conflict": not same_period and not incomplete_period,
        "explicit_unit_conflict": bool(unit) and normalized(unit) != normalized(gold_unit)
            and not (normalized(gold_unit) == "count" and normalized(unit) in ("cases", "shipments", "employees")),
        "unit_missing": not bool(unit) and bool(gold_unit),
        "count_population_applicable": count_population,
        "count_population_conflict": count_population
            and normalized(claim.get("label")) != normalized(fact.get("metric")),
    }


def score_report(report: dict, case: dict, truth: dict, gold: list[dict] | None = None) -> dict:
    if report.get("outcome") != "success" or report.get("requests") != 1:
        raise ValueError("Frozen backend replay was not a successful single mocked request")
    for key, expected in (("source_sha256", case["source_sha256"]),
                          ("extracted_text_sha256", case["extraction_sha256"]),
                          ("source_revision", case["source_span_revision"]),
                          ("span_count", case["source_span_count"]),
                          ("gold_sha256", case["ground_truth_sha256"])):
        if report.get(key) != expected:
            raise ValueError(f"Replay provenance mismatch: {key}")
    candidates = report["candidates"]
    total = len(candidates)
    model = report["model_observation_count"]
    expanded = report["server_expanded_observation_count"]
    if total != model + expanded:
        raise ValueError("Persisted observations do not reconcile with model and expansion counts")
    coverage = report["source_gold_score"]["gold_coverage"]
    if len(coverage) != truth["source_gold_fact_count"]:
        raise ValueError("Replay was scored against an incomplete source-gold set")
    matched = {index for row in coverage for index in row["candidate_indexes"]}
    if any(not isinstance(index, int) or index < 0 or index >= total for index in matched):
        raise ValueError("Source-gold candidate index is invalid")
    linked = {index for index, candidate in enumerate(candidates) if candidate.get("evidence") is not None}
    proven = {index for index in linked if isinstance(
        candidates[index]["evidence"]["data"].get("source_cell_start_offset"), int)
        and isinstance(candidates[index]["evidence"]["data"].get("source_cell_end_offset"), int)}
    eligible = {index for index, candidate in enumerate(candidates)
                if candidate.get("api_kpi", {}).get("analyticalEligible") is True}
    if len(linked) != report["source_linked_observation_count"]:
        raise ValueError("Source-linked observation count does not reconcile")
    if len(proven) != report["structurally_proven_observation_count"]:
        raise ValueError("Exact-cell observation count does not reconcile")
    if len(eligible) != report["independently_eligible_count"]:
        raise ValueError("Analytical eligibility count does not reconcile")
    if not eligible.issubset(linked):
        raise ValueError("Analytically eligible observation lacks persisted metric evidence")
    expected_ids = set(truth["analytical_eligibility_expectations"]["eligible_gold_ids"])
    eligible_gold = {row["gold_id"] for row in coverage if row["gold_id"] in expected_ids
                     and any(index in eligible for index in row["candidate_indexes"])}
    matched_expected = {row["gold_id"] for row in coverage if row["gold_id"] in expected_ids
                        and row["candidate_indexes"]}
    rejected_correct = {index for index in matched if index not in linked}
    numeric_matches = {index: fact for index, candidate in enumerate(candidates)
                       if gold is not None and (fact := numeric_gold_match(candidate, gold)) is not None}
    semantic = [semantic_review(candidates[index], fact) for index, fact in numeric_matches.items()]
    field_counts = {key: sum(review[key] for review in semantic) for key in
                    ("scope_differs_from_gold_subject", "basis_error", "period_incomplete",
                     "period_conflict",
                     "explicit_unit_conflict", "unit_missing", "count_population_applicable",
                     "count_population_conflict")}
    field_counts["scope_mismatch_analytically_eligible"] = sum(
        index in eligible and semantic_review(candidates[index], fact)["scope_differs_from_gold_subject"]
        for index, fact in numeric_matches.items())
    api = report.get("api_evidence") or {}
    source_ids = {candidate["source_id"] for candidate in candidates}
    api_valid = sum(bool(mapping.get("quote")) and bool(mapping.get("sources"))
                    and all(isinstance(source.get("start_offset"), int)
                            and isinstance(source.get("end_offset"), int)
                            and source["end_offset"] > source["start_offset"]
                            and bool(source.get("quote")) for source in mapping["sources"])
                    for mapping in api.values())
    if api_valid != len(api) or not set(api).issubset(source_ids):
        raise ValueError("Composite API evidence mapping is incomplete or unrecognized")
    mapped_keys = sorted(candidate_key(candidate) for candidate in candidates
                         if candidate["source_id"] in api)
    reasons = report.get("rejection_reasons") or {}
    signature = {
        "model": model, "expanded": expanded, "linked": len(linked), "proven": len(proven),
        "eligible": len(eligible), "reasons": reasons,
        "matched_gold_ids": sorted(row["gold_id"] for row in coverage if row["candidate_indexes"]),
        "numeric_gold_ids": sorted(fact["id"] for fact in numeric_matches.values()),
        "semantic_review": field_counts,
        "eligible_gold_ids": sorted(eligible_gold), "api_mapped_claims": mapped_keys,
        "financial_series": report["strict_complete_financial_series_count"],
        "charts": report["trusted_chart_count"],
        "comparisons": report["deterministic_comparison_count"],
    }
    return {
        "flag_enabled": bool(report.get("server_evidence_resolution_enabled")),
        "observations": {"model": model, "server_expanded": expanded, "persisted": total},
        "numerically_correct_exact_gold": {"count": len(matched), "denominator": total,
                                           "unknown": total - len(matched)},
        "numerically_source_matched": {"count": len(numeric_matches), "denominator": total,
                                       "unknown": total - len(numeric_matches),
                                       "method": "unique independent page-row-value-quarter match"},
        "source_linked": {"count": len(linked), "denominator": total},
        "exact_cell_proven": {"count": len(proven), "denominator": total},
        "analytically_eligible": {"count": len(eligible), "denominator": total},
        "correct_claims_rejected_for_grounding": {"count": len(rejected_correct),
                                                   "denominator": len(matched)},
        "expected_financial_facts_eligible": {"count": len(eligible_gold),
                                              "denominator": len(expected_ids),
                                              "observed_gold_claims": len(matched_expected)},
        "source_gold_coverage": {"count": sum(bool(row["candidate_indexes"]) for row in coverage),
                                 "denominator": len(coverage)},
        "incorrect_claims_accepted": {"count": None, "denominator": 0,
                                      "reason": "No independently labeled negative claim in frozen response"},
        "semantic_review_of_numeric_matches": {"denominator": len(numeric_matches),
                                               **field_counts,
                                               "unmatched_candidates": total - len(numeric_matches)},
        "financial_series": {"count": report["strict_complete_financial_series_count"],
                             "denominator": len(truth["expected_financial_series"])},
        "charts": report["trusted_chart_count"],
        "comparisons": report["deterministic_comparison_count"],
        "api_evidence_mappings": {"valid": api_valid, "denominator": len(api)},
        "rejection_reasons": reasons,
        "latency_ms": {"provider_parser": report.get("latency_ms"),
                       "pipeline_to_api": report.get("pipeline_latency_ms")},
        "recorded_provider_usage": {
            "input_tokens": ((report.get("provider_attempts") or [{}])[0].get("usage") or {}).get("input_tokens"),
            "output_tokens": ((report.get("provider_attempts") or [{}])[0].get("usage") or {}).get("output_tokens"),
            "estimated_usd_for_original_response": report.get("known_estimated_cost_usd")},
        "actual_paid_inference_calls": 0,
        "signature": signature,
    }


def compact_runs(runs: list[dict]) -> dict:
    public_keys = ["observations", "numerically_correct_exact_gold", "numerically_source_matched",
                   "semantic_review_of_numeric_matches", "source_linked",
                   "exact_cell_proven", "analytically_eligible", "correct_claims_rejected_for_grounding",
                   "expected_financial_facts_eligible", "source_gold_coverage",
                   "financial_series", "charts", "comparisons", "api_evidence_mappings",
                   "rejection_reasons", "latency_ms", "recorded_provider_usage"]
    count_keys = ("source_linked", "exact_cell_proven", "analytically_eligible",
                  "financial_series", "charts", "comparisons")
    def value(run: dict, key: str) -> int:
        item = run[key]
        return item["count"] if isinstance(item, dict) else item
    return {"runs": len(runs), "first_run": {key: runs[0][key] for key in public_keys},
            "ranges": {key: {"min": min(value(run, key) for run in runs),
                              "max": max(value(run, key) for run in runs)} for key in count_keys},
            "latency_pipeline_ms": [run["latency_ms"]["pipeline_to_api"] for run in runs],
            "api_mapping_counts": [run["api_evidence_mappings"]["denominator"] for run in runs],
            "nondeterministic": any(run["signature"] != runs[0]["signature"] for run in runs[1:])}


def render_markdown(result: dict) -> str:
    off = result["feature_off"]
    on = result["feature_on"]
    rows = [
        ("Model observations", "observations", "model"),
        ("Server expanded", "observations", "server_expanded"),
        ("Unique source-gold numerical matches", "numerically_source_matched", "count"),
        ("Source linked", "source_linked", "count"),
        ("Exact cell proven", "exact_cell_proven", "count"),
        ("Analytically eligible", "analytically_eligible", "count"),
        ("Complete financial series", "financial_series", "count"),
        ("Charts", "charts", None),
        ("Comparisons", "comparisons", None),
        ("Valid API mappings", "api_evidence_mappings", "valid"),
    ]
    lines = ["# Offline evidence-first gate", "",
             f"Case: `{result['source_case']}` ({result['source_classification']}).",
             f"Real authorized documents: **{result['real_authorized_document_count']}**. ",
             f"Repeated off/on pairs: **{off['runs']}**. Paid inference calls: **0**.", "",
             "| Measure | Flag off | Flag on |", "|---|---:|---:|"]
    for label, key, subkey in rows:
        def display(state: dict) -> str:
            value = state["first_run"][key]
            if subkey is None:
                return str(value)
            numerator = value[subkey]
            return f"{numerator}/{value['denominator']}" if "denominator" in value else str(numerator)
        lines.append(f"| {label} | {display(off)} | {display(on)} |")
    wrong = result["synthetic_adversarial"]["incorrect_claims_accepted"]
    correct = result["synthetic_adversarial"]["correct_claims_rejected_by_grounder"]
    lines += ["", f"Synthetic false acceptances: **{wrong['count']}/{wrong['denominator']}**; "
              f"false rejections: **{correct['count']}/{correct['denominator']}**.",
              f"Repeated-output variation: off={off['nondeterministic']}, on={on['nondeterministic']}.",
              f"Parser-to-API latency (ms): off={off['latency_pipeline_ms']}; "
              f"on={on['latency_pipeline_ms']}.", "",
              f"Verdict: **{result['generalization_verdict']}**.",
              "Unmatched observations and missing real-document ground truth are unknown, not successes.", ""]
    return "\n".join(lines)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repeat", type=int, default=3, help="Independent flag-off/on replay pairs (2–10)")
    parser.add_argument("--real-case", help="Reviewed local real-document case ID")
    args = parser.parse_args()
    if not 2 <= args.repeat <= 10:
        parser.error("--repeat must be between 2 and 10")
    if args.real_case:
        from tools.evidence_corpus import run_case
        print(json.dumps(run_case(args.real_case, args.repeat), sort_keys=True))
        return 0
    manifest = read_json(MANIFEST)
    truth = read_json(TRUTH)
    case, adversarial = validate_spec(manifest, truth)
    gold = read_json(ROOT / case["ground_truth_path"])["cases"][0]["gold"]
    for fact in gold:
        fact["comparison_operator"] = truth["printed_comparison_operators"].get(fact["id"])
    environment = os.environ.copy()
    environment.update({"APP_DEMO_MODE": "0", "DB_CONNECTION": "pgsql", "DB_HOST": "127.0.0.1",
                        "DB_PORT": "55449", "DB_DATABASE": "docintel_v9_eval", "DB_URL": "",
                        "DOCINTEL_V10_EVAL_RUN": "1", "DOCINTEL_V10_FROZEN_REPLAY": "1",
                        "DOCINTEL_V10_EVAL_LIVE": "0", "DOCINTEL_V10_EVAL_APPROVED": "0",
                        "DOCINTEL_V9_EVAL_LIVE": "0"})
    started = time.time()
    runs = {"off": [], "on": []}
    for index in range(args.repeat):
        # Alternate order so warm-process/cache effects do not always favor ON.
        order = ("off", "on") if index % 2 == 0 else ("on", "off")
        for mode in order:
            before = diagnostic_paths()
            filter_name = ("test_frozen_live_response_replays_with_server_evidence_resolution"
                           if mode == "on" else "test_frozen_live_response_replays_without_provider_access")
            run_test(REPLAY_TEST, ["--filter=" + filter_name], environment)
            new = diagnostic_paths() - before
            if len(new) != 1:
                raise RuntimeError(f"Expected one fresh {mode} replay report, got {len(new)}")
            report = read_json(next(iter(new)))
            validate_replayed_body(report)
            score = score_report(report, case, truth, gold)
            key = "on" if score["flag_enabled"] else "off"
            if key != mode:
                raise RuntimeError("Frozen replay ran with the wrong feature state")
            runs[key].append(score)
        if len(runs["off"]) != index + 1 or len(runs["on"]) != index + 1:
            raise RuntimeError("Replay did not run both feature states exactly once")
    adversarial_test = run_test(ADVERSARIAL_TEST, [], environment)
    expected_tests = len(adversarial["cases"]) * 2
    if adversarial_test["tests"] != expected_tests or adversarial_test["skipped"] not in (None, 0):
        raise RuntimeError("Adversarial decision denominator differs from fixture")
    wrong = sum(row["expected"] == "reject" for row in adversarial["cases"])
    correct = sum(row["expected"] == "accept" for row in adversarial["cases"])
    result = {
        "schema_version": 1, "run_type": "offline_frozen_backend_replay",
        "source_case": case["id"], "source_classification": case["classification"],
        "document_type": case["document_type"],
        "source_sha256": case["source_sha256"],
        "extraction_sha256": case["extraction_sha256"],
        "source_span_revision": case["source_span_revision"],
        "frozen_response_sha256": case["frozen_response_sha256"],
        "ground_truth_sha256": case["ground_truth_sha256"],
        "ground_truth_origin": case["ground_truth_origin"],
        "real_authorized_document_count": len(manifest["real_authorized_documents"]),
        "coverage_matrix": manifest["coverage_matrix"],
        "feature_off": compact_runs(runs["off"]), "feature_on": compact_runs(runs["on"]),
        "synthetic_adversarial": {
            "incorrect_claims_accepted": {"count": 0, "denominator": wrong * 2},
            "correct_claims_rejected_by_grounder": {"count": 0, "denominator": correct * 2},
            "test_result": adversarial_test,
        },
        "generalization_verdict": "not_assessable_without_authorized_real_documents_and_independent_gold",
        "actual_paid_inference_calls": 0,
        "wall_time_ms": round((time.time() - started) * 1000),
    }
    os.umask(0o077)
    OUTPUT.mkdir(mode=0o700, parents=True, exist_ok=True)
    path = OUTPUT / f"gate-{time.strftime('%Y%m%d-%H%M%S')}.json"
    with path.open("x", encoding="utf-8") as target:
        json.dump(result, target, indent=2, sort_keys=True)
        target.write("\n")
    os.chmod(path, 0o600)
    readable = path.with_suffix(".md")
    with readable.open("x", encoding="utf-8") as target:
        target.write(render_markdown(result))
    os.chmod(readable, 0o600)
    print(json.dumps({"result": "passed", "artifact": str(path.relative_to(ROOT)),
                      "human_report": str(readable.relative_to(ROOT)),
                      "replay_pairs": args.repeat, "synthetic_negative_decisions": wrong * 2,
                      "real_authorized_documents": len(manifest["real_authorized_documents"]),
                      "generalization_verdict": result["generalization_verdict"]}))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (OSError, ValueError, RuntimeError, subprocess.TimeoutExpired) as error:
        print(json.dumps({"result": "failed", "reason": str(error)}), file=sys.stderr)
        raise SystemExit(1)
