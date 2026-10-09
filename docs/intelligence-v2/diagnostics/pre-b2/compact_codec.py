"""Offline-only strict compact evidence expander. Never imported by production."""
import json

from measure_compact import KEYS, NULLABLE

ORDER = tuple(k for k in KEYS if k != "records")
REQUIRED = set(ORDER) - NULLABLE - {"quote"}
KINDS = {"entity", "metric", "deadline", "obligation", "risk", "fact", "definition", "unresolved"}


class CompactExpansionError(ValueError):
    code = "compact_expansion_error"


class CompactEvidenceExpander:
    @staticmethod
    def encode(canonical, short=True):
        if set(canonical) != {"records"} or not isinstance(canonical["records"], list):
            raise CompactExpansionError("invalid canonical root")
        result = []
        for record in canonical["records"]:
            CompactEvidenceExpander._validate_record(record)
            result.append({(KEYS[k] if short else k): record[k] for k in ORDER if k in record and not (k in NULLABLE and record[k] is None)})
        return {"m" if short else "metrics": result}

    @staticmethod
    def expand(payload, short=True):
        root = "m" if short else "metrics"
        if not isinstance(payload, dict) or set(payload) != {root} or not isinstance(payload[root], list):
            raise CompactExpansionError("invalid root")
        inverse = {v: k for k, v in KEYS.items() if k != "records"}
        records = []
        for compact in payload[root]:
            if not isinstance(compact, dict) or any((k not in inverse if short else k not in ORDER) for k in compact):
                raise CompactExpansionError("unknown key or malformed record")
            expanded = {inverse[k] if short else k: v for k, v in compact.items()}
            for key in NULLABLE:
                expanded.setdefault(key, None)
            CompactEvidenceExpander._validate_record(expanded)
            records.append({k: expanded[k] for k in ORDER if k in expanded})
        return {"records": records}

    @staticmethod
    def _validate_record(r):
        if not isinstance(r, dict) or not REQUIRED <= set(r) or set(r) - set(ORDER):
            raise CompactExpansionError("missing or unknown field")
        for k in ("label", "value", "subject", "reference", "kind"):
            if not isinstance(r[k], str):
                raise CompactExpansionError("wrong string type")
        if r["kind"] not in KINDS:
            raise CompactExpansionError("unsupported kind")
        if not isinstance(r["confidence"], (int, float)) or isinstance(r["confidence"], bool):
            raise CompactExpansionError("wrong confidence type")
        for k in ("evidence_ids", "aliases"):
            if not isinstance(r[k], list) or any(not isinstance(x, str) for x in r[k]):
                raise CompactExpansionError("wrong array type")
        for k in NULLABLE:
            if k in r and r[k] is not None and not isinstance(r[k], str):
                raise CompactExpansionError("wrong nullable type")

    @staticmethod
    def salvage(raw, short=True):
        """Diagnostic truncation probe: only fully closed objects are returned."""
        root = '"m"' if short else '"metrics"'
        pos = raw.find(root)
        if pos < 0:
            return []
        start = raw.find("[", pos + len(root))
        if start < 0:
            return []
        depth = 0
        quoted = escaped = False
        begin = None
        rows = []
        for i in range(start + 1, len(raw)):
            char = raw[i]
            if quoted:
                if escaped:
                    escaped = False
                elif char == "\\":
                    escaped = True
                elif char == '"':
                    quoted = False
            elif char == '"':
                quoted = True
            elif char == "{":
                if depth == 0:
                    begin = i
                depth += 1
            elif char == "}":
                depth -= 1
                if depth == 0 and begin is not None:
                    try:
                        row = json.loads(raw[begin:i+1])
                        CompactEvidenceExpander.expand({"m" if short else "metrics": [row]}, short)
                        rows.append(row)
                    except (json.JSONDecodeError, CompactExpansionError):
                        pass
                    begin = None
                if depth < 0:
                    break
            elif char == "]" and depth == 0:
                break
        return rows
