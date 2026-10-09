#!/usr/bin/env python3
"""Materialize the frozen final-study decision from saved offline metrics."""

import json
from pathlib import Path

HERE = Path(__file__).resolve().parent
PRE_B2 = HERE.parent
metrics = json.loads((HERE / "study-metrics.json").read_text())
manifest = json.loads((HERE / "request-hash-manifest.json").read_text())
contract = json.loads((PRE_B2 / "version-freeze-plan.json").read_text())
plan = json.loads((PRE_B2 / "minimal-prompt-experiment-plan.json").read_text())


def write(name, value):
    (HERE / name).write_text(json.dumps(value, indent=2, ensure_ascii=False) + "\n")


g = metrics["groups"]
v = metrics["variants"]
p = metrics["paired"]
c = metrics["cost"]
effects = {}
safety = {}
for chunk in plan["sample"]["chunks"]:
    a, b = g[chunk]["A"], g[chunk]["B"]
    rep_delta = 100 * (b["mean_headline_representation"] - a["mean_headline_representation"])
    gold_delta = b["mean_gold_recalled"] - a["mean_gold_recalled"]
    rep_positive = sum(row["representation_delta_pp"] > 0 for row in p[chunk])
    gold_positive = sum(row["gold_delta_items"] > 0 for row in p[chunk])
    effects[chunk] = {
        "headline_representation_delta_pp": rep_delta,
        "mean_additional_gold_items": gold_delta,
        "positive_representation_pairs": rep_positive,
        "positive_gold_pairs": gold_positive,
        "paired_runs": len(p[chunk]),
        "majority_for_either_primary": rep_positive > len(p[chunk]) / 2 or gold_positive > len(p[chunk]) / 2,
    }
    safety[chunk] = {
        "precision_deterioration_pp": 100 * (a["supported_precision"] - b["supported_precision"]),
        "precision_gate_failed": (a["supported_precision"] - b["supported_precision"]) > .05,
        "negative_false_positive_deterioration_pp": 100 * (b["mean_negative_false_positive_rate"] - a["mean_negative_false_positive_rate"]),
        "negative_false_positive_gate_failed": (b["mean_negative_false_positive_rate"] - a["mean_negative_false_positive_rate"]) > .05,
        "rejection_rate_deterioration_pp": 100 * (b["mean_rejection_rate"] - a["mean_rejection_rate"]),
        "rejection_rate_gate_failed": (b["mean_rejection_rate"] - a["mean_rejection_rate"]) > .05,
        "grounding_failure_deterioration_pp": 100 * (b["mean_grounding_failure_rate"] - a["mean_grounding_failure_rate"]),
        "grounding_failure_gate_failed": (b["mean_grounding_failure_rate"] - a["mean_grounding_failure_rate"]) > .02,
        "variant_b_truncations": b["truncated_calls"],
        "variant_b_parser_failures": b["parser_failures"],
        "variant_b_structured_output_failures": b["structured_output_failures"],
    }

overall_rep_gain_pp = 100 * (v["B"]["balanced_mean_headline_representation"] - v["A"]["balanced_mean_headline_representation"])
overall_gold_gain = v["B"]["mean_gold_recalled_per_chunk_call"] - v["A"]["mean_gold_recalled_per_chunk_call"]
meaningful_effect = (
    overall_rep_gain_pp >= plan["minimum_useful_effect"]["either_headline_representation_mean_percentage_points"]
    or overall_gold_gain >= plan["minimum_useful_effect"]["or_additional_gold_items_per_chunk_mean"]
) and all(row["majority_for_either_primary"] for row in effects.values())
safety_pass = not any(value is True for row in safety.values() for key, value in row.items() if key.endswith("gate_failed"))
cost_pass = (
    float(c["actual_total_study_cost_usd"]) <= 1.50
    and float(c["max_chunk_pair_cost_usd"]) <= .15
    and float(c["cost_per_net_additional_represented_candidate_usd"]) <= .10
    and float(c["cost_per_net_additional_gold_item_usd"]) <= .15
)
decision = {
    "status": "COMPLETE",
    "interpretation": "DIRECTIONAL",
    "decision": "KEEP CONTROL",
    "reason": "The preregistered useful-effect criterion and paired-run majority condition failed; UNICEF precision and rejection-rate safety gates also failed.",
    "calls_completed": 20,
    "calls_failed": 0,
    "overall_headline_representation_delta_pp": overall_rep_gain_pp,
    "overall_mean_additional_gold_items_per_chunk_call": overall_gold_gain,
    "minimum_useful_effect_met": meaningful_effect,
    "effects_by_chunk": effects,
    "safety_pass": safety_pass,
    "safety_by_chunk": safety,
    "absolute_cost_gates_pass": cost_pass,
    "cost": c,
    "blinded_review_note": "Frozen blind packet of 396 sampled accepted records, adjudicated by an AI coding agent; not an independent human review.",
}
assert not meaningful_effect and not safety_pass and cost_pass
write("final-decision.json", decision)

