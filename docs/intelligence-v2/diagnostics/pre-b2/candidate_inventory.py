"""Offline Candidate Inventory V1. No application, provider, or database imports."""
from __future__ import annotations

import hashlib
import re
from collections import Counter, defaultdict
from decimal import Decimal

DETECTOR_VERSION = "candidate-inventory-v1.0.0"
MATCHER_VERSION = "candidate-representation-v1.0.0"
CODE = r"USD|KES|EUR|GBP|NGN|ZAR|TZS|UGX|RWF|GHS|ZMW|XAF|XOF|INR|CNY|JPY|AED|SAR|CAD|AUD|CHF|SEK|NOK|DKK|BRL|MXN|TRY|EGP|MAD|ETB|MUR|SDR"
SCALE = r"billion|million|thousand|billions|millions|thousands|bn|mn|k|m|b"
NUMBER = r"[+-]?\d[\d,]*(?:\.\d+)?"
TOKEN = re.compile(rf"(?<![\w])(?:(?P<code>{CODE})\s*|(?P<symbol>[$€£₹₦])\s*)?(?P<num>{NUMBER})\s*(?P<suffix>%|per\s+cent|percent|{SCALE})?(?![\w])", re.I)
SPAN = re.compile(r"(?m)^\[(E\d+)\]\s*\n")
MULTIPLIER = {"k": 1000, "thousand": 1000, "thousands": 1000, "m": 10**6, "mn": 10**6,
              "million": 10**6, "millions": 10**6, "b": 10**9, "bn": 10**9,
              "billion": 10**9, "billions": 10**9}
SYMBOL_CURRENCY = {"€": "EUR", "£": "GBP", "₹": "INR", "₦": "NGN"}


def split_spans(source):
    marks = list(SPAN.finditer(source))
    return [(m.group(1), source[m.end():marks[i+1].start() if i+1 < len(marks) else len(source)])
            for i, m in enumerate(marks)]


def parse_token(match):
    raw = match.group()
    suffix = (match.group("suffix") or "").lower().replace(" ", "")
    code, symbol = match.group("code"), match.group("symbol")
    if suffix in ("%", "percent", "percent") or re.fullmatch(r"per\s+cent", match.group("suffix") or "", re.I):
        cls, scale, percent = "percentage", None, True
    elif code or symbol:
        cls, scale, percent = "currency", suffix or None, False
    elif suffix in MULTIPLIER:
        cls, scale, percent = "scaled_quantity", suffix, False
    else:
        cls, scale, percent = "diagnostic_year" if re.fullmatch(r"(?:19|20)\d{2}", match.group("num")) else "diagnostic_bare_number", None, False
    currency_type = "explicit_code" if code else "symbol_ambiguous" if symbol == "$" else "explicit_symbol" if symbol else "none"
    currency = code.upper() if code else SYMBOL_CURRENCY.get(symbol)
    number = Decimal(match.group("num").replace(",", ""))
    canonical = number * MULTIPLIER.get(scale, 1)
    return dict(candidate_class=cls, raw_source_value=raw.strip(), canonical_numeric=str(canonical),
                currency=currency, currency_source_type=currency_type, scale=scale,
                percentage=percent, percentage_type="explicit" if percent else None)


class CandidateInventory:
    def detect(self, source, chart_span_ids=(), span_metadata=None):
        result = []
        span_metadata = span_metadata or {}
        offset = 0
        for ordinal, (span_id, body) in enumerate(split_spans(source), 1):
            meta = span_metadata.get(span_id, {})
            chart = span_id in chart_span_ids
            occurrences = Counter()
            for match in TOKEN.finditer(body):
                parsed = parse_token(match)
                # A bare suffix that is embedded in an ordinary word is rejected by TOKEN boundaries.
                key = (parsed["candidate_class"], parsed["canonical_numeric"], parsed["currency"])
                occurrences[key] += 1
                item = dict(candidate_id=hashlib.sha256(f"{DETECTOR_VERSION}|{span_id}|{match.start()}|{match.group()}".encode()).hexdigest()[:20],
                            detector_version=DETECTOR_VERSION, source_span_id=span_id, page=meta.get("page"),
                            span_ordinal=meta.get("ordinal", ordinal), occurrence_index=occurrences[key],
                            source_offset=meta.get("start_offset", offset)+match.start(),
                            span_offset=match.start(), chart_or_ocr_ambiguous=chart,
                            headline_eligible=parsed["candidate_class"] in ("percentage", "currency", "scaled_quantity") and not chart)
                result.append(item | parsed)
            offset += len(body)
        return result


