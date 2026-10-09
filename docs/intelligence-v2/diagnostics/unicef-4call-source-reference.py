"""Enumerate source-side observable signals; these are not semantic gold evidence."""

import hashlib
import json
import pathlib
import re
from decimal import Decimal

BASE = pathlib.Path(__file__).resolve().parent
source_bytes = (BASE / "unicef-safe-attribution-source.txt").read_bytes()
assert hashlib.sha256(source_bytes).hexdigest() == "803c45e8e6a1e38952f213d70b95aa9ab62aa02dc7bbf206df156d6d59cf18fc"
source = source_bytes.decode("utf-8")
spans = json.loads((BASE / "unicef-safe-attribution-stored-span-rows.json").read_text())
assert len(spans) == 154 and spans[0]["span_key"] == "E033" and spans[-1]["span_key"] == "E186"
number = r"\d[\d,]*(?:\.\d+)?"
patterns = {
    "currency": rf"(?<!\w)(?:[$€£]\s*{number}|(?:USD|EUR|GBP|KES)\s*{number}|{number}\s*(?:USD|EUR|GBP|KES))(?:\s*(?:thousand|million|billion|trillion))?",
    "percent": rf"(?<!\w){number}\s*(?:%|per\s+cent\b|percent\b)",
    "date_time": r"\b\d{1,2}\s+(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+(?:19|20)\d{2}\b|\b(?:19|20)\d{2}\b|\b\d+\s+(?:hours?|days?|weeks?|months?|years?)\b",
    "scaled_operational": rf"(?<![$€£\w]){number}\s+(?:thousand|million|billion|trillion)\b",
    "numeric_operational": rf"(?<!\w){number}\s+(?:countries|children|people|schools|health\s+facilities|communities)\b",
    "obligation_language": r"\b(?:must|shall|required to|deadline|due by|no later than)\b",
}


def numeric(expression):
    found = re.search(number, expression)
    return str(Decimal(found.group().replace(",", "")).normalize()) if found else None


result = []
for span in spans:
    text = source[span["start_offset"] - 4218 : span["end_offset"] - 4218]
    for category, pattern in patterns.items():
        for match in re.finditer(pattern, text, re.IGNORECASE):
            expression = match.group().strip()
            if category == "scaled_operational" and re.search(r"[$€£]|\b(?:USD|EUR|GBP|KES)\b", text[max(0, match.start()-5):match.end()], re.I):
                continue
            value = numeric(expression)
            scale = next((x for x in ("trillion", "billion", "million", "thousand") if re.search(rf"\b{x}\b", expression, re.I)), None)
            material = False
            if category == "currency" and value is not None:
                amount = Decimal(value) * {"trillion": Decimal(10)**12, "billion": Decimal(10)**9,
                    "million": Decimal(10)**6, "thousand": Decimal(10)**3, None: Decimal(1)}[scale]
                material = amount >= 100_000_000
            elif category == "percent" and value is not None:
                material = Decimal(value) >= 20
            elif category == "date_time":
                material = bool(re.search(r"\b(?:hours?|days?|weeks?|months?)\b", expression, re.I))
            elif category == "scaled_operational" and value is not None:
                material = Decimal(value) >= 1 and scale in ("million", "billion", "trillion")
            elif category == "numeric_operational" and value is not None:
                material = Decimal(value) >= 10
            elif category == "obligation_language":
                material = True
            result.append({"span": span["span_key"], "page": span["page"], "type": span["type"],
                "category": category, "expression": expression, "number": value,
                "scale": scale, "potentially_material_proxy": material})

unique = list({(r["span"], r["category"], re.sub(r"\s+", " ", r["expression"].casefold())): r for r in result}.values())
out = {"title": "SOURCE-SIDE OBSERVABLE REFERENCE", "gold_standard": False,
    "materiality_rule": "Heuristic only: currency >= $100m equivalent, percent >=20, short time bounds, scaled operational >=1m, or obligation words.",
    "source_sha256": hashlib.sha256(source_bytes).hexdigest(), "count": len(unique),
    "material_proxy_count": sum(r["potentially_material_proxy"] for r in unique),
    "candidates": unique}
(BASE / "unicef-4call-source-reference.json").write_text(json.dumps(out, indent=2) + "\n")
print(json.dumps({key: out[key] for key in ("count", "material_proxy_count")}, indent=2))
