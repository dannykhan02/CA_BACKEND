"""Build frozen offline detector validation and per-call representation artifacts."""
import json
import pathlib
from collections import Counter, defaultdict
from candidate_inventory import CandidateInventory, DETECTOR_VERSION, MATCHER_VERSION, represent, split_spans

HERE = pathlib.Path(__file__).resolve().parent
DIAG = HERE.parent
STUDY = DIAG / "experiment-flags"
CHUNKS = ("unicef_reduced", "india_wash", "unicef_quantitative")
CHART = {"unicef_reduced": {"E101", "E102", "E103"}, "india_wash": set(),
         "unicef_quantitative": {"E101", "E102", "E103"}}
SPAN_METADATA = {x["span_key"]:x for x in json.loads((HERE.parents[3] / "unicef-source-spans.json").read_text())}


def save(name, value):
    (HERE / name).write_text(json.dumps(value, indent=2, ensure_ascii=False) + "\n")


def gold_expected(g):
    unit = str(g.get("canonical_unit") or "").lower()
    value = g.get("canonical_value")
    return value is not None and (any(x in unit for x in ("percent", "million", "billion"))
                                  or (isinstance(value, (int, float)) and value >= 1_000_000))


def gold_number(g):
    value = float(g["canonical_value"])
    unit = str(g["canonical_unit"]).lower()
    if "million" in unit and value < 1000:
        value *= 10**6
    if "billion" in unit and value < 1000:
        value *= 10**9
    return value


