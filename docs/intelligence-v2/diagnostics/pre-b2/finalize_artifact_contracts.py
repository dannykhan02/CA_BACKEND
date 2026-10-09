"""Stamp diagnostic results with the frozen compatibility contract."""
import json
import pathlib

HERE = pathlib.Path(__file__).resolve().parent
freeze = json.loads((HERE / "version-freeze-plan.json").read_text())
contract = {"contract_id": freeze["contract_id"], "model_id": freeze["model_id"],
    "wire_format_version": freeze["wire_format_version"],
    "candidate_inventory_detector_version": freeze["candidate_inventory_detector_version"],
    "candidate_representation_matcher_version": freeze["candidate_representation_matcher_version"],
    "planner_config_version": freeze["planner_config_version"], "planner_config_sha256": freeze["planner_config_sha256"],
    "chunk_contracts": {chunk: {"source_sha256": detail["source_sha256"],
        "span_set_sha256": detail["span_set_sha256"], "schema_version": detail["schema_version"],
        "schema_sha256": detail["schema_sha256"], "variants": {name: {
            "extraction_prompt_version": variant["extraction_prompt_version"],
            "system_prompt_sha256": variant["system_prompt_sha256"],
            "request_body_sha256": variant["request_body_sha256"]}
            for name, variant in detail["variants"].items()}} for chunk, detail in freeze["chunks"].items()},
    "historical_chunks": freeze["historical_chunks"],
    "saved_call_contracts": freeze["saved_call_contracts"],
    "request_body_hash_serialization": "UTF-8 JSON, ensure_ascii=false, comma/colon separators, no added whitespace"}

for path in HERE.glob("*.json"):
    if path.name in {"version-freeze-plan.json", "compact-structured-output-schema.json"} or path.name.endswith(".request-template.json"):
        continue
    obj = json.loads(path.read_text())
    if not isinstance(obj, dict):
        raise ValueError(path)
    obj["contract"] = contract
    path.write_text(json.dumps(obj, indent=2, ensure_ascii=False)+"\n")