write("per-call-results.json", {"cells": metrics["cells"], "order": manifest["order"]})
write("paired-run-comparison.json", p)
write("gold-recall-comparison.json", {
    "cells": {k: {"gold_recalled": x["gold_recalled"], "gold_total": x["gold_total"]} for k, x in metrics["cells"].items()},
    "groups": {k: {arm: {"mean_gold_recalled": x["mean_gold_recalled"], "mean_gold_recall_rate": x["mean_gold_recall_rate"]} for arm, x in rows.items()} for k, rows in g.items()},
    "paired": {k: [{"run": x["run"], "gold_delta_items": x["gold_delta_items"]} for x in rows] for k, rows in p.items()},
})
write("precision-safety-metrics.json", {
    "precision_judgments_sha256": metrics["precision_judgments_sha256"],
    "cells": {k: {field: x[field] for field in (
        "sampled_supported_precision", "precision_sample_n", "negative_false_positive_rate",
        "rejection_rate", "rejection_reasons", "grounding_failure_rate", "truncated",
        "parser_failure", "structured_output_failure", "provider_failure")}
        for k, x in metrics["cells"].items()},
    "gates": safety,
    "reviewer": "AI coding agent using mechanically blinded frozen packet",
})
write("cost-analysis.json", c)
write("provenance-key-figure-comparison.json", {
    "cells": {k: {field: x[field] for field in ("provenance_distribution", "typed_evidence_counts", "key_figure_eligible_count")} for k, x in metrics["cells"].items()},
    "groups": {k: {arm: {field: x[field] for field in ("provenance_document", "provenance_unknown", "typed_evidence_counts", "key_figure_eligible_count")} for arm, x in rows.items()} for k, rows in g.items()},
})

control_contracts = {chunk: manifest["calls"][f"{chunk}:CONTROL"] for chunk in plan["sample"]["chunks"]}
freeze = {
    "status": "FROZEN_FOR_B2",
    "decision": "KEEP CONTROL",
    "interpretation": "DIRECTIONAL",
    "contract_id": contract["contract_id"],
    "chosen_prompt_version": "pre-b2-control-v1",
    "model_id": contract["model_id"],
    "system_prompt_sha256": control_contracts["unicef_reduced"]["system_prompt_sha256"],
    "schema_version": control_contracts["unicef_reduced"]["schema_version"],
    "schema_sha256": control_contracts["unicef_reduced"]["schema_sha256"],
    "canonical_wire_format_version": contract["wire_format_version"],
    "candidate_inventory_detector_version": contract["candidate_inventory_detector_version"],
    "candidate_representation_matcher_version": contract["candidate_representation_matcher_version"],
    "planner_config_version": contract["planner_config_version"],
    "planner_config_sha256": contract["planner_config_sha256"],
    "control_request_contracts": control_contracts,
    "all_study_request_hashes": {k: x["request_body_sha256"] for k, x in manifest["calls"].items()},
    "request_hash_manifest": "request-hash-manifest.json",
    "study_result": "No preregistered meaningful improvement; UNICEF precision and rejection safety gates failed.",
    "study_cost_usd": c["actual_total_study_cost_usd"],
    "generation_calls": 20,
    "production_prompt_changed": False,
    "production_extraction_behavior_changed": False,
    "compact_json": {"COMPACT_CODEC_WORTH_IMPLEMENTING": True, "offline_estimated_savings_percent_approximately": 29.5, "production_activation": "DEFERRED"},
    "known_deferred_issues": ["adaptive routing", "AI document classifier", "field-role provenance", "context citation", "structure-aware segmentation", "residual gap-fill", "prompt caching", "batch API", "semantic deduplication", "null-period identity", "Compact JSON production activation"],
}
write("final-extraction-freeze.json", freeze)
