import json
import pathlib
import re
import statistics
from collections import Counter

HERE = pathlib.Path(__file__).resolve().parent
DIAG = HERE.parent
PAID = DIAG / "experiment-flags/paid-study"
FEATURES = json.loads((PAID / "analysis/router-feature-table.json").read_text())["chunks"]


def save(name, x):
    (HERE / name).write_text(json.dumps(x, indent=2, ensure_ascii=False) + "\n")


def calls():
    for path in sorted((DIAG / "unicef-4call").glob("*.response.json")) + sorted(PAID.glob("*.response.json")):
        early = "unicef-4call" in str(path)
        cell = path.name.removesuffix(".response.json")
        chunk = "unicef_quantitative" if early else cell.rsplit("-", 1)[0]
        base = path.parent
        response = json.loads(path.read_text())
        request = json.loads((base / (cell + ".request.json")).read_text())
        payload = json.loads(request["messages"][-1]["content"])
        returned = len(json.loads(response["content"][0]["text"])["records"])
        if early:
            replay = json.loads((base / (cell + ".replay-A.json")).read_text())
            accepted = replay["accepted_count"]
        else:
            replay = json.loads((PAID / "analysis" / (cell + ".replay.json")).read_text())
            accepted = replay["accepted_count"]
        yield {"cell": cell, "chunk": chunk, "planner_estimated_record_pressure": FEATURES[chunk]["estimated_record_pressure"],
            "returned_records": returned, "accepted_records": accepted,
            "output_tokens": response["usage"]["output_tokens"], "max_records_prompt": payload["max_records"],
            "returned_over_max_records": returned/payload["max_records"],
            "actual_over_planner": returned/FEATURES[chunk]["estimated_record_pressure"],
            "stop_reason": response["stop_reason"], "max_tokens": request["max_tokens"],
            "output_over_max_tokens": response["usage"]["output_tokens"]/request["max_tokens"],
            "prompted_max_records_exceeded": returned > payload["max_records"]}


