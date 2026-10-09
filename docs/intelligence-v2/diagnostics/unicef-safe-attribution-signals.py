"""Deterministic source-signal counts for the verified diagnostic fixture."""

import collections
import hashlib
import json
import pathlib
import re

HERE = pathlib.Path(__file__).resolve().parent
source = (HERE / "unicef-safe-attribution-source.txt").read_text(encoding="utf-8")
spans = json.loads((HERE / "unicef-safe-attribution-spans.json").read_text())
assert hashlib.sha256(source.encode()).hexdigest() == "803c45e8e6a1e38952f213d70b95aa9ab62aa02dc7bbf206df156d6d59cf18fc"

number = r"\d[\d,]*(?:\.\d+)?"
patterns = {
    "currency": rf"(?<!\w)(?:[$€£]\s*{number}|(?:USD|EUR|GBP|KES)\s*{number}|{number}\s*(?:USD|EUR|GBP|KES))(?:\s*(?:thousand|million|billion|trillion))?",
    "percent": rf"(?<!\w){number}\s*(?:%|per\s+cent\b|percent\b)",
    "date_time": r"\b\d{1,2}\s+(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+(?:19|20)\d{2}\b|\b(?:19|20)\d{2}\b|\b\d+\s+(?:hours?|days?|weeks?|months?|years?)\b",
    "numeric": rf"(?<![A-Za-z]){number}(?![A-Za-z])",
}
matches = {key: list(re.finditer(pattern, source, re.IGNORECASE)) for key, pattern in patterns.items()}
covered = [(m.start(), m.end()) for key in ("currency", "percent", "date_time") for m in matches[key]]
other = [m for m in matches["numeric"] if not any(a <= m.start() and m.end() <= b for a, b in covered)]
normalized = lambda items: len({re.sub(r"\s+", "", m.group().casefold()) for m in items})
types = collections.Counter(row["type"] for row in spans)
result = {
    "source_sha256": hashlib.sha256(source.encode()).hexdigest(),
    "currency_raw": len(matches["currency"]), "currency_distinct_notation": normalized(matches["currency"]),
    "percent_raw": len(matches["percent"]), "percent_distinct_notation": normalized(matches["percent"]),
    "date_time_raw": len(matches["date_time"]), "date_time_distinct_notation": normalized(matches["date_time"]),
    "other_numeric_raw": len(other), "other_numeric_distinct_notation": normalized(other),
    "all_numeric_tokens": len(matches["numeric"]), "span_types": dict(types),
    "heading_section_span_ratio": round((types["heading"] + types["section"]) / len(spans), 4),
}
(HERE / "unicef-safe-attribution-signals.json").write_text(json.dumps(result, indent=2) + "\n")
print(json.dumps(result, indent=2))
