"""Additional bounded count-only measurements; no generation endpoint."""
import copy
import json
from collections import defaultdict
from measure_compact import HERE, DIAG, NULLABLE, count, dump


def main():
    gate_path = HERE / "compact-format-token-gate.json"
    gate = json.loads(gate_path.read_text())
    if gate["status"] not in ("MEASURED", "APPROXIMATE"):
        raise RuntimeError("base token gate not measured")
    state = {"requests": gate["token_count_requests"] + 1}  # one earlier failed empty-content request
    paths = sorted((DIAG / "unicef-4call").glob("*.response.json")) + sorted((DIAG / "experiment-flags/paid-study").glob("*.response.json"))
    records = [r for p in paths for r in json.loads(json.loads(p.read_text())["content"][0]["text"])["records"]]
    assert len(records) == 912
    overhead = 7
    def tokens(x):
        return count(dump(x), state)-overhead
    baseline = tokens({"records": records})
    omit_null = tokens({"records": [{k:v for k,v in r.items() if not(k in NULLABLE and v is None)} for r in records]})
    no_reference = tokens({"records": [{k:("" if k=="reference" else v) for k,v in r.items()} for r in records]})
    skeleton = tokens({"records": [{k:(None if v is None else [] if isinstance(v,list) else 0 if isinstance(v,(int,float)) else "") for k,v in r.items()} for r in records]})
    families = {"value": ["value"], "reference": ["reference"], "identity": ["label","subject","period","unit","kind"],
                "metadata": ["entity_type","date_type","due_date","severity","metric_type","value_basis","aggregation","quantity_kind","confidence","aliases","evidence_ids"]}
    family_shares = {}
    for name, keys in families.items():
        blank = [{k:(None if v is None else [] if isinstance(v,list) else 0 if isinstance(v,(int,float)) else "") if k in keys else v for k,v in r.items()} for r in records]
        family_shares[name] = baseline-tokens({"records":blank})
    kinds = defaultdict(list)
    for r in records:
        kinds[r["kind"]].append(r)
    kind_counts = {kind:{"records":len(rows),"tokens":tokens({"records":rows})} for kind,rows in sorted(kinds.items())}
    gate["field_breakdown"] = {"canonical_reserialized_tokens":baseline,
        "reference_value_tokens_estimate":baseline-no_reference,
        "reference_share_of_reserialized_current":(baseline-no_reference)/baseline,
        "schema_scaffolding_tokens_estimate":skeleton,
        "schema_scaffolding_share_estimate":skeleton/baseline,
        "omitted_null_savings_tokens":baseline-omit_null,
        "shorter_key_savings_tokens":gate["variants"]["readable"]["tokens"]-gate["variants"]["short"]["tokens"],
        "field_family_token_contribution_estimate":family_shares,
        "by_evidence_kind":kind_counts,
        "method_note":"Token subtraction after blanking values is an estimate; tokenizer boundaries make shares non-additive. Skeleton counts punctuation, keys, and empty-value markers."}
    gate["token_count_requests"] = state["requests"]
    gate_path.write_text(json.dumps(gate, indent=2, ensure_ascii=False)+"\n")
    cal_path = HERE / "compact-token-calibration.json"
    cal = json.loads(cal_path.read_text());cal["requests_used_total_including_prior_failed_request"] = state["requests"]
    cal_path.write_text(json.dumps(cal, indent=2)+"\n")
    print("total count requests including failed:",state["requests"],"reference share:",gate["field_breakdown"]["reference_share_of_reserialized_current"])


if __name__ == "__main__":main()
