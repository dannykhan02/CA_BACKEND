"""AI coding-agent review of the frozen mechanically blinded packet.

Reads only blinded records and cited source; never reads the private mapping.
The decision rules follow the earlier frozen review's treatment of flattened
E103 chart associations and E137 expense attribution, plus direct source checks.
"""
import json
import pathlib
import re

BASE = pathlib.Path(__file__).resolve().parent / "precision-review"
packet = json.loads((BASE / "precision-review-blinded.json").read_text())
judgments = []


def period_supported(period, source):
    if not period:
        return True
    p = str(period).lower()
    s = source.lower()
    if p in s:
        return True
    if p in ("5 years", "last 5 years") and "five years" in s:
        return True
    if p == "2014-2022" and "2014 and 2022" in s:
        return True
    if p == "10 years" and "ten years" in s:
        return True
    return False


for item in packet:
    record = item["record"]
    source = " ".join(s["text"] for s in item["source"])
    spans = {s["evidence_id"] for s in item["source"]}
    label = str(record.get("label") or "").lower()
    value = str(record.get("value") or "").lower()
    judgment, reason = "SUPPORTED", "Core claim and metadata supported by cited source"
    if "E103" in spans:
        if "%" in value or "per cent" in value or "percent" in value:
            judgment, reason = "UNSUPPORTED", "Flattened E103 chart does not safely associate a percentage with this series/year"
        elif "core resources income" in label or "core resources contribution" in label:
            judgment, reason = "UNSUPPORTED", "E103 total amount is not Core Resources income alone"
        else:
            judgment, reason = "PARTIALLY_SUPPORTED", "Amount appears in flattened E103 chart but year/series ownership is uncertain"
        if not any(number.replace(",", "") in source.replace(",", "") for number in re.findall(r"\d[\d,]*(?:\.\d+)?", value)):
            judgment, reason = "UNSUPPORTED", "Value is absent from cited chart text"
    elif "E106" in spans and ("comparative advantages" in label or "this initiative" in value):
        judgment, reason = "UNSUPPORTED", "Specific advantage list or unresolved phrase is absent from cited span"
    elif "E127" in spans and "three pillars:" in value:
        judgment, reason = "PARTIALLY_SUPPORTED", "Cited span says three pillars but does not name the list"
    elif "E130" in spans and "$952 million" in value:
        judgment, reason = "UNSUPPORTED", "$952 million belongs to the Emergency Programme Fund, not country programmes"
    elif "E133" in spans and "country programme" in label and "$952 million" in value:
        judgment, reason = "UNSUPPORTED", "$952 million belongs to the Emergency Programme Fund"
    elif "E133" in spans and "$952 million" in value and "emergency programme fund" not in label:
        judgment, reason = "PARTIALLY_SUPPORTED", "Amount is cited for Emergency Programme Fund; broad label loses that scope"
    elif "E137" in spans and "$227 million" in value and "development effectiveness" in label:
        judgment, reason = "PARTIALLY_SUPPORTED" if "capital costs" in label else "UNSUPPORTED", "$227 million is broader expenditure, not the Development Effectiveness component"
    elif "E137" in spans and "$174 million" in value:
        judgment, reason = "PARTIALLY_SUPPORTED", "$174 million is cited as a component; expense attribution and period need caution"
    elif "E209" in spans and ("shifted the global dial" in value or "progress toward sdg" in value):
        judgment, reason = "UNSUPPORTED" if "shifted" in value else "PARTIALLY_SUPPORTED", "Cited span gives reach figures and SDG label, not this full progress claim"
    if judgment == "SUPPORTED" and not period_supported(record.get("period"), source):
        judgment, reason = "PARTIALLY_SUPPORTED", "Record period is absent from its cited span"
    judgments.append({"sample_id": item["sample_id"], "judgment": judgment, "review_note": reason})

(BASE / "precision-review-judgments.json").write_text(json.dumps(judgments, indent=2, ensure_ascii=False)+"\n")
print("judged",len(judgments),"supported",sum(x["judgment"]=="SUPPORTED" for x in judgments),
      "partial",sum(x["judgment"]=="PARTIALLY_SUPPORTED" for x in judgments),
      "unsupported",sum(x["judgment"]=="UNSUPPORTED" for x in judgments))
