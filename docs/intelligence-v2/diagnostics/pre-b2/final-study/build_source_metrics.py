"""Offline Candidate Inventory scoring and frozen-item review worksheet."""
import json
import pathlib
import sys
from collections import Counter

BASE = pathlib.Path(__file__).resolve().parent
PRE = BASE.parent
DIAG = PRE.parent
STUDY = DIAG / "experiment-flags"
sys.path.insert(0, str(PRE))
from candidate_inventory import DETECTOR_VERSION, MATCHER_VERSION, represent


def save(name, value):
    (BASE / name).write_text(json.dumps(value, indent=2, ensure_ascii=False)+"\n")


def main():
    inv = json.loads((PRE / "candidate-inventory.json").read_text())["chunks"]
    assert DETECTOR_VERSION == json.loads((PRE / "version-freeze-plan.json").read_text())["candidate_inventory_detector_version"]
    output = {}
    gold_packet = {}
    neg_packet = {}
    for run in range(1,6):
        for chunk in ("unicef_reduced", "india_wash"):
            gold = json.loads((STUDY / f"{chunk}.gold-items.json").read_text())
            negative = json.loads((STUDY / f"{chunk}.negative-items.json").read_text())
            for arm in "AB":
                cell = f"{chunk}-{arm}{run}"
                accepted = json.loads((BASE / "analysis" / f"{cell}.accepted.json").read_text())
                response = json.loads((BASE / f"{cell}.response.json").read_text())
                raw = json.loads(response["content"][0]["text"])["records"]
                trace = json.loads((BASE / "analysis" / f"{cell}.replay.json").read_text())["trace"]
                rejected = [{"evidence_ids":raw[t["raw_index"]].get("evidence_ids", []),
                             "rejection_reason":t.get("rejection_reason") or t.get("rejection_class")}
                            for t in trace if not t.get("accepted")]
                matched = represent(inv[chunk], accepted, rejected)
                by_class = {}
                for cls in ("percentage", "currency", "scaled_quantity"):
                    eligible = [r for r in matched if r["headline_eligible"] and r["candidate_class"] == cls]
                    counts = Counter(r["status"] for r in eligible)
                    by_class[cls] = {"represented":counts["REPRESENTED"], "denominator":len(eligible),
                        "statuses":{s:counts[s] for s in ("REPRESENTED","UNREPRESENTED","INDETERMINATE","MENTIONED_IN_TEXT_ONLY")},
                        "rate":counts["REPRESENTED"]/len(eligible) if eligible else None}
                eligible = [r for r in matched if r["headline_eligible"]]
                output[cell] = {"chunk":chunk,"arm":arm,"detector_version":DETECTOR_VERSION,"matcher_version":MATCHER_VERSION,
                    "by_class":by_class,"headline_represented":sum(r["status"]=="REPRESENTED" for r in eligible),
                    "headline_denominator":len(eligible),"headline_rate":sum(r["status"]=="REPRESENTED" for r in eligible)/len(eligible),
                    "ambiguous_chart_exclusions":sum(r["chart_or_ocr_ambiguous"] and r["candidate_class"] in ("percentage","currency","scaled_quantity") for r in matched),
                    "diagnostic_only_count":sum(r["candidate_class"].startswith("diagnostic") for r in matched),
                    "candidates":matched}
                def packet(items):
                    rows = []
                    for ordinal, item in enumerate(items, 1):
                        cited = [dict(accepted_index=i, label=r.get("label"),value=r.get("value"),kind=r.get("kind"),period=r.get("period"),
                                      evidence_ids=r.get("evidence_ids")) for i,r in enumerate(accepted)
                                 if set(item["evidence_ids"]) & set(r.get("evidence_ids", []))]
                        rows.append({"ordinal":ordinal,"frozen_item":item,"cited_accepted":cited,
                                     "matched":None,"accepted_indices":[],"review_note":None})
                    return rows
                gold_packet[cell] = packet(gold)
                neg_packet[cell] = packet(negative)
    save("candidate-representation-results.json",{"metric":"EXPLICIT QUANTITY REPRESENTATION","cells":output})
    save("gold-review-worksheet.json",gold_packet)
    save("negative-review-worksheet.json",neg_packet)
    print("cells",len(output),"gold rows",sum(map(len,gold_packet.values())),"negative rows",sum(map(len,neg_packet.values())))


if __name__ == "__main__":main()
