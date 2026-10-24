#!/usr/bin/env python3
"""Score rollback-only audit JSON against visual source expectations, never train extraction."""

import argparse
import csv
import json
import re
from collections import defaultdict


def read_json_stream(path):
    content = open(path, encoding="utf-8").read()
    decoder = json.JSONDecoder()
    while content.strip():
        item, length = decoder.raw_decode(content.lstrip())
        yield item
        content = content.lstrip()[length:]


def canonical_period(row):
    column, duration = row["period_column"], row["duration"]
    if row["document"] == "Nasdaq":
        return "Q3 " + column[:4]
    if row["document"] == "Apple":
        months = "3" if duration == "three months" else "6"
        return f"{months}M ended {column}"
    if row["document"] == "Northstar":
        return column
    if row["document"] == "UPS":
        return column
    return column  # A balance-sheet date is not equivalent to a bare year.


def magnitude(value):
    match = re.search(r"\(?-?\d[\d,.]*\)?%?", value)
    return match.group(0) if match else value


def same_box(left, right):
    return isinstance(left, list) and len(left) == 4 and all(
        abs(float(a) - float(b)) <= 1 for a, b in zip(left, right))


def supported_context(fact, ref):
    unit = re.sub(r"\bmillions\b", "million", (fact["unit"] or "").lower())
    expected_unit = re.sub(r"\bmillions\b", "million", ref["unit_or_scale"].lower())
    owner = re.sub(r"[^a-z0-9]", "", (fact["subject"] or "").lower())
    expected_owner = {
        "Nasdaq": "nasdaqinc", "Apple": "appleinc",
        "Northstar": "northstardistributionltd",
    }.get(ref["document"])
    return (unit == expected_unit
            and owner == expected_owner
            and not fact["accounting_basis"])


def main(reference, audit):
    audits = {item["sha256"]: item for item in read_json_stream(audit)}
    nasdaq_page_fixture = "cece469e3d5b2bc932a503c11253ea893ac8dad2640d21f83c4d1e362fb36c66"
    scores = defaultdict(lambda: defaultdict(int))
    with open(reference, newline="", encoding="utf-8") as handle:
        for ref in csv.DictReader(handle):
            name = ref["document"]
            scores[name]["references"] += 1
            item = audits.get(ref["sha256"])
            if item is None and name == "Nasdaq":
                item = audits.get(nasdaq_page_fixture)
            if item is None:
                scores[name]["input_missing"] += 1
                continue
            same_cell = [fact for fact in item["facts"]
                         if fact["page"] == int(ref["input_page"])
                         and same_box(fact["bbox"], json.loads(ref["bbox_top_left_pdf_points"]))]
            exact = [fact for fact in same_cell
                     if fact["label"].strip() == ref["row_label"]
                     and magnitude(fact["value"]) == magnitude(ref["printed_value"])]
            if exact:
                scores[name]["numeric_row_and_box"] += 1
            period = [fact for fact in exact if fact["period"] == canonical_period(ref)]
            if period:
                scores[name]["period_context"] += 1
            accepted = [fact for fact in same_cell if fact["outcome"] == "verified"]
            if ref["expected_eligible"] == "yes":
                scores[name]["expected_positive"] += 1
                correct = [fact for fact in period if fact["outcome"] == "verified"
                           and supported_context(fact, ref)]
                if correct:
                    scores[name]["positive_verified"] += 1
                elif accepted:
                    scores[name]["context_mismatch"] += 1
            else:
                scores[name]["expected_negative"] += 1
                if accepted:
                    scores[name]["wrong_acceptance"] += 1
                elif not same_cell:
                    scores[name]["negative_absent"] += 1
                else:
                    scores[name]["negative_rejected"] += 1
    print(json.dumps(scores, indent=2))


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("reference")
    parser.add_argument("audit")
    args = parser.parse_args()
    main(args.reference, args.audit)
