#!/usr/bin/env python3
"""Offline comparison probe. Reads local PDFs; never publishes provenance."""

import argparse
import json
import resource
import time

import pdfplumber


def inspect(path, pages):
    started = time.perf_counter()
    results = []
    with pdfplumber.open(path) as pdf:
        for number in pages:
            page = pdf.pages[number - 1]
            variants = {}
            for name, settings in {
                "lines": {"vertical_strategy": "lines", "horizontal_strategy": "lines"},
                "text": {"vertical_strategy": "text", "horizontal_strategy": "text",
                         "text_x_tolerance": 2, "text_y_tolerance": 3,
                         "min_words_vertical": 2, "min_words_horizontal": 1},
            }.items():
                tables = page.find_tables(table_settings=settings)
                variants[name] = [{"bbox": table.bbox, "rows": table.extract()} for table in tables]
            results.append({"page": number, "words": len(page.extract_words()),
                            "lines": len(page.lines), "rects": len(page.rects),
                            "variants": variants})
    return {"file": path, "pages": results,
            "seconds": round(time.perf_counter() - started, 3),
            "peak_rss_kib": resource.getrusage(resource.RUSAGE_SELF).ru_maxrss}


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("pdf")
    parser.add_argument("pages", nargs="+", type=int)
    arguments = parser.parse_args()
    print(json.dumps(inspect(arguments.pdf, arguments.pages), indent=2))