def _tokens(text):
    return [parse_token(m) for m in TOKEN.finditer(str(text or ""))]


def _typed_tokens(record):
    tokens = _tokens(record.get("value"))
    unit = str(record.get("unit") or "").lower()
    if len(tokens) == 1 and tokens[0]["candidate_class"] in ("diagnostic_bare_number", "diagnostic_year"):
        scales = [k for k in ("thousand", "million", "billion") if re.search(r"\b"+k+r"s?\b", unit)]
        if len(scales) == 1:
            tokens[0]["canonical_numeric"] = str(Decimal(tokens[0]["canonical_numeric"]) * MULTIPLIER[scales[0]])
            tokens[0]["scale"] = scales[0]
        if "percent" in unit or "per cent" in unit:
            tokens[0]["percentage"] = True
            tokens[0]["percentage_type"] = "explicit_unit"
    return tokens


def _same(candidate, token, record):
    if candidate["canonical_numeric"] != token["canonical_numeric"]:
        return False
    if candidate["percentage"] != token["percentage"]:
        return False
    if candidate["currency"] and token["currency"] and candidate["currency"] != token["currency"]:
        return False
    if candidate["currency_source_type"] == "symbol_ambiguous" and token["currency"] and token["currency"] != "USD":
        return False
    if candidate["currency_source_type"] == "explicit_code" and token["currency"] is None and record.get("unit"):
        # Unit may express the currency, but an explicit mismatch cannot be a clean match.
        codes = re.findall(rf"\b(?:{CODE})\b", str(record["unit"]), re.I)
        if codes and candidate["currency"] not in [x.upper() for x in codes]:
            return False
    return True


def represent(candidates, accepted_records, rejected_records=()):
    """Match accepted values only, with one-to-one ownership for identical occurrences."""
    by_span = defaultdict(list)
    for record in accepted_records:
        for span in record.get("evidence_ids", []):
            by_span[span].append(record)
    result = []
    claims = defaultdict(list)
    for candidate in candidates:
        item = candidate.copy()
        span = candidate["source_span_id"]
        matches = [r for r in by_span[span] if r.get("kind") == "metric" and any(_same(candidate, t, r) for t in _typed_tokens(r))]
        text_only = any(any(_same(candidate, t, r) for field in ("label", "reference") for t in _tokens(r.get(field))) for r in by_span[span])
        item["status"] = "REPRESENTED" if matches else "MENTIONED_IN_TEXT_ONLY" if text_only else "UNREPRESENTED"
        item["currency_unstated"] = bool(matches and candidate["currency"] and all(not re.search(rf"\b(?:{CODE})\b", str(r.get("unit") or ""), re.I) for r in matches))
        item["model_supplied_usd_from_ambiguous_dollar_not_citation_supported"] = candidate["currency_source_type"] == "symbol_ambiguous" and any("USD" in str(r.get("unit") or "") for r in matches)
        item["rejection_reasons"] = sorted({str(r.get("rejection_reason")) for r in rejected_records if span in r.get("evidence_ids", []) and r.get("rejection_reason")})
        result.append(item)
        if matches:
            claims[(span, candidate["candidate_class"], candidate["canonical_numeric"], candidate["currency"])].append((item, matches))
    for group in claims.values():
        # Identical occurrences in one span lack a source offset on the accepted record.
        # Even equal record and occurrence counts do not prove one-to-one ownership.
        if len(group) > 1:
            for item, _ in group:
                item["status"] = "INDETERMINATE"
    return result
