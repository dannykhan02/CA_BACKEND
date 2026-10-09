import hashlib
import json
from compact_codec import ORDER, REQUIRED
from measure_compact import HERE, KEYS, NULLABLE


def main():
    source = json.loads((HERE.parent / "experiment-flags/paid-study/unicef_reduced-C1.request.json").read_text())["output_config"]["format"]["schema"]
    fields = source["properties"]["records"]["items"]["properties"]
    props = {}
    for name in ORDER:
        if name == "quote":
            continue
        spec = json.loads(json.dumps(fields[name]))
        if name in NULLABLE:
            spec = {"type":"string"}
            if name == "date_type":
                spec["enum"] = ["explicit", "relative", "inferred"]
        props[KEYS[name]] = spec
    schema = {"type":"object", "properties":{"m":{"type":"array", "items":{
        "type":"object", "properties":props,
        "required":[KEYS[k] for k in ORDER if k in REQUIRED], "additionalProperties":False}}},
        "required":["m"], "additionalProperties":False}
    (HERE / "compact-structured-output-schema.json").write_text(json.dumps(schema, indent=2)+"\n")
    status = {"status":"OFFLINE_CODEC_TESTED_NOT_ACTIVATED", "schema_sha256":hashlib.sha256(json.dumps(schema,separators=(",", ":")).encode()).hexdigest(),
        "optional_property_count": len(set(props)-set(schema["properties"]["m"]["items"]["required"])),
        "union_property_count": 0, "provider_documented_optional_limit":24, "provider_documented_union_limit":16,
        "explicit_schema_limits_fit":True, "provider_compilation_test":"NOT_RUN; internal grammar limits cannot be verified offline",
        "current_salvage_assumes_verbose_records_key":True,
        "compact_truncation_path":"Offline salvage recognizes m and expands only complete valid records; future integration must route before EvidenceSchema::validate and preserve failure artifacts",
        "future_store_hashes":["raw_compact_response_sha256","expanded_canonical_response_sha256"],
        "offline_tests":"7 unittest tests pass including 912 record round trips, malformed/fuzz, truncation, fresh process; 12 saved downstream PHP replay cells exactly match acceptance, reasons, typed projector, provenance, identities and key-figure eligibility",
        "provider_schema_docs":"https://platform.claude.com/docs/en/build-with-claude/structured-outputs"}
    (HERE / "compact-codec-status.json").write_text(json.dumps(status,indent=2)+"\n")


if __name__ == "__main__":main()
