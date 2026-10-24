#!/usr/bin/env python3
"""Generate a fixed 120-row native-text financial PDF for local parser stress audits."""

from pathlib import Path
import subprocess
import sys
import tempfile


def line(x: int, y: int, value: str) -> str:
    safe = value.replace("\\", "\\\\").replace("(", "\\(").replace(")", "\\)")
    return f"{x} {y} moveto ({safe}) show\n"


def main() -> None:
    if len(sys.argv) != 2:
        raise SystemExit("usage: docintel-generate-pdf-table-stress.py OUTPUT.pdf")
    target = Path(sys.argv[1]).resolve()
    if target.suffix.lower() != ".pdf":
        raise SystemExit("output must be a PDF")
    ps = ["%!PS-Adobe-3.0\n%%Pages: 3\n%%BoundingBox: 0 0 595 842\n",
          "/Helvetica findfont 9 scalefont setfont\n"]
    for page in range(3):
        ps += [line(40, 803, "Example Holdings, Inc."),
               line(40, 785, "Condensed Consolidated Statements of Income"),
               line(40, 767, "USD thousands"),
               line(40, 745, "Metric"), line(280, 745, "Q3 2025"),
               line(410, 745, "Q3 2026")]
        for row in range(40):
            number = page * 40 + row + 1
            y = 730 - row * 16
            ps += [line(40, y, f"Operating item {number:03d}"),
                   line(280, y, str(1000 + number)),
                   line(410, y, str(1200 + number))]
        ps.append("showpage\n")
    ps.append("%%EOF\n")
    with tempfile.TemporaryDirectory(prefix="docintel-table-stress-") as directory:
        source = Path(directory) / "stress.ps"
        source.write_text("".join(ps), encoding="ascii")
        subprocess.run(["ps2pdf", str(source), str(target)], check=True)
    print(f"{target} rows=120 points=240 pages=3")


if __name__ == "__main__":
    main()
