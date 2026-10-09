"""Freeze offline request templates and study policy. Never sends requests."""
import hashlib
import json
import pathlib
from candidate_inventory import DETECTOR_VERSION, MATCHER_VERSION, split_spans

HERE = pathlib.Path(__file__).resolve().parent
ROOT = HERE.parents[3]
STUDY = HERE.parent / "experiment-flags"
MODEL = "claude-haiku-4-5-20251001"
REMOVALS = (
    "Prefer material evidence to repetitive boilerplate. ",
    "When the slice holds more qualifying observations than max_records, keep the most material ones (deadlines, obligations, risks, headline totals and key figures, named parties) ahead of row-level table detail and repeated boilerplate. ",
)


def sha(data):
    if not isinstance(data, bytes):
        data = data.encode()
    return hashlib.sha256(data).hexdigest()


def dump(x):
    return json.dumps(x, ensure_ascii=False, separators=(",", ":"))


def save(name, x):
    (HERE / name).write_text(json.dumps(x, indent=2, ensure_ascii=False) + "\n")


def main():
    requests = {}
    freeze = {"contract_id": "intelligence-v2-pre-b2-extraction-v1", "model_id": MODEL,
        "wire_format_version": "canonical-json-span-v1", "candidate_inventory_detector_version": DETECTOR_VERSION,
        "candidate_representation_matcher_version": MATCHER_VERSION,
        "planner_config_version": "pre-b2-saved-request-planner-v1",
        "planner_config_sha256": sha((ROOT / "app/Services/AI/Incremental/ExtractionCapacity.php").read_bytes() + (ROOT / "app/Services/AI/Incremental/ChunkPlanner.php").read_bytes()),
        "chunks": {}, "compatibility_rule": "Do not pool artifacts unless model, prompt, schema, wire format, detector, matcher, planner/config, request body, source, and span-set hashes match the declared cell contract."}
    for chunk in ("unicef_reduced", "india_wash"):
        base = json.loads((STUDY / "paid-study" / (chunk + "-C1.request.json")).read_text())
        source = (STUDY / (chunk + ".source.txt")).read_text()
        schema = base["output_config"]["format"]
        spans = split_spans(source)
        chunk_freeze = {"source_sha256": sha(source), "span_set_sha256": sha(dump(spans)),
            "span_count": len(spans), "schema_version": "evidence-schema-span-v1-snapshot", "schema_sha256": sha(dump(schema)),
            "wire_format_version": "canonical-json-span-v1", "variants": {}}
        for variant in ("CONTROL", "CONTROL_MINUS_MATERIALITY"):
            body = json.loads(json.dumps(base))
            system = body["system"][0]["text"]
            if variant != "CONTROL":
                for sentence in REMOVALS:
                    if system.count(sentence) != 1:
                        raise ValueError("materiality sentence missing or repeated")
                    system = system.replace(sentence, "")
                body["system"][0]["text"] = system
            prompt_version = "pre-b2-control-v1" if variant == "CONTROL" else "pre-b2-control-minus-materiality-v1"
            template_name = chunk + "-" + variant + ".request-template.json"
            save(template_name, body)
            chunk_freeze["variants"][variant] = {"extraction_prompt_version": prompt_version,
                "system_prompt_sha256": sha(system), "request_body_sha256": sha(dump(body)),
                "request_template": template_name, "model_id": body["model"], "max_tokens": body["max_tokens"],
                "temperature": body.get("temperature", "provider_default_as_saved"),
                "source_sha256": sha(source), "span_set_sha256": sha(dump(spans))}
        requests[chunk] = chunk_freeze
    freeze["chunks"] = requests
    # Historical four-call chunk is part of the diagnostics, though not an
    # independent arm of the proposed study and overlaps the reduced chunk.
    historical_source = (STUDY / "unicef_quantitative.source.txt").read_text()
    historical_request = json.loads((HERE.parent / "unicef-4call/C1.request.json").read_text())
    freeze["historical_chunks"] = {"unicef_quantitative": {
        "source_sha256": sha(historical_source),
        "span_set_sha256": sha(dump(split_spans(historical_source))),
        "schema_version": "evidence-schema-span-v1-snapshot",
        "schema_sha256": sha(dump(historical_request["output_config"]["format"])),
        "wire_format_version": "canonical-json-span-v1"}}
    saved_calls = {}
    for directory in (HERE.parent / "unicef-4call", STUDY / "paid-study"):
        for path in sorted(directory.glob("*.request.json")):
            body = json.loads(path.read_text())
            saved_calls[("four_call_" if directory.name == "unicef-4call" else "") + path.name.removesuffix(".request.json")] = {
                "model_id": body["model"], "request_body_sha256": sha(path.read_bytes()),
                "system_prompt_sha256": sha(body["system"][0]["text"]),
                "schema_sha256": sha(dump(body["output_config"]["format"])),
                "wire_format_version": "canonical-json-span-v1"}
    freeze["saved_call_contracts"] = saved_calls
    freeze["request_body_hash_serialization"] = "Proposed template hashes use UTF-8 compact JSON with ensure_ascii=false; historical saved-call hashes use exact saved request bytes."
    save("version-freeze-plan.json", freeze)
    plan = {"status": "PREPARED_NOT_RUN", "generation_calls": 0, "variant_a": "CONTROL", "variant_b": "CONTROL_MINUS_MATERIALITY",
        "single_change": "remove exactly two materiality selection sentences from the system prompt",
        "removed_sentences": list(REMOVALS), "sample": {"chunks": list(requests), "runs_per_cell_per_chunk": 5,
            "new_calls": 20, "run_order_per_chunk": [f"{v}{i}" for i in range(1,6) for v in ("A","B")],
            "fresh_interleaved_control_required": True,
            "third_independent_chunk": "not frozen: no different-document source and blind gold set in saved study artifacts",
            "conclusion_classification_without_third_chunk": "DIRECTIONAL_ONLY"},
        "wire_format": "current canonical JSON; compact 2x2 requires a separate decision",
        "budget_proposed_requires_founder_approval": {"MAX_TOTAL_STUDY_COST_USD": 1.50,
            "MAX_COST_PER_DOCUMENT_CHUNK_PAIR_USD": .15,
            "MAX_COST_PER_ADDITIONAL_REPRESENTED_CANDIDATE_USD": .10,
            "MAX_COST_PER_ADDITIONAL_GOLD_ITEM_USD": .15,
            "basis": "12 saved paid calls cost $0.018473–$0.058467 each; 20 planned calls, conservative per-pair and total ceilings. Product value thresholds require founder approval."},
        "minimum_useful_effect": {"either_headline_representation_mean_percentage_points": 5,
            "or_additional_gold_items_per_chunk_mean": 1,
            "majority_of_paired_runs_per_chunk": True,
            "if_not_met": "KEEP_CONTROL_FREEZE_EXTRACTION_PROCEED_TO_B2"},
        "primary": ["headline explicit quantity representation by class", "frozen gold recall"],
        "safety": ["blinded supported precision", "negative-set false positives", "rejection rate", "grounding failure rate", "truncation", "parser failure", "structured-output failure"],
        "secondary": ["returned records", "accepted records", "output tokens", "absolute cost", "cost per represented candidate gained", "cost per gold item gained", "representation-insensitive Jaccard", "provenance distribution", "key-figure eligibility"],
        "proposed_safety_gates": {"precision_max_deterioration_pp":5, "negative_false_positive_max_deterioration_pp":5,
            "rejection_rate_max_deterioration_pp":5, "grounding_failure_max_deterioration_pp":2,
            "variant_b_attributable_truncation_tolerance":0, "unexplained_systematic_parser_or_structured_failure_tolerance":0,
            "cost": "absolute founder-approved caps; relative cost rise alone does not reject B"},
        "failure_policy": {"max_tokens": "unchanged", "max_records": "soft observational", "returned_over_max_records": "log and preserve",
            "truncation_or_parser_or_malformed_output": "cell failure", "retry_or_replacement": "none unless future protocol permits", "preserve_failed_artifacts": True},
        "version_freeze_file": "version-freeze-plan.json", "request_templates": {c:{v:d["request_template"] for v,d in x["variants"].items()} for c,x in requests.items()}}
    save("minimal-prompt-experiment-plan.json", plan)


if __name__ == "__main__":main()
