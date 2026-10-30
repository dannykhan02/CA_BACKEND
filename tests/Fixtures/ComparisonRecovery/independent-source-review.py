#!/usr/bin/env python3
"""Independent Poppler review of original bytes. Output is NEVER an application input."""
import hashlib
import json
import re
import subprocess
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent
FILES = [
    ("1936-cooper-eagle-agmt.pdf", 8, 16),
    ("1936-cooper-eagle-amendagmt1.pdf", 2, 2),
]
documents = []
terms = []
schedules = []
for filename, term_page, schedule_page in FILES:
    path = ROOT / "sources" / filename
    result = subprocess.run(["pdftotext", "-layout", str(path), "-"], check=True, capture_output=True)
    pages = result.stdout.decode().split("\f")
    if not pages[-1].strip():
        pages.pop()
    text = re.sub(r"\s+", " ", pages[term_page - 1])
    section = text[text.index("D. The Tax Limitation Period"):]
    patterns = {
        "2.3.D.i": r"Starts on ([A-Za-z]+ \d{1,2}, \d{4})",
        "2.3.D.ii": r"Ends on ([A-Za-z]+ \d{1,2}, \d{4})",
        "2.3.E": r"Final Termination Date for this Agreement is ([A-Za-z]+ \d{1,2}, \d{4})",
    }
    values = {}
    for ref, pattern in patterns.items():
        match = re.search(pattern, section)
        assert match, (filename, ref)
        values[ref] = {"reported_text": match.group(1),
                       "date": datetime.strptime(match.group(1), "%B %d, %Y").date().isoformat(),
                       "pdf_page": term_page}
    terms.append(values)
    schedule = pages[schedule_page - 1].split("Section 6.3.")[-1].split("Subject to Section 4.10")[0]
    rows = []
    for line in schedule.splitlines():
        match = re.match(r"^\s*(.+?)\s{2,}(\d{4}\s+[–-]\s+\d{4})\s+(\d{4})\s+"
                         r"((?:January|December) \d{1,2}, \d{4})\s+(\$100 x prior-year ADA)\s*$", line)
        if match:
            label, school, tax, due, formula = match.groups()
            rows.append({"agreement_year": label, "school_year": school, "tax_year": tax,
                         "payment_due": datetime.strptime(due, "%B %d, %Y").date().isoformat(),
                         "payment_formula": formula})
    assert len(rows) == (16 if term_page == 8 else 14), (filename, len(rows))
    schedules.append(rows)
    documents.append({"file": "sources/" + filename,
                      "url": "https://assets.comptroller.texas.gov/ch313/1936/" + filename,
                      "sha256": hashlib.sha256(path.read_bytes()).hexdigest(), "bytes": path.stat().st_size,
                      "pages": len(pages), "review_method": "pdftotext -layout on acquired original bytes",
                      "poppler_diagnostics": result.stderr.decode().strip(), "terms": values,
                      "section_6_3_pdf_page": schedule_page, "section_6_3_rows": rows})

assert [v["date"] for v in terms[0].values()] == ["2028-01-01", "2037-12-31", "2042-12-31"]
assert [v["date"] for v in terms[1].values()] == ["2026-01-01", "2035-12-31", "2040-12-31"]
shared = []
for old in schedules[0]:
    for new in schedules[1]:
        if old["tax_year"] == new["tax_year"]:
            shared.append({"tax_year": old["tax_year"],
                           "same_due_date": old["payment_due"] == new["payment_due"],
                           "same_school_year": old["school_year"] == new["school_year"],
                           "same_payment_formula": old["payment_formula"] == new["payment_formula"],
                           "original_agreement_year": old["agreement_year"],
                           "replacement_agreement_year": new["agreement_year"]})
assert len(shared) == 14 and all(r["same_due_date"] and r["same_payment_formula"] for r in shared)
report = {"role": "Independent expected observations; never structured extraction or engine output",
          "reviewed_at_utc": datetime.now(timezone.utc).isoformat(), "documents": documents,
          "section_6_3_shared_tax_year_review": shared,
          "limits": ["Row correspondence by tax year is a reviewer observation, not a DocIntel alignment.",
                     "Missing schedule rows do not establish legal deletion.",
                     "Layout text is independent of application Smalot text; its offsets must not be mixed."]}
(ROOT / "independent-source-review.json").write_text(json.dumps(report, indent=2) + "\n")
print("Verified three explicit date differences and 14 unchanged overlapping payment dates from original bytes.")
