"""Frozen offline final-study comparisons. No provider access."""
import json
import pathlib
import statistics
from collections import Counter, defaultdict
from decimal import Decimal

BASE = pathlib.Path(__file__).resolve().parent
ANALYSIS = BASE / "analysis"
PRE = BASE.parent
GOLD = json.loads((BASE / "gold-review.json").read_text())
NEGATIVE = json.loads((BASE / "negative-review.json").read_text())
REP = json.loads((BASE / "candidate-representation-results.json").read_text())["cells"]
PRECISION = json.loads((BASE / "precision-review/precision-review-unblinded.json").read_text())
PLAN = json.loads((PRE / "minimal-prompt-experiment-plan.json").read_text())


def mean(xs):
    return statistics.mean(xs)


def jaccard(a,b):
    union = a|b
    return len(a&b)/len(union) if union else 1.0


def main():
    cells = {}
    for run in range(1,6):
        for chunk in ("unicef_reduced","india_wash"):
            for arm in "AB":
                cell = f"{chunk}-{arm}{run}"
                meta = json.loads((BASE / f"{cell}.metadata.json").read_text())
                replay = json.loads((ANALYSIS / f"{cell}.replay.json").read_text())
                returned = replay["returned_count"]
                accepted = replay["accepted_count"]
                rejected = replay["rejected_count"]
                reject_reasons = replay["validation"].get("rejections") or {}
                grounding = sum(n for name,n in reject_reasons.items() if "evidence" in name or "ground" in name)
                typed = Counter()
                identities = set()
                for t in replay["trace"]:
                    if t.get("accepted"):
                        identities.add(t["identity"])
                        value = (t.get("typed") or {}).get("value")
                        if value is not None:
                            typed[value.get("type") or "unknown"] += 1
                represented_ids = {r["candidate_id"] for r in REP[cell]["candidates"] if r["headline_eligible"] and r["status"] == "REPRESENTED"}
                cells[cell] = {"chunk":chunk,"arm":arm,"run":run,
                    "gold_recalled":sum(x["matched"] for x in GOLD[cell]),"gold_total":len(GOLD[cell]),
                    "negative_matches":sum(x["matched"] for x in NEGATIVE[cell]),"negative_total":len(NEGATIVE[cell]),
                    "negative_false_positive_rate":sum(x["matched"] for x in NEGATIVE[cell])/len(NEGATIVE[cell]),
                    "sampled_supported_precision":PRECISION["by_cell"][cell]["supported_precision"],
                    "precision_sample_n":PRECISION["by_cell"][cell]["sampled"],
                    "returned":returned,"accepted":accepted,"rejected":rejected,"rejection_rate":rejected/returned,
                    "rejection_reasons":replay["validation"].get("rejection_reasons") or {},
                    "grounding_failures":grounding,"grounding_failure_rate":grounding/returned,
                    "parser_failure":meta["failure_class"]=="PARSER_FAILURE",
                    "structured_output_failure":meta["failure_class"]=="STRUCTURED_OUTPUT_FAILURE",
                    "truncated":meta["failure_class"]=="MAX_TOKENS_TRUNCATION",
                    "provider_failure":meta["status"]!="success",
                    "returned_over_max_records":meta["returned_over_max_records"],
                    "output_over_max_tokens":meta["output_over_max_tokens"],
                    "input_tokens":meta["usage"]["input_tokens"],"output_tokens":meta["usage"]["output_tokens"],
                    "actual_cost_usd":meta["actual_cost_usd"],
                    "provenance_distribution":replay["origin"],
                    "typed_evidence_counts":dict(typed),
                    "key_figure_eligible_count":replay["key_figure_eligible_count"],
                    "representation_by_class":REP[cell]["by_class"],
                    "headline_represented":REP[cell]["headline_represented"],
                    "headline_denominator":REP[cell]["headline_denominator"],
                    "headline_rate":REP[cell]["headline_rate"],
                    "_candidate_ids":represented_ids,"_identities":identities}
    groups = {}
    for chunk in ("unicef_reduced","india_wash"):
        groups[chunk] = {}
        for arm in "AB":
            xs = [cells[f"{chunk}-{arm}{n}"] for n in range(1,6)]
            precision = PRECISION["by_chunk_variant"][f"{chunk}:{'CONTROL' if arm=='A' else 'CONTROL_MINUS_MATERIALITY'}"]
            groups[chunk][arm] = {"mean_headline_representation":mean(x["headline_rate"] for x in xs),
                "represented_sum":sum(x["headline_represented"] for x in xs),
                "headline_denominator_sum":sum(x["headline_denominator"] for x in xs),
                "mean_gold_recalled":mean(x["gold_recalled"] for x in xs),
                "mean_gold_recall_rate":mean(x["gold_recalled"]/x["gold_total"] for x in xs),
                "supported_precision":precision["supported_precision"],
                "precision_sample_n":precision["sampled"],
                "mean_negative_false_positive_rate":mean(x["negative_false_positive_rate"] for x in xs),
                "mean_rejection_rate":mean(x["rejection_rate"] for x in xs),
                "pooled_rejection_rate":sum(x["rejected"] for x in xs)/sum(x["returned"] for x in xs),
                "mean_grounding_failure_rate":mean(x["grounding_failure_rate"] for x in xs),
                "mean_returned":mean(x["returned"] for x in xs),"mean_accepted":mean(x["accepted"] for x in xs),
                "mean_input_tokens":mean(x["input_tokens"] for x in xs),"mean_output_tokens":mean(x["output_tokens"] for x in xs),
                "mean_cost_usd":str(sum(Decimal(x["actual_cost_usd"]) for x in xs)/5),
                "total_cost_usd":str(sum(Decimal(x["actual_cost_usd"]) for x in xs)),
                "provenance_document":sum(x["provenance_distribution"].get("document",0) for x in xs),
                "provenance_unknown":sum(x["provenance_distribution"].get("unknown",0) for x in xs),
                "typed_evidence_counts":dict(sum((Counter(x["typed_evidence_counts"]) for x in xs),Counter())),
                "key_figure_eligible_count":sum(x["key_figure_eligible_count"] for x in xs),
                "truncated_calls":sum(x["truncated"] for x in xs),
                "parser_failures":sum(x["parser_failure"] for x in xs),
                "structured_output_failures":sum(x["structured_output_failure"] for x in xs)}
    paired = {}
    for chunk in groups:
        paired[chunk] = []
        for n in range(1,6):
            a,b = cells[f"{chunk}-A{n}"], cells[f"{chunk}-B{n}"]
            paired[chunk].append({"run":n,"representation_delta_pp":100*(b["headline_rate"]-a["headline_rate"]),
                "gold_delta_items":b["gold_recalled"]-a["gold_recalled"],
                "returned_delta":b["returned"]-a["returned"],
                "accepted_delta":b["accepted"]-a["accepted"],
                "output_token_delta":b["output_tokens"]-a["output_tokens"],
                "cost_delta_usd":str(Decimal(b["actual_cost_usd"])-Decimal(a["actual_cost_usd"])),
                "cost_pair_usd":str(Decimal(b["actual_cost_usd"])+Decimal(a["actual_cost_usd"])),
                "representation_insensitive_candidate_jaccard":jaccard(a["_candidate_ids"],b["_candidate_ids"]),
                "merger_identity_jaccard":jaccard(a["_identities"],b["_identities"])})
    variants = {}
    for arm in "AB":
        xs=[x for x in cells.values() if x["arm"]==arm]
        variants[arm]={"balanced_mean_headline_representation":mean(x["headline_rate"] for x in xs),
            "pooled_headline_represented":sum(x["headline_represented"] for x in xs),
            "pooled_headline_denominator":sum(x["headline_denominator"] for x in xs),
            "mean_gold_recalled_per_chunk_call":mean(x["gold_recalled"] for x in xs),
            "total_gold_item_instances_recalled":sum(x["gold_recalled"] for x in xs),
            "total_cost_usd":str(sum(Decimal(x["actual_cost_usd"]) for x in xs)),
            "mean_returned":mean(x["returned"] for x in xs),"mean_accepted":mean(x["accepted"] for x in xs),
            "mean_output_tokens":mean(x["output_tokens"] for x in xs),
            "mean_input_tokens":mean(x["input_tokens"] for x in xs),
            "pooled_rejection_rate":sum(x["rejected"] for x in xs)/sum(x["returned"] for x in xs),
            "supported_precision_pooled":sum(PRECISION["by_chunk_variant"][f"{c}:{'CONTROL' if arm=='A' else 'CONTROL_MINUS_MATERIALITY'}"]["judgments"].get("SUPPORTED",0) for c in groups)/sum(PRECISION["by_chunk_variant"][f"{c}:{'CONTROL' if arm=='A' else 'CONTROL_MINUS_MATERIALITY'}"]["sampled"] for c in groups),
            "by_class":{cls:{"represented":sum(x["representation_by_class"][cls]["represented"] for x in xs),
                "denominator":sum(x["representation_by_class"][cls]["denominator"] for x in xs)}
                for cls in ("percentage","currency","scaled_quantity")}}
    extra_cost=Decimal(variants["B"]["total_cost_usd"])-Decimal(variants["A"]["total_cost_usd"])
    extra_candidates=variants["B"]["pooled_headline_represented"]-variants["A"]["pooled_headline_represented"]
    extra_gold=variants["B"]["total_gold_item_instances_recalled"]-variants["A"]["total_gold_item_instances_recalled"]
    cost={"actual_total_study_cost_usd":str(sum(Decimal(x["actual_cost_usd"]) for x in cells.values())),
        "max_chunk_pair_cost_usd":str(max(Decimal(x["cost_pair_usd"]) for ps in paired.values() for x in ps)),
        "variant_b_incremental_cost_usd":str(extra_cost),
        "variant_b_relative_cost_increase":float(extra_cost/Decimal(variants["A"]["total_cost_usd"])),
        "net_additional_represented_candidate_instances":extra_candidates,
        "net_additional_gold_item_instances":extra_gold,
        "cost_per_net_additional_represented_candidate_usd":str(extra_cost/extra_candidates) if extra_candidates>0 and extra_cost>0 else None,
        "cost_per_net_additional_gold_item_usd":str(extra_cost/extra_gold) if extra_gold>0 and extra_cost>0 else None,
        "approved_limits_usd":PLAN["budget_proposed_requires_founder_approval"]}
    for cell in cells:
        cells[cell].pop("_candidate_ids");cells[cell].pop("_identities")
    result={"cells":cells,"groups":groups,"variants":variants,"paired":paired,"cost":cost,
        "precision_judgments_sha256":PRECISION["judgments_sha256"],
        "interpretation":"DIRECTIONAL: two chunks from one source document"}
    (BASE / "study-metrics.json").write_text(json.dumps(result,indent=2,ensure_ascii=False)+"\n")
    print("representation",variants["A"]["balanced_mean_headline_representation"],variants["B"]["balanced_mean_headline_representation"])
    print("gold",{c:(groups[c]["A"]["mean_gold_recalled"],groups[c]["B"]["mean_gold_recalled"]) for c in groups})
    print("cost",cost)


if __name__ == "__main__":main()
