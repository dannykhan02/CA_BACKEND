"""Generate the synthetic native-text PDF used by BasicPdfTableTest.

This is a layout test fixture, not a copy of the Quillmere production file.
"""

from pathlib import Path


def escape(text: str) -> str:
    return text.replace("\\", "\\\\").replace("(", "\\(").replace(")", "\\)")


header = [(50, "Cost line"), (195, "Quantity / basis"), (390, "Cost ($)"), (505, "Share (%)")]
rows = [
    ("Seed", "All sown crops", "138,400", "15.3"),
    ("Fertiliser", "Compound products", "402,600", "44.4"),
    ("Lime", "280 t at $38/t", "10,640", "1.2"),
    ("Crop protection", "Net of rebate", "184,300", "20.3"),
    ("Fuel", "Machinery fleet", "96,750", "10.7"),
    ("Soil testing", "Laboratory services", "14,200", "1.6"),
    ("Contract services", "Drying and haulage", "58,900", "6.5"),
    ("Total", "", "905,790", "100.0"),
]
commands = []
for x, y, value in [(50, 755, "Table 8: Purchased input costs, 2025/26")]:
    commands.append(f"BT /F1 12 Tf {x} {y} Td ({escape(value)}) Tj ET")
for x, value in header:
    commands.append(f"BT /F1 10 Tf {x} 715 Td ({escape(value)}) Tj ET")
for number, row in enumerate(rows):
    y = 690 - 24 * number
    for x, value in zip((50, 195, 390, 505), row):
        if value:
            commands.append(f"BT /F1 10 Tf {x} {y} Td ({escape(value)}) Tj ET")
stream = "\n".join(commands).encode("ascii")
objects = [
    b"<< /Type /Catalog /Pages 2 0 R >>",
    b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
    b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>",
    b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
    b"<< /Length " + str(len(stream)).encode() + b" >>\nstream\n" + stream + b"\nendstream",
]
pdf = bytearray(b"%PDF-1.4\n")
offsets = [0]
for index, obj in enumerate(objects, 1):
    offsets.append(len(pdf))
    pdf.extend(f"{index} 0 obj\n".encode() + obj + b"\nendobj\n")
xref = len(pdf)
pdf.extend(f"xref\n0 {len(offsets)}\n0000000000 65535 f \n".encode())
for offset in offsets[1:]:
    pdf.extend(f"{offset:010d} 00000 n \n".encode())
pdf.extend(f"trailer\n<< /Size {len(offsets)} /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n".encode())
Path(__file__).with_name("synthetic_single_period_costs.pdf").write_bytes(pdf)
Path(__file__).with_name("synthetic_single_period_costs_conflict.pdf").write_bytes(
    pdf.replace(b"905,790", b"905,000")
)
Path(__file__).with_name("synthetic_single_period_costs_duplicate.pdf").write_bytes(
    pdf.replace(b"(Seed)", b"(Lime)")
)
Path(__file__).with_name("synthetic_single_period_costs_no_unit.pdf").write_bytes(
    pdf.replace(b"Cost \\($\\)", b"Cost \\(x\\)")
)
Path(__file__).with_name("synthetic_single_period_costs_forecast.pdf").write_bytes(
    pdf.replace(b"Purchased input costs", b"Forecast input costs!")
)
Path(__file__).with_name("synthetic_single_period_costs_share_conflict.pdf").write_bytes(
    pdf.replace(b"(15.3)", b"(15.4)")
)
Path(__file__).with_name("synthetic_single_period_costs_ambiguous_caption.pdf").write_bytes(
    pdf.replace(b"Purchased input costs", b"Forecast  input costs")
)
Path(__file__).with_name("synthetic_single_period_costs_scale.pdf").write_bytes(
    pdf.replace(b"Purchased input costs", b"Costs in $ millions!!")
)