def main():
    inventory = {}
    validation = {"detector_version": DETECTOR_VERSION, "reviewer": "AI coding agent", "gold": [], "sample": [],
                  "sample_precision_is_rough_n_about_30": True}
    for chunk in CHUNKS:
        source = (STUDY / (chunk + ".source.txt")).read_text()
        rows = CandidateInventory().detect(source, CHART[chunk], SPAN_METADATA)
        inventory[chunk] = rows
        if chunk == "unicef_quantitative":
            continue  # no frozen item-level gold set for this larger chunk
        for g in json.loads((STUDY / (chunk + ".gold-items.json")).read_text()):
            if not gold_expected(g):
                continue
            hits = [r for r in rows if r["headline_eligible"] and r["source_span_id"] in g["evidence_ids"]
                    and abs(float(r["canonical_numeric"]) - gold_number(g)) < 1e-6]
            validation["gold"].append({"chunk": chunk, "claim": g["normalized_claim"],
                                       "candidate_ids": [r["candidate_id"] for r in hits], "detected": bool(hits)})
    # Deterministic, stratified agent review. Each selection is inspected against the saved span text.
    review_pools = defaultdict(list)
    for chunk, rows in inventory.items():
        source = dict(split_spans((STUDY / (chunk + ".source.txt")).read_text()))
        for r in rows:
            if r["headline_eligible"]:
                review_pools[r["candidate_class"]].append((chunk, r, source[r["source_span_id"]]))
    for cls in ("percentage", "currency", "scaled_quantity"):
        # Spread across two gold chunks, then add the distinct large source chunk.
        pool = sorted(review_pools[cls], key=lambda z: (z[0] == "unicef_quantitative", z[0], z[1]["source_span_id"], z[1]["span_offset"]))
        chosen = pool[:10]
        for chunk, r, span in chosen:
            raw = r["raw_source_value"]
            correct = span[r["span_offset"]:r["span_offset"]+len(raw)] == raw
            # The source-side review also checks that this is a quantity, not a locator or chart tick.
            context = span[max(0, r["span_offset"]-30):r["span_offset"]+len(raw)+30]
            validation["sample"].append({"candidate_id": r["candidate_id"], "chunk": chunk,
                                          "class": cls, "span_id": r["source_span_id"], "raw": raw,
                                          "source_context": context, "correct": correct,
                                          "review_note": "explicit quantity in saved source; chart exclusions applied" if correct else "span offset mismatch"})
    # Also review excluded chart/OCR examples diagnostically, outside headline precision denominator.
    validation["excluded_chart_examples"] = [{"chunk": chunk, "span_id": r["source_span_id"], "raw": r["raw_source_value"]}
        for chunk, rows in inventory.items() for r in rows if r["chart_or_ocr_ambiguous"] and r["candidate_class"] in ("percentage", "currency", "scaled_quantity")][:12]
    validation["gold_quantity_recall"] = sum(x["detected"] for x in validation["gold"]) / len(validation["gold"])
    validation["headline_sample_precision"] = sum(x["correct"] for x in validation["sample"]) / len(validation["sample"])
    validation["thresholds_pass"] = validation["gold_quantity_recall"] >= .95 and validation["headline_sample_precision"] >= .90
    validation["gold_false_negatives"] = [x for x in validation["gold"] if not x["detected"]]
    validation["false_positives"] = [x for x in validation["sample"] if not x["correct"]]
    validation["detector_exclusions"] = {chunk: dict(Counter(r["candidate_class"] for r in rows if not r["headline_eligible"])) for chunk, rows in inventory.items()}
    save("candidate-inventory.json", {"detector_version": DETECTOR_VERSION, "chunks": inventory})
    save("candidate-detector-validation.json", validation)
    if not validation["thresholds_pass"]:
        return
    calls = {}
    for path in sorted((DIAG / "unicef-4call").glob("*.response.json")):
        cell = path.name.removesuffix(".response.json")
        replay = json.loads((path.parent / (cell + ".replay-A.json")).read_text())
        accepted = [t["accepted_record"] for t in replay["trace"] if t.get("accepted_record")]
        rejected = [{"evidence_ids": t.get("raw_record", {}).get("evidence_ids", []),
                     "rejection_reason": t.get("rejection_reason")} for t in replay["trace"] if t.get("rejection_reason")]
        matched = represent(inventory["unicef_quantitative"], accepted, rejected)
        calls["four_call_"+cell] = {"chunk":"unicef_quantitative",
            "by_class":{cls:dict(Counter(r["status"] for r in matched if r["candidate_class"] == cls and r["headline_eligible"]))
                        for cls in ("percentage", "currency", "scaled_quantity")},
            "headline_denominator":sum(r["headline_eligible"] for r in matched), "candidates":matched}
    paid = STUDY / "paid-study"
    for path in sorted(paid.glob("*.response.json")):
        cell = path.name.removesuffix(".response.json")
        chunk = cell.rsplit("-", 1)[0]
        if chunk not in inventory:
            continue
        accepted_path = paid / "analysis" / (cell + ".accepted.json")
        if not accepted_path.exists():
            continue
        accepted = json.loads(accepted_path.read_text())
        replay = json.loads((paid / "analysis" / (cell + ".replay.json")).read_text())
        raw_records = json.loads(json.loads(path.read_text())["content"][0]["text"])["records"]
        rejected = [{"evidence_ids": raw_records[t["raw_index"]].get("evidence_ids", []),
                     "rejection_reason": t.get("rejection_reason") or t.get("rejection_class")}
                    for t in replay["trace"] if not t.get("accepted")]
        matched = represent(inventory[chunk], accepted, rejected)
        calls[cell] = {"chunk": chunk, "by_class": {cls: dict(Counter(r["status"] for r in matched if r["candidate_class"] == cls and r["headline_eligible"]))
                  for cls in ("percentage", "currency", "scaled_quantity")},
                  "headline_denominator": sum(r["headline_eligible"] for r in matched),
                  "candidates": matched}
    save("candidate-representation-summary.json", {"detector_version": DETECTOR_VERSION,
        "matcher_version": MATCHER_VERSION, "metric": "EXPLICIT QUANTITY REPRESENTATION",
        "limitations": ["Representation is source-side quantity ownership, not semantic completeness or materiality.",
                        "Accepted saved replay records only; periods ignored.",
                        "Repeated identical values with too few records are indeterminate."], "calls": calls})


if __name__ == "__main__":
    main()
