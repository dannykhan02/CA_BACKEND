"""Run only the frozen final pre-B2 study. No retries or request regeneration."""
import hashlib
import importlib.util
import json
import pathlib
import subprocess
import sys
import time
from datetime import datetime, timezone
from decimal import Decimal

BASE = pathlib.Path(__file__).resolve().parent
PRE = BASE.parent
DIAG = PRE.parent
ROOT = PRE.parents[3]
STUDY = DIAG / "experiment-flags"
sys.path.insert(0, str(PRE))
from candidate_inventory import DETECTOR_VERSION, MATCHER_VERSION, split_spans

spec = importlib.util.spec_from_file_location("saved_runner", STUDY / "collector-study-run.py")
saved = importlib.util.module_from_spec(spec)
spec.loader.exec_module(saved)

FREEZE = json.loads((PRE / "version-freeze-plan.json").read_text())
PLAN = json.loads((PRE / "minimal-prompt-experiment-plan.json").read_text())
TOTAL_LIMIT = Decimal("1.50")
PAIR_LIMIT = Decimal("0.15")
CHUNKS = tuple(PLAN["sample"]["chunks"])
VARIANTS = ("CONTROL", "CONTROL_MINUS_MATERIALITY")
ORDER = [(chunk, variant, run) for run in range(1, 6) for chunk in CHUNKS for variant in VARIANTS]


def sha(x):
    return hashlib.sha256(x if isinstance(x, bytes) else x.encode()).hexdigest()


def dump(x):
    return json.dumps(x, ensure_ascii=False, separators=(",", ":"))


def write(name, obj):
    path = BASE / name
    temp = path.with_name(path.name + ".tmp")
    temp.write_text(json.dumps(obj, indent=2, ensure_ascii=False, default=str) + "\n")
    temp.replace(path)


def current_production_contract():
    code = ('$r=getcwd(); require $r."/vendor/autoload.php"; $a=require $r."/bootstrap/app.php"; '
            '$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); '
            'echo json_encode(["prompt"=>App\\Services\\AI\\Incremental\\EvidenceSchema::instructions("span_reference"),'
            '"schema"=>App\\Services\\AI\\Incremental\\EvidenceSchema::extraction("span_reference")]);')
    out = subprocess.check_output(["php", "-r", code], cwd=ROOT, text=True, timeout=20)
    return json.loads(out)


def verify(chunk, variant):
    if DETECTOR_VERSION != FREEZE["candidate_inventory_detector_version"] or MATCHER_VERSION != FREEZE["candidate_representation_matcher_version"]:
        raise ValueError("detector/matcher version drift")
    planner_hash = sha((ROOT / "app/Services/AI/Incremental/ExtractionCapacity.php").read_bytes()
                       + (ROOT / "app/Services/AI/Incremental/ChunkPlanner.php").read_bytes())
    if planner_hash != FREEZE["planner_config_sha256"]:
        raise ValueError("planner/config drift")
    frozen = FREEZE["chunks"][chunk]
    variant_frozen = frozen["variants"][variant]
    source = (STUDY / f"{chunk}.source.txt").read_text()
    if sha(source) != frozen["source_sha256"] or sha(dump(split_spans(source))) != frozen["span_set_sha256"]:
        raise ValueError("source or span-set drift")
    template = PRE / variant_frozen["request_template"]
    request = json.loads(template.read_text())
    body = dump(request).encode()
    schema = request["output_config"]["format"]
    system = request["system"][0]["text"]
    expected_prompt = "pre-b2-control-v1" if variant == "CONTROL" else "pre-b2-control-minus-materiality-v1"
    if (request["model"] != FREEZE["model_id"] or request["model"] != variant_frozen["model_id"]
        or variant_frozen["extraction_prompt_version"] != expected_prompt
        or sha(system) != variant_frozen["system_prompt_sha256"]
        or sha(dump(schema)) != frozen["schema_sha256"]
        or frozen["schema_version"] != "evidence-schema-span-v1-snapshot"
        or frozen["wire_format_version"] != "canonical-json-span-v1"
        or sha(body) != variant_frozen["request_body_sha256"]
        or request["max_tokens"] != 16000
        or any(k in request for k in ("temperature", "top_p", "top_k", "stop_sequences"))
        or json.loads(request["messages"][0]["content"])["max_records"] != 79):
        raise ValueError("frozen request component drift")
    prod = current_production_contract()
    control = json.loads((PRE / FREEZE["chunks"][chunk]["variants"]["CONTROL"]["request_template"]).read_text())
    if prod["prompt"] != control["system"][0]["text"] or prod["schema"] != schema["schema"]:
        raise ValueError("current production prompt/schema drift")
    other = json.loads((PRE / frozen["variants"]["CONTROL_MINUS_MATERIALITY" if variant == "CONTROL" else "CONTROL"]["request_template"]).read_text())
    request_without_system = dict(request); request_without_system.pop("system")
    other_without_system = dict(other); other_without_system.pop("system")
    if request_without_system != other_without_system:
        raise ValueError("variant isolation drift")
    expected_b = control["system"][0]["text"]
    for sentence in PLAN["removed_sentences"]:
        if expected_b.count(sentence) != 1:
            raise ValueError("materiality removal drift")
        expected_b = expected_b.replace(sentence, "")
    b_request = request if variant == "CONTROL_MINUS_MATERIALITY" else other
    if b_request["system"][0]["text"] != expected_b:
        raise ValueError("variant B prompt drift")
    return body, {"model_id": request["model"], "prompt_version": expected_prompt,
                  "system_prompt_sha256": sha(system), "schema_version": frozen["schema_version"],
                  "schema_sha256": sha(dump(schema)), "wire_format_version": frozen["wire_format_version"],
                  "source_sha256": sha(source), "span_set_sha256": sha(dump(split_spans(source))),
                  "planner_config_sha256": planner_hash, "detector_version": DETECTOR_VERSION,
                  "matcher_version": MATCHER_VERSION, "request_body_sha256": sha(body)}


