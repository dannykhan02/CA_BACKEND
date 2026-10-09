"""Offline encoding benchmark; optional free token-count calls, hard capped at 40."""
import hashlib
import json
import os
import pathlib
import statistics
import urllib.request
from collections import Counter, defaultdict

HERE = pathlib.Path(__file__).resolve().parent
DIAG = HERE.parent
MODEL = "claude-haiku-4-5-20251001"
CAP = 40
KEYS = {"records": "m", "label": "l", "value": "v", "subject": "s", "reference": "r", "evidence_ids": "e",
        "entity_type": "et", "unit": "u", "period": "p", "date_type": "dt", "due_date": "dd", "severity": "sv",
        "metric_type": "mt", "value_basis": "vb", "aggregation": "ag", "quantity_kind": "qk", "kind": "k",
        "confidence": "cf", "aliases": "a", "quote": "qt"}
NULLABLE = {"entity_type", "unit", "period", "date_type", "due_date", "severity", "metric_type", "value_basis", "aggregation", "quantity_kind"}


def dump(x):
    return json.dumps(x, separators=(",", ":"), ensure_ascii=False)


def compact(records, short=False, omit_null=True):
    rows = []
    for record in records:
        rows.append({KEYS[k] if short else k: v for k, v in record.items() if not (omit_null and k in NULLABLE and v is None)})
    return {"m" if short else "metrics": rows}


def key():
    k = os.environ.get("ANTHROPIC_API_KEY")
    if k:
        return k
    env = HERE.parents[3] / ".env"
    if env.exists():
        for line in env.read_text().splitlines():
            if line.startswith("ANTHROPIC_API_KEY="):
                return line.partition("=")[2].strip().strip("'\"")
    return None


def count(text, state):
    if state["requests"] >= CAP:
        raise RuntimeError("token-count request cap reached")
    body = dump({"model": MODEL, "messages": [{"role": "user", "content": text}]}).encode()
    request = urllib.request.Request("https://api.anthropic.com/v1/messages/count_tokens", data=body, headers={
        "content-type": "application/json", "anthropic-version": "2023-06-01", "x-api-key": key()}, method="POST")
    state["requests"] += 1
    with urllib.request.urlopen(request, timeout=60) as response:
        return json.load(response)["input_tokens"]


def main():
    paths = sorted((DIAG / "unicef-4call").glob("*.response.json")) + sorted((DIAG / "experiment-flags/paid-study").glob("*.response.json"))
    cells = []
    for path in paths:
        response = json.loads(path.read_text())
        raw = "".join(b["text"] for b in response["content"] if b["type"] == "text")
        records = json.loads(raw)["records"]
        cells.append({"cell": path.stem.removesuffix(".response"), "chunk": "unicef_quantitative" if "unicef-4call" in str(path) else path.name.split("-")[0],
                      "raw": raw, "records": records, "record_count": len(records), "actual_output_tokens": response["usage"]["output_tokens"]})
    assert sum(c["record_count"] for c in cells) == 912
    state = {"requests": 0}
    calibration = {"model": MODEL, "endpoint": "/v1/messages/count_tokens", "request_cap": CAP,
                   "requests_used": 0, "method": "Saved output text submitted as a user message; subtract measured empty-message overhead. Output versus input tokenization may differ.", "cells": []}
    gate = {"pre_registered_gate": .25, "model": MODEL, "population_records": 912, "population_cells": 16,
            "status": "UNMEASURED", "compact_codec_worth_implementing": None, "variants": {}, "token_count_requests": 0}
    if not key():
        gate["reason"] = "Anthropic API credential unavailable"
    else:
        try:
            # The Messages API rejects an empty user content block. A one-token probe
            # gives fixed framing overhead after subtracting that one content token.
            overhead = count("x", state) - 1
            calibration["fixed_overhead_tokens"] = overhead
            for cell in (cells[0], cells[1], cells[4], cells[-1]):
                counted = count(cell["raw"], state)
                adjusted = counted - overhead
                error = (adjusted - cell["actual_output_tokens"]) / cell["actual_output_tokens"]
                calibration["cells"].append({"cell": cell["cell"], "actual_output_tokens": cell["actual_output_tokens"],
                     "counted_as_input_tokens": counted, "adjusted_tokens": adjusted, "relative_error": error})
            calibration["absolute_error_median"] = statistics.median(abs(x["relative_error"]) for x in calibration["cells"])
            calibration["absolute_error_max"] = max(abs(x["relative_error"]) for x in calibration["cells"])
            calibration["acceptable_within_10_percent"] = calibration["absolute_error_max"] <= .10
            for variant in ("current", "readable", "short"):
                chunks = defaultdict(list)
                for cell in cells:
                    chunks[cell["chunk"]].append(cell)
                measured = []
                for chunk, group in chunks.items():
                    if variant == "current":
                        payload = "\n".join(c["raw"] for c in group)
                    else:
                        payload = "\n".join(dump(compact(c["records"], short=variant == "short")) for c in group)
                    counted = count(payload, state)
                    measured.append({"chunk": chunk, "record_count": sum(c["record_count"] for c in group),
                                     "chars": len(payload), "utf8_bytes": len(payload.encode()), "tokens": counted-overhead})
                gate["variants"][variant] = {"chunks": measured, "tokens": sum(x["tokens"] for x in measured),
                    "chars": sum(x["chars"] for x in measured), "utf8_bytes": sum(x["utf8_bytes"] for x in measured)}
                gate["variants"][variant]["tokens_per_record"] = gate["variants"][variant]["tokens"] / 912
            base = gate["variants"]["current"]["tokens"]
            for v in gate["variants"].values():
                v["savings_vs_current"] = 1-v["tokens"]/base
            best = min(gate["variants"]["readable"]["tokens"], gate["variants"]["short"]["tokens"])
            gate["best_savings"] = 1-best/base
            gate["compact_codec_worth_implementing"] = gate["best_savings"] >= .25
            gate["status"] = "MEASURED" if calibration["acceptable_within_10_percent"] else "APPROXIMATE"
        except Exception as exc:
            gate["reason"] = f"Token-count endpoint unavailable: {type(exc).__name__}: {exc}"
    gate["token_count_requests"] = state["requests"]
    calibration["requests_used"] = state["requests"]
    (HERE / "compact-token-calibration.json").write_text(dump(calibration)+"\n")
    (HERE / "compact-format-token-gate.json").write_text(dump(gate)+"\n")
    print(gate["status"], gate.get("reason", ""), "requests", state["requests"], "best savings", gate.get("best_savings"))


if __name__ == "__main__":
    main()
