"""Offline attribution from four saved provider responses and deterministic replays."""

import collections
import hashlib
import itertools
import json
import pathlib
import re
from decimal import Decimal

BASE = pathlib.Path(__file__).resolve().parent
DIR = BASE / "unicef-4call"
CALLS = ("C1", "C2", "T1", "T2")
number_re = re.compile(r"(?<![A-Za-z])\d[\d,]*(?:\.\d+)?(?![A-Za-z])")


def numbers(value):
    out = []
    for match in number_re.finditer(str(value or "")):
        try:
            out.append(Decimal(match.group().replace(",", "")))
        except Exception:
            pass
    return out


def canonical(value):
    return format(value.normalize(), "f") if value is not None else None


def norm(value):
    return re.sub(r"\s+", " ", str(value or "").strip().casefold())


def scale(text):
    text = norm(text)
    for word, multiplier in (("trillion", 10**12), ("billion", 10**9),
                             ("million", 10**6), ("thousand", 10**3)):
        if re.search(rf"\b{word}s?\b", text):
            return multiplier, word
    return 1, None


def currency(text):
    text = str(text or "")
    if re.search(r"\b(?:USD|US\$)\b|\$", text, re.I):
        return "USD"
    if re.search(r"\bEUR\b|€", text, re.I):
        return "EUR"
    if re.search(r"\bGBP\b|£", text, re.I):
        return "GBP"
    if re.search(r"\bKES\b", text, re.I):
        return "KES"
    return None


def base_identity(record):
    ids = tuple(sorted(str(x).strip().upper() for x in record.get("evidence_ids", []) if isinstance(x, str)))
    raw_value = str(record.get("value") or "")
    unit = str(record.get("unit") or "")
    nums = numbers(raw_value)
    if nums:
        multiplier, scale_name = scale(raw_value + " " + unit)
        money = currency(raw_value + " " + unit)
        percent = bool(re.search(r"%|\b(?:percent|per\s+cent)\b", raw_value + " " + unit, re.I))
        magnitudes = tuple(canonical(x * multiplier) for x in nums)
        return ("Q", ids, magnitudes, scale_name, money, percent)
    return ("N", str(record.get("kind") or ""), ids, norm(raw_value))


def strict_suffix(record):
    return (norm(record.get("label")), norm(record.get("period")), norm(record.get("unit")))


def id_text(value):
    return json.dumps(value, ensure_ascii=False, separators=(",", ":"))


def jaccard(a, b):
    return len(a & b) / len(a | b) if a or b else 1.0


meta, replay = {}, {}
for call in CALLS:
    meta[call] = json.loads((DIR / f"{call}.metadata.json").read_text())
    replay[call] = json.loads((DIR / f"{call}.replay-A.json").read_text())
    assert meta[call]["stop_classification"] == "NORMAL_COMPLETION"
    assert replay[call]["raw_sha256"] == meta[call]["response_sha256"]

collisions = collections.Counter()
for call in CALLS:
    current = collections.Counter(base_identity(t["raw_record"]) for t in replay[call]["trace"])
    for base, count in current.items():
        if count > 1:
            collisions[base] += 1


def primary(record):
    base = base_identity(record)
    return id_text((base, strict_suffix(record)) if base in collisions else base)


def strict(record):
    return id_text((base_identity(record), strict_suffix(record)))


primary_sets = {}
strict_sets = {}
quant_sets = {}
eligible_sets = {}
record_maps = {}
for call in CALLS:
    current = collections.defaultdict(list)
    for t in replay[call]["trace"]:
        key = primary(t["raw_record"])
        t["primary_identity"] = key
        t["strict_identity"] = strict(t["raw_record"])
        current[key].append(t)
    record_maps[call] = current
    primary_sets[call] = set(current)
    strict_sets[call] = {t["strict_identity"] for t in replay[call]["trace"]}
    quant_sets[call] = {k for k, ts in current.items() if base_identity(ts[0]["raw_record"])[0] == "Q"}
    eligible_sets[call] = {k for k, ts in current.items() if any(t["key_figure_eligible"] for t in ts)}