def parse_provider(raw, status):
    if raw is None:
        return None, None, "NO_RESPONSE"
    try:
        response = json.loads(raw)
    except (ValueError, TypeError):
        return None, None, "PARSER_FAILURE"
    if status != 200:
        return response, None, "HTTP_FAILURE"
    try:
        text = "".join(x["text"] for x in response["content"] if x["type"] == "text")
        records = json.loads(text)["records"]
        if not isinstance(records, list) or not all(isinstance(r, dict) for r in records):
            raise ValueError("records malformed")
    except (KeyError, TypeError, ValueError):
        return response, None, "STRUCTURED_OUTPUT_FAILURE"
    if response.get("stop_reason") == "max_tokens":
        return response, records, "MAX_TOKENS_TRUNCATION"
    if response.get("stop_reason") != "end_turn":
        return response, records, "UNEXPECTED_STOP_REASON"
    if saved.usage_cost(response.get("usage")) is None:
        return response, records, "MISSING_USAGE"
    return response, records, None


def run():
    BASE.mkdir(parents=True, exist_ok=True)
    if (BASE / "study-stop.json").exists():
        raise RuntimeError("existing study-stop.json; no automatic resume")
    if any(BASE.glob("*.response.json")) or any(BASE.glob("*.request.json")):
        raise RuntimeError("existing cell artifacts; no automatic replay")
    key = saved.load_api_key()
    if not key:
        raise RuntimeError("ANTHROPIC_API_KEY unavailable")
    # Whole-study preflight before any network call.
    for chunk, variant, _ in ORDER:
        verify(chunk, variant)
    write("request-hash-manifest.json", {"contract_id": FREEZE["contract_id"],
        "order": [f"{c}-{'A' if v == 'CONTROL' else 'B'}{n}" for c,v,n in ORDER],
        "calls": {f"{c}:{v}": verify(c,v)[1] for c in CHUNKS for v in VARIANTS},
        "initial_repo_head": subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True).strip(),
        "pricing_source": "https://platform.claude.com/docs/en/models/haiku-4-5/overview",
        "approved_limits_usd": {"total": str(TOTAL_LIMIT), "per_chunk_pair": str(PAIR_LIMIT)}})
    spent = Decimal("0")
    reserved = Decimal("0")
    pair_spent = {}
    calls = []
    for chunk, variant, run_number in ORDER:
        cell = f"{chunk}-{'A' if variant == 'CONTROL' else 'B'}{run_number}"
        try:
            body, contract = verify(chunk, variant)
        except Exception as exc:
            stop = {"status": "REQUEST_DRIFT", "cell": cell, "reason": str(exc), "calls_sent": len(calls)}
            write("study-stop.json", stop)
            print(dump(stop), flush=True)
            return
        next_bound = saved.conservative_cost(len(body))
        remaining = ORDER[len(calls):]
        remaining_worst = sum((saved.conservative_cost(len(dump(json.loads((PRE / FREEZE["chunks"][c]["variants"][v]["request_template"]).read_text())).encode()))
                               for c,v,_ in remaining), Decimal("0"))
        pair_key = f"{chunk}:{run_number}"
        pair_used = pair_spent.get(pair_key, Decimal("0"))
        budget = {"actual_spent_usd": str(spent), "unknown_reserved_usd": str(reserved),
                  "next_call_worst_case_usd": str(next_bound),
                  "remaining_cells_worst_case_usd": str(remaining_worst),
                  "pair_spent_usd": str(pair_used), "total_limit_usd": str(TOTAL_LIMIT),
                  "pair_limit_usd": str(PAIR_LIMIT)}
        if spent + reserved + next_bound > TOTAL_LIMIT or pair_used + next_bound > PAIR_LIMIT:
            stop = {"status": "BUDGET_STOP", "cell": cell, "budget": budget, "calls_sent": len(calls)}
            write("study-stop.json", stop)
            print(dump(stop), flush=True)
            return
        (BASE / f"{cell}.request.json").write_bytes(body)
        start = datetime.now(timezone.utc).isoformat()
        clock = time.monotonic()
        http_status, provider_request_id, raw, transport_error = saved.transport(body, key)
        latency_ms = round((time.monotonic()-clock)*1000)
        if raw is not None:
            (BASE / f"{cell}.response.json").write_bytes(raw)
        response, records, failure = parse_provider(raw, http_status)
        if transport_error and failure is None:
            failure = "TRANSPORT_ERROR"
        usage = response.get("usage") if isinstance(response, dict) else None
        cost = saved.usage_cost(usage)
        if cost is None:
            reserved += next_bound
        else:
            spent += cost
            pair_spent[pair_key] = pair_spent.get(pair_key, Decimal("0")) + cost
        returned = len(records) if records is not None else None
        max_records = json.loads(json.loads(body)["messages"][0]["content"])["max_records"]
        output_tokens = usage.get("output_tokens") if isinstance(usage, dict) else None
        meta = {"cell": cell, "chunk": chunk, "variant": variant, "run": run_number,
                "status": "success" if failure is None else "failed", "failure_class": failure,
                "http_status": http_status, "transport_error": transport_error,
                "provider_request_id": provider_request_id or (response.get("id") if isinstance(response, dict) else None),
                "started_at_utc": start, "latency_ms": latency_ms,
                "request_sha256": sha(body), "response_sha256": sha(raw) if raw is not None else None,
                "contract": contract, "budget_before_send": budget, "stop_reason": response.get("stop_reason") if isinstance(response, dict) else None,
                "usage": usage, "actual_cost_usd": str(cost) if cost is not None else None,
                "unknown_cost_reserve_usd": str(next_bound) if cost is None else None,
                "returned_records": returned, "max_records_prompt": max_records,
                "returned_over_max_records": returned/max_records if returned is not None else None,
                "max_tokens": json.loads(body)["max_tokens"],
                "output_over_max_tokens": output_tokens/json.loads(body)["max_tokens"] if isinstance(output_tokens,int) else None}
        write(f"{cell}.metadata.json", meta)
        calls.append(meta)
        write("ledger.json", {"actual_spent_usd": str(spent), "unknown_reserved_usd": str(reserved),
                              "calls_completed": len(calls), "calls_failed": sum(x["status"] == "failed" for x in calls),
                              "pair_spent_usd": {k:str(v) for k,v in pair_spent.items()},
                              "completed_cells": [x["cell"] for x in calls]})
        print(dump({"cell": cell, "status": meta["status"], "cost_usd": meta["actual_cost_usd"],
                    "total_usd": str(spent), "returned": returned, "output_tokens": output_tokens,
                    "failure": failure}), flush=True)
    write("study-stop.json", {"status": "COMPLETE" if all(x["status"] == "success" for x in calls) else "STUDY_INCOMPLETE",
                              "calls_sent": len(calls), "calls_failed": sum(x["status"] == "failed" for x in calls),
                              "actual_cost_usd": str(spent), "unknown_reserved_usd": str(reserved)})


if __name__ == "__main__":
    if sys.argv[1:] != ["--execute"]:
        raise SystemExit("Use --execute to authorize the frozen paid study")
    run()
