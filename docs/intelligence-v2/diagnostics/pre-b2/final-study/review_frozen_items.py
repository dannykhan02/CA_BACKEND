"""Adjudicate only the frozen gold and negative items against accepted values.

The rules below identify the frozen proposition in the record's own label/value/
period, with the already-filtered cited span. Citation or reference text alone
never counts. Each rule was reviewed against the generated worksheet.
"""
import json
import pathlib
import re

BASE = pathlib.Path(__file__).resolve().parent


def gold_hit(chunk, ordinal, record):
    label = str(record["label"]).lower()
    value = str(record["value"]).lower()
    period = str(record.get("period") or "").lower()
    combined = label + " " + value
    if chunk == "unicef_reduced":
        tests = {
            1: "$1 billion" in value and "social protection" in combined,
            2: "8.8 million" in value and "mental health" in combined,
            3: "23" in value and "mortal" in combined,
            4: "1,700" in value and "facilit" in combined,
            5: "22.7" in value and "fatality" in combined,
            6: "59.3 million" in value and "water" in combined,
            7: "17.9 million" in value and "sanitation" in combined,
            8: "$68 million" in value and ("investment" in combined or "restart" in combined),
            9: "40" in value and "stunt" in combined,
            10: ("groundwater" in combined and ("satellite" in combined or "detect" in combined)),
            11: "mental health" in combined and ("integrat" in combined or "national systems" in combined),
            12: "funded" in value and "one stop centre" in combined,
            13: "9 in 10" in value and "regist" in combined,
            14: "675,000" in value and "protection" in combined,
            15: "$174 million" in value and "development effectiveness" in combined,
            16: "12 of the 17" in value and ("goal" in combined or "sdg" in combined),
            17: ("country programme" in combined and ("rights" in value or "sdg" in value) and "$" not in value),
        }
    else:
        tests = {
            1: "37" in value and "mortal" in combined,
            2: ("swachh bharat" in combined and ("launch" in combined or "2014" in value or "2014" in period)),
            3: "110 million" in value and "toilet" in combined and ("target" in combined or "goal" in combined),
            4: "550 million" in value and ("target" in combined or "goal" in combined),
            5: "achiev" in combined and ("2019" in value or "2019" in period),
            6: "550 million" in value and "sanitation" in combined,
            7: "580 million" in value and "water" in combined,
            8: "115 million" in value and "household" in combined,
            9: "50,000 rupees" in value and ("saving" in combined or "economic" in combined),
            10: "$120 billion" in value and "investment" in combined,
            11: "technical advisor" in combined and "every state" in value,
            12: ("data" in combined and ("collect" in value or "manag" in value or "data systems" in value)),
        }
    return bool(tests[ordinal])


def negative_hit(chunk, ordinal, record):
    # Match the frozen excluded proposition in the accepted record's own fields.
    # Merely citing the span, or mentioning it in a reference, is insufficient.
    label = str(record.get("label") or "").lower()
    value = str(record.get("value") or "").lower()
    period = str(record.get("period") or "").lower()
    text = " ".join((label, value, period))
    if chunk == "unicef_reduced":
        tests = {
            1: "guatemala" in text and "$1 billion" in value and "2024" in period,
            2: "map" in text and "disclaimer" in text and "risk" in text,
            3: "annual report" in text and "page 5" in text and "programme" in text,
            4: "qr" in text and ("deadline" in text or "due" in text),
            5: "yemen" in text and "mortal" in text and "2024" in period,
            6: "952 million" in value and "epf" in text and "loan" in text and ("immediately" in text or "due" in text),
        }
    else:
        tests = {
            1: "sdg" in text and "6" in text and "achiev" in text and "business" in text,
            2: "110 million" in value and "toilet" in text and ("complete" in text or "built" in text) and "2014" in text,
            3: "vani" in text and "handwash" in text and "2019" in text and ("deadline" in text or "due" in text),
            4: "115 million" in value and "household" in text and "2024" in period,
            5: "annual report" in text and "14" in value and "operational" in text,
        }
    return bool(tests[ordinal])


def main():
    gold = json.loads((BASE / "gold-review-worksheet.json").read_text())
    negative = json.loads((BASE / "negative-review-worksheet.json").read_text())
    for cell, rows in gold.items():
        chunk = cell.rsplit("-",1)[0]
        for row in rows:
            hits = [r["accepted_index"] for r in row["cited_accepted"] if gold_hit(chunk,row["ordinal"],r)]
            row["matched"] = bool(hits)
            row["accepted_indices"] = hits
            row["review_note"] = "Record label/value/period expresses frozen proposition" if hits else "No accepted record expresses frozen proposition"
    for cell, rows in negative.items():
        chunk = cell.rsplit("-",1)[0]
        for row in rows:
            hits = [r["accepted_index"] for r in row["cited_accepted"] if negative_hit(chunk,row["ordinal"],r)]
            row["matched"] = bool(hits)
            row["accepted_indices"] = hits
            row["review_note"] = "Frozen excluded claim expressed" if hits else "Frozen excluded claim absent"
    (BASE / "gold-review.json").write_text(json.dumps(gold,indent=2,ensure_ascii=False)+"\n")
    (BASE / "negative-review.json").write_text(json.dumps(negative,indent=2,ensure_ascii=False)+"\n")
    for chunk in ("unicef_reduced","india_wash"):
        for arm in "AB":
            print(chunk,arm,[sum(x["matched"] for x in gold[f"{chunk}-{arm}{run}"]) for run in range(1,6)])


if __name__ == "__main__":main()