raw_union = set().union(*primary_sets.values())
strict_union = set().union(*strict_sets.values())
quant_union = set().union(*quant_sets.values())
union_trace = {}
attribution_events = collections.Counter()
for identity in sorted(raw_union):
    status = {}
    for call in CALLS:
        entries = record_maps[call].get(identity, [])
        if not entries:
            bucket = "OUTPUT_TRUNCATION" if meta[call]["stop_classification"] == "OUTPUT_TRUNCATION" else "MODEL_OMISSION"
            attribution_events[bucket] += 1
            status[call] = {"raw_present": False, "first_divergence": bucket}
            continue
        accepted = [e for e in entries if e["validated"]]
        if not accepted:
            classes = {e["rejection_class"] for e in entries}
            bucket = "GROUNDING_FAILURE" if classes == {"invalid_evidence"} else "VALIDATOR_REJECTION"
            attribution_events[bucket] += 1
        else:
            bucket = None
        status[call] = {"raw_present": True, "raw_indices": [e["raw_index"] for e in entries],
            "parsed": all(e["parsed"] for e in entries), "validated": bool(accepted),
            "grounded": any(e["grounded"] for e in accepted),
            "typed_values": [e["typed"] for e in accepted],
            "provenance": [e["provenance"] for e in accepted],
            "kinds": [e["kind"] for e in accepted],
            "merge_identities": [e["merge_identity"] for e in accepted],
            "dedupe_collisions": sum(e["dedupe_collision"] for e in accepted),
            "actual_merge_status": "NOT_EVALUABLE: merge persistence and KPI alias resolution were intentionally not run",
            "key_figure_eligible": any(e["key_figure_eligible"] for e in accepted),
            "rejection_reasons": [e["rejection_reason"] for e in entries if not e["validated"]],
            "first_divergence": bucket}
    union_trace[identity] = status

source = json.loads((BASE / "unicef-4call-source-reference.json").read_text())
priority_spec = [
    ("E066", "currency", "230.0"), ("E066", "currency", "124.4"),
    ("E075", "scaled_operational", "7.9"), ("E076", "scaled_operational", "154"),
    ("E077", "scaled_operational", "56"), ("E077", "scaled_operational", "2"),
    ("E095", "currency", "1.584"),
    ("E101", "currency", "512.6"), ("E101", "currency", "724.9"),
    ("E101", "currency", "346.1"),
    ("E101", "percent", "32"), ("E101", "percent", "46"),
    ("E101", "percent", "22"),
    ("E107", "scaled_operational", "10"),
    ("E108", "currency", "1"), ("E109", "numeric_operational", "13"),
    ("E109", "scaled_operational", "8.8"),
    ("E120", "scaled_operational", "59.3"), ("E120", "scaled_operational", "17.9"),
    ("E133", "currency", "952"), ("E137", "currency", "174"),
    ("E137", "currency", "227"), ("E140", "currency", "162"),
    ("E145", "scaled_operational", "2.1"),
    ("E146", "currency", "817.2"),
    ("E173", "percent", "41"), ("E173", "percent", "21"),
    ("E185", "date_time", "48"),
]
priority = []
for span, category, n in priority_spec:
    matching = [r for r in source["candidates"] if r["span"] == span and r["category"] == category
                and Decimal(r["number"]) == Decimal(n)]
    assert matching, (span, category, n)
    priority.append(matching[0])


def source_match(candidate, trace):
    record = trace["raw_record"]
    if candidate["span"] not in [str(x).upper().strip() for x in record.get("evidence_ids", [])]:
        return False
    if candidate["category"] == "obligation_language":
        return record.get("kind") in ("obligation", "deadline")
    expected = Decimal(candidate["number"])
    value = str(record.get("value") or "")
    if candidate["category"] == "date_time":
        value += " " + str(record.get("period") or "") + " " + str(record.get("due_date") or "")
    found = numbers(value)
    source_multiplier = scale(candidate["expression"])[0]
    record_multiplier = scale(value + " " + str(record.get("unit") or ""))[0]
    return any((x * record_multiplier == expected * source_multiplier)
               if candidate["category"] in ("currency", "scaled_operational")
               else (x == expected) for x in found)