def unit_audit():
    rows = []
    for path in sorted((PAID / "analysis").glob("*.accepted.json")):
        cell = path.name.removesuffix(".accepted.json")
        accepted = json.loads(path.read_text())
        trace = [t for t in json.loads((PAID / "analysis" / (cell + ".replay.json")).read_text())["trace"] if t.get("accepted")]
        for index, record in enumerate(accepted):
            if record.get("kind") != "metric" or not re.search(r"\d", str(record.get("value") or "")):
                continue
            citation = " ".join(e.get("text", "") for e in record.get("evidence", []))
            unit = str(record.get("unit") or "")
            value = str(record.get("value") or "")
            currency_code = bool(re.search(r"\b(?:USD|KES|EUR|GBP|INR|NGN|ZAR|JPY)\b", citation, re.I))
            unambig = bool(re.search(r"[€£₹₦¥]", citation))
            dollar = "$" in citation
            currency_field = bool(re.search(r"\b(?:USD|KES|EUR|GBP|INR|NGN|ZAR|JPY)\b|[€£₹₦¥$]", unit, re.I))
            if currency_code:
                currency_status = "EXPLICIT_CODE"
            elif unambig:
                currency_status = "EXPLICIT_UNAMBIGUOUS_SYMBOL"
            elif dollar:
                currency_status = "SYMBOL_AMBIGUOUS"
            elif currency_field:
                currency_status = "UNSUPPORTED"
            else:
                currency_status = "ABSENT"
            # Unit grounding is lexical and conservative; contextual tables need manual review.
            noncurrency_unit = re.sub(r"\b(?:USD|KES|EUR|GBP|INR|NGN|ZAR|JPY)\b|[€£₹₦¥$]|\b(?:million|millions|billion|billions|thousand|thousands)\b", "", unit, flags=re.I).strip(" ()/,")
            if not unit:
                unit_status = "NULL"
            elif not noncurrency_unit:
                unit_status = "CURRENCY_OR_SCALE_ONLY"
            elif "percent" in noncurrency_unit.lower() and ("%" in citation or re.search(r"per\s+cent|percent", citation, re.I)):
                unit_status = "EXPLICIT"
            elif re.search(r"\b"+re.escape(noncurrency_unit)+r"\b", citation, re.I):
                unit_status = "EXPLICIT"
            elif " and " in noncurrency_unit.lower() and all(part.strip().lower() in citation.lower() for part in noncurrency_unit.lower().split(" and ") if part.strip().lower() != "usd"):
                unit_status = "EXPLICIT_COMPOSITE"
            elif noncurrency_unit.lower() == "people" and re.search(r"\b(?:children|adolescents|caregivers|providers|women|persons|people)\b", citation, re.I):
                unit_status = "EXPLICIT_POPULATION_ALIAS"
            elif noncurrency_unit.lower() == "proportion" and re.search(r"\b\d+\s+in\s+\d+\b", citation):
                unit_status = "EXPLICIT_RATIO"
            elif noncurrency_unit.lower() == "rupees and" and "rupees" in citation.lower() and dollar:
                unit_status = "SYMBOL_AMBIGUOUS_COMPOSITE"
            elif noncurrency_unit.lower().rstrip("s") in citation.lower():
                unit_status = "EXPLICIT_VARIANT"
            else:
                unit_status = "ABSENT_FROM_CITATION"
            t = trace[index] if index < len(trace) else {}
            rows.append({"cell": cell, "accepted_index": index, "value": value, "unit": record.get("unit"),
                         "currency_source_category": currency_status, "unit_source_category": unit_status,
                         "key_figure_eligible": t.get("key_figure_eligible"), "provenance_origin": t.get("origin"),
                         "typed_value": t.get("typed", {}).get("value"), "source_span_ids": record.get("evidence_ids", [])})
    populated = [r for r in rows if r["unit"]]
    unsupported = [r for r in rows if r["currency_source_category"] == "UNSUPPORTED" or r["unit_source_category"] == "ABSENT_FROM_CITATION"]
    key_unsupported = [r for r in unsupported if r["key_figure_eligible"]]
    threshold = bool(key_unsupported) or (len(unsupported)/len(populated) >= .02 if populated else False)
    return {"pre_registered_threshold": "review if any unsupported/contradicted key-figure-eligible record OR unsupported/contradicted non-ambiguous fields >=2% of populated accepted quantitative records",
            "quantitative_accepted_record_instances": len(rows), "non_null_unit": len(populated),
            "non_null_currency": sum(bool(r["typed_value"] and r["typed_value"].get("currency")) for r in rows),
            "currency_categories": dict(Counter(r["currency_source_category"] for r in rows)),
            "unit_categories": dict(Counter(r["unit_source_category"] for r in rows)),
            "unsupported_nonambiguous_count": len(unsupported), "unsupported_nonambiguous_rate_populated": len(unsupported)/len(populated) if populated else None,
            "unsupported_key_figure_count": len(key_unsupported), "unit_currency_grounding_review_before_b2": threshold,
            "decision": "MUST_FIX_BEFORE_B2" if threshold else "DEFER",
            "limitation": "Lexical source audit. Contextual units in table headers and all apparent contradictions require human adjudication; ambiguous $ is separate and does not alone trip the threshold.",
            "rows": rows}


def main():
    rows = list(calls())
    ratios = [r["actual_over_planner"] for r in rows]
    save("planner-pressure-calibration.json", {"calls": rows, "median_actual_over_planner": statistics.median(ratios),
        "min_actual_over_planner": min(ratios), "max_actual_over_planner": max(ratios),
        "worst_underprediction": max(rows, key=lambda r: r["actual_over_planner"])["cell"],
        "returned_over_max_records_distribution": sorted(r["returned_over_max_records"] for r in rows),
        "future_overflow_policy": {"max_tokens": "unchanged", "max_records": "observational/soft unless code proves otherwise",
            "returned_above_max_records": "log and preserve", "max_tokens_truncation": "cell failure",
            "parser_failure": "cell failure", "malformed_structured_output": "cell failure",
            "retry": "none without future protocol", "preserve_failed_cell_artifacts": True,
            "log": ["returned/max_records", "output/max_tokens"],
            "expected_after_removing_materiality_selection": "record volume may rise; rise alone is not failure"}})
    save("unit-currency-prevalence.json", unit_audit())


if __name__ == "__main__":
    main()