def candidate_status(candidate, call):
    matches = [t for t in replay[call]["trace"] if source_match(candidate, t)]
    accepted = [t for t in matches if t["validated"]]
    if accepted:
        return {"raw_present": True, "accepted": True,
                "raw_indices": [t["raw_index"] for t in matches],
                "typed": [t["typed"] for t in accepted],
                "provenance": [t["provenance"] for t in accepted],
                "kinds": [t["kind"] for t in accepted],
                "key_figure_eligible": any(t["key_figure_eligible"] for t in accepted),
                "first_divergence": None}
    if matches:
        bucket = "GROUNDING_FAILURE" if {t["rejection_class"] for t in matches} == {"invalid_evidence"} else "VALIDATOR_REJECTION"
        return {"raw_present": True, "accepted": False,
                "raw_indices": [t["raw_index"] for t in matches], "first_divergence": bucket}
    return {"raw_present": False, "accepted": False,
            "first_divergence": "OUTPUT_TRUNCATION" if meta[call]["stop_classification"] == "OUTPUT_TRUNCATION" else "MODEL_OMISSION"}


priority_traces = []
priority_attribution = collections.Counter()
for candidate in priority:
    states = {call: candidate_status(candidate, call) for call in CALLS}
    priority_attribution.update(state["first_divergence"] for state in states.values() if state["first_divergence"])
    priority_traces.append({"candidate": candidate, "calls": states,
                            "raw_frequency": sum(x["raw_present"] for x in states.values()),
                            "accepted_frequency": sum(x["accepted"] for x in states.values())})

source_traces = []
for candidate in source["candidates"]:
    states = {call: candidate_status(candidate, call) for call in CALLS}
    source_traces.append({"candidate": candidate, "calls": states})

comparisons = {}
for left, right in (("C1", "C2"), ("T1", "T2")):
    comparisons[f"{left}_{right}"] = {
        "primary_jaccard": jaccard(primary_sets[left], primary_sets[right]),
        "strict_jaccard": jaccard(strict_sets[left], strict_sets[right]),
        "quantitative_jaccard": jaccard(quant_sets[left], quant_sets[right]),
        "key_figure_eligible_jaccard": jaccard(eligible_sets[left], eligible_sets[right]),
        "primary_intersection": len(primary_sets[left] & primary_sets[right]),
        "primary_union": len(primary_sets[left] | primary_sets[right]),
        "quant_intersection": len(quant_sets[left] & quant_sets[right]),
        "quant_union": len(quant_sets[left] | quant_sets[right]),
    }

per_call = {}
for call in CALLS:
    validation = replay[call]["aggregate_validation"]
    recovered = sum(x["calls"][call]["accepted"] for x in source_traces)
    important = sum(x["calls"][call]["accepted"] for x in priority_traces)
    per_call[call] = {"metadata": meta[call], "raw_primary_count": len(primary_sets[call]),
        "raw_strict_count": len(strict_sets[call]), "raw_quantitative_count": len(quant_sets[call]),
        "accepted_count": replay[call]["accepted_count"],
        "rejection_count": validation["records_dropped"],
        "rejection_rate": validation["records_dropped"] / validation["records_returned"],
        "validation": validation, "key_figure_eligible_count": len(eligible_sets[call]),
        "source_proxy_recovered": recovered, "source_proxy_total": len(source_traces),
        "source_proxy_recovery_rate": recovered / len(source_traces),
        "important_recovered": important, "important_total": len(priority_traces),
        "important_recovery_rate": important / len(priority_traces)}

usage = {key: sum((meta[c].get(key) or 0) for c in CALLS) for key in
         ("input_tokens", "cache_creation_input_tokens", "cache_read_input_tokens", "output_tokens")}
actual_estimate = (usage["input_tokens"] * 1 + usage["cache_creation_input_tokens"] * 1.25
                   + usage["cache_read_input_tokens"] * 0.10 + usage["output_tokens"] * 5) / 1_000_000

control_union = primary_sets["C1"] | primary_sets["C2"]
t0_union = primary_sets["T1"] | primary_sets["T2"]
control_priority = {i for i, row in enumerate(priority_traces)
                    if row["calls"]["C1"]["accepted"] or row["calls"]["C2"]["accepted"]}
t0_priority = {i for i, row in enumerate(priority_traces)
               if row["calls"]["T1"]["accepted"] or row["calls"]["T2"]["accepted"]}
drift = collections.Counter()
earliest_downstream = collections.Counter()
for identity, states in union_trace.items():
    comparable = [v for v in states.values() if v.get("raw_present") and v.get("validated")]
    if len(comparable) < 2:
        continue
    kind_changed = len({str(v["kinds"]) for v in comparable}) > 1
    provenance_changed = len({str([p.get("origin") for p in v["provenance"]]) for v in comparable}) > 1
    eligibility_changed = len({v["key_figure_eligible"] for v in comparable}) > 1
    merge_changed = len({str(v["merge_identities"]) for v in comparable}) > 1
    if kind_changed:
        drift["kind"] += 1
    if provenance_changed:
        drift["provenance_origin"] += 1
    if eligibility_changed:
        drift["key_figure_eligibility"] += 1
    typed_core = []
    for v in comparable:
        typed_core.append(str([((t or {}).get("value") or {}).get(field)
                               for t in v["typed_values"]
                               for field in ("type", "number", "scale", "currency", "unit_kind")] ))
    typed_changed = len(set(typed_core)) > 1
    if typed_changed:
        drift["typed_value_core"] += 1
    if merge_changed:
        drift["premerge_identity"] += 1
    # The provider supplied different raw fields for any such stage change; this is
    # the first different downstream outcome, not hidden downstream nondeterminism.
    raw_variants = {id_text(record_maps[call][identity][0]["raw_record"])
                    for call in CALLS if identity in record_maps[call]}
    earliest = ("CLASSIFICATION_DRIFT" if kind_changed else
                "TYPED_VALUE_DRIFT" if typed_changed else
                "PROVENANCE_DRIFT" if provenance_changed else
                "NOT_EVALUABLE" if merge_changed else
                "SELECTOR_ELIGIBILITY_DRIFT" if eligibility_changed else None)
    states["_cross_call_analysis"] = {"raw_field_variation": len(raw_variants) > 1,
        "first_different_downstream_outcome": earliest,
        "actual_merge_evaluable": False}
    if earliest:
        earliest_downstream[earliest] += 1

priority_currency_raw = 0
priority_currency_unknown = 0
for row in priority_traces:
    if row["candidate"]["category"] != "currency":
        continue
    for state in row["calls"].values():
        if state["raw_present"] and state["accepted"]:
            priority_currency_raw += 1
            priority_currency_unknown += any((p or {}).get("origin") != "document"
                                             for p in state.get("provenance", []))
replay_a = (DIR / "C1.replay-A.json").read_bytes()
replay_b = (DIR / "C1.replay-B.json").read_bytes()
response_t1 = json.loads((DIR / "T1.response.json").read_text())
response_t2 = json.loads((DIR / "T2.response.json").read_text())

out = {"scope": "one diagnostic subchunk E033-E186, pages 3-12; n=2 per arm",
    "identity_rule": "Primary quantitative: sorted cited span IDs, canonical numeric magnitude(s), scale, currency, percent flag; label/period/unit only for base-identity collisions in a call. Nonnumeric: kind, sorted cited spans, normalized value. Strict adds normalized label/period/unit always.",
    "fixed_as_of": "2026-10-09T00:00:00+03:00",
    "raw_union_count": len(raw_union), "strict_union_count": len(strict_union),
    "quantitative_raw_union_count": len(quant_union),
    "per_call": per_call, "comparisons": comparisons, "usage_totals": usage,
    "actual_estimated_cost_usd": round(actual_estimate, 6),
    "c1_replay_determinism": {"identical": replay_a == replay_b,
        "replay_a_sha256": hashlib.sha256(replay_a).hexdigest(),
        "replay_b_sha256": hashlib.sha256(replay_b).hexdigest()},
    "t0_provider_content_identical": response_t1.get("content") == response_t2.get("content"),
    "cross_arm": {"control_union": len(control_union), "t0_union": len(t0_union),
        "shared": len(control_union & t0_union), "control_only": len(control_union - t0_union),
        "t0_only": len(t0_union - control_union), "t0_to_control_union_ratio": len(t0_union) / len(control_union),
        "control_priority_union": len(control_priority), "t0_priority_union": len(t0_priority),
        "priority_control_only": [priority_traces[i]["candidate"] for i in sorted(control_priority - t0_priority)],
        "priority_t0_only": [priority_traces[i]["candidate"] for i in sorted(t0_priority - control_priority)]},
    "shared_identity_downstream_drift_counts": dict(drift),
    "first_different_downstream_outcome_counts": dict(earliest_downstream),
    "priority_currency_projection": {"raw_accepted_occurrences": priority_currency_raw,
        "origin_unknown_occurrences": priority_currency_unknown},
    "attribution_identity_call_events": {name: attribution_events[name] for name in
        ("MODEL_OMISSION", "OUTPUT_TRUNCATION", "PARSER_LOSS", "VALIDATOR_REJECTION",
         "GROUNDING_FAILURE", "PROVENANCE_DRIFT", "TYPED_VALUE_DRIFT", "CLASSIFICATION_DRIFT",
         "DEDUPE_MERGE_DRIFT", "SELECTOR_ELIGIBILITY_DRIFT", "NOT_EVALUABLE")},
    "priority_attribution_events": {name: priority_attribution[name] for name in
        ("MODEL_OMISSION", "OUTPUT_TRUNCATION", "PARSER_LOSS", "VALIDATOR_REJECTION",
         "GROUNDING_FAILURE", "PROVENANCE_DRIFT", "TYPED_VALUE_DRIFT", "CLASSIFICATION_DRIFT",
         "DEDUPE_MERGE_DRIFT", "SELECTOR_ELIGIBILITY_DRIFT", "NOT_EVALUABLE")},
    "actual_merge_evaluable": False,
    "priority_source_claims": priority_traces,
    "source_observable_traces": source_traces,
    "raw_union_identity_traces": union_trace,
    "all_call_priority_omissions": [x["candidate"] for x in priority_traces if x["raw_frequency"] == 0],
    "all_call_source_proxy_omissions": [x["candidate"] for x in source_traces if not any(y["raw_present"] for y in x["calls"].values())],
}
(BASE / "unicef-4call-root-cause-audit.json").write_text(json.dumps(out, indent=2, ensure_ascii=False) + "\n")
summary = {key: out[key] for key in ("raw_union_count", "strict_union_count", "quantitative_raw_union_count",
                                  "comparisons", "usage_totals", "actual_estimated_cost_usd",
                                  "attribution_identity_call_events", "priority_attribution_events",
                                  "c1_replay_determinism", "t0_provider_content_identical", "cross_arm",
                                  "shared_identity_downstream_drift_counts", "priority_currency_projection")}
summary["first_different_downstream_outcome_counts"] = out["first_different_downstream_outcome_counts"]
summary["per_call"] = {call: {k: v for k, v in row.items() if k not in ("metadata", "validation")}
                       for call, row in per_call.items()}
summary["all_call_priority_omissions"] = out["all_call_priority_omissions"]
print(json.dumps(summary, indent=2, ensure_ascii=False))
