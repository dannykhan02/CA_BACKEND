#!/usr/bin/env python3
"""Local real-document intake and offline replay. All case files stay outside Git."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import shutil
import subprocess
import sys
import time
from datetime import date
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CORPUS = ROOT / "storage/framework/testing/docintel-evaluation-corpus"
OUTPUT = ROOT / "storage/framework/testing/docintel-evidence-first-gate"
CATEGORIES = ("real_recorded_model", "real_human_observations")
EXTENSIONS = {".pdf": "PDF", ".docx": "DOCX", ".xlsx": "XLSX"}
FIELDS = ("id", "metric", "value", "unit", "period", "basis", "entity", "population",
          "expected_decision", "analytical_eligible", "source_references")


def digest(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def load(path: Path) -> dict:
    value = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(value, dict):
        raise ValueError(f"Expected JSON object: {path.name}")
    return value


def private_json(path: Path, value: dict) -> None:
    with path.open("x", encoding="utf-8") as stream:
        json.dump(value, stream, indent=2, ensure_ascii=False)
        stream.write("\n")
    path.chmod(0o600)


def extract(path: Path, kind: str) -> dict:
    result = subprocess.run(["php", "tools/evidence_corpus_extract.php", kind, str(path)],
                            cwd=ROOT, capture_output=True, text=True, check=False, timeout=180)
    if result.returncode:
        raise RuntimeError(f"Local {kind} extraction failed (exit {result.returncode})")
    return json.loads(result.stdout)


def within_case(case_dir: Path, name: str) -> Path:
    if not isinstance(name, str) or Path(name).name != name:
        raise ValueError("Case file must be a basename in its private case directory")
    path = (case_dir / name).resolve()
    if not path.is_file() or not path.is_relative_to(CORPUS.resolve()):
        raise ValueError(f"Missing local case file: {name}")
    return path


def case_dir(case_id: str) -> Path:
    if not re.fullmatch(r"[a-z][a-z0-9-]{2,63}", case_id):
        raise ValueError("Case ID must be a lowercase, 3–64 character slug")
    return CORPUS / case_id


def intake(args: argparse.Namespace) -> dict:
    original = Path(args.source).expanduser().resolve(strict=True)
    kind = EXTENSIONS.get(original.suffix.lower())
    if kind is None or not original.is_file() or original.stat().st_size == 0:
        raise ValueError("Source must be a nonempty local PDF, DOCX or XLSX")
    if args.category not in CATEGORIES:
        raise ValueError("Unknown real-document evaluation category")
    if not all([args.authorization.strip(), args.provenance.strip(), args.reviewer.strip()]):
        raise ValueError("Authorization reference, document provenance and reviewer are required")
    target = case_dir(args.id)
    if target.exists():
        raise ValueError("Case ID already exists; originals and snapshots are immutable")
    # Extract before creating the case so an invalid or unsupported source leaves no partial entry.
    snapshot = extract(original, kind)
    os.umask(0o077)
    CORPUS.mkdir(mode=0o700, parents=True, exist_ok=True)
    target.mkdir(mode=0o700)
    source_name = "original" + original.suffix.lower()
    copied = target / source_name
    with original.open("rb") as src, copied.open("xb") as dst:
        shutil.copyfileobj(src, dst)
    copied.chmod(0o600)
    if digest(copied) != digest(original):
        raise RuntimeError("Copied original differs from authorized source")
    manifest = {"schema_version": 1, "id": args.id, "category": args.category,
                "format": kind, "source_file": source_name, "source_sha256": digest(copied),
                "source_size_bytes": copied.stat().st_size, "original_filename": original.name,
                "authorization_reference": args.authorization.strip(),
                "provenance": args.provenance.strip(), "intake_reviewer": args.reviewer.strip(),
                "intake_date": date.today().isoformat(), "review_status": "draft",
                "parser_version": snapshot["parser_version"],
                "extraction_sha256": snapshot["text_sha256"],
                "source_span_revision": snapshot["source_span_revision"],
                "span_count": len(snapshot["spans"]), "snapshot_file": "snapshot.json",
                "snapshot_sha256": None, "truth_file": "truth.json", "truth_sha256": None,
                "candidate_file": None, "candidate_provenance": None,
                "candidate_sha256": None, "recorded_response_file": None,
                "recorded_response_sha256": None, "recorded_response_provenance": None,
                "recorded_prompt_version": None}
    private_json(target / "snapshot.json", snapshot)
    manifest["snapshot_sha256"] = digest(target / "snapshot.json")
    private_json(target / "truth.json", {"schema_version": 1, "case_id": args.id,
                 "origin": "independent_human_review", "review_status": "draft",
                 "reviewer": "", "reviewed_at": "", "source_facts": [], "claims": [],
                 "expected_chart_membership": [], "expected_comparison_membership": []})
    private_json(target / "manifest.json", manifest)
    return {"case_id": args.id, "category": args.category, "format": kind,
            "source_sha256": manifest["source_sha256"], "span_count": len(snapshot["spans"]),
            "status": "draft", "directory": str(target)}


def validate(case_id: str, *, ready: bool = True, reextract: bool = True) -> tuple[dict, dict, dict]:
    directory = case_dir(case_id)
    manifest = load(within_case(directory, "manifest.json"))
    if manifest.get("schema_version") != 1 or manifest.get("id") != case_id \
            or manifest.get("category") not in CATEGORIES:
        raise ValueError("Invalid real-document case identity or category")
    kind = manifest.get("format")
    source = within_case(directory, manifest.get("source_file"))
    if EXTENSIONS.get(source.suffix) != kind or digest(source) != manifest.get("source_sha256") \
            or source.stat().st_size != manifest.get("source_size_bytes"):
        raise ValueError("Original source format, size or hash mismatch")
    if not all(isinstance(manifest.get(key), str) and manifest[key].strip() for key in
               ("authorization_reference", "provenance", "intake_reviewer", "original_filename")):
        raise ValueError("Authorization or document provenance missing")
    snapshot = load(within_case(directory, manifest.get("snapshot_file")))
    if digest(directory / manifest["snapshot_file"]) != manifest.get("snapshot_sha256"):
        raise ValueError("Extraction snapshot hash mismatch")
    if snapshot.get("format") != kind or snapshot.get("parser_version") != manifest.get("parser_version") \
            or snapshot.get("text_sha256") != manifest.get("extraction_sha256") \
            or snapshot.get("source_span_revision") != manifest.get("source_span_revision") \
            or hashlib.sha256(snapshot.get("text", "").encode()).hexdigest() != manifest.get("extraction_sha256") \
            or len(snapshot.get("spans", [])) != manifest.get("span_count"):
        raise ValueError("Extraction snapshot or source revision mismatch")
    if reextract:
        fresh = extract(source, kind)
        if fresh != snapshot:
            raise ValueError("Current extractor differs from registered extraction; re-review required")
    truth = load(within_case(directory, manifest.get("truth_file")))
    if ready and manifest.get("review_status") == "human_verified" \
            and digest(directory / manifest["truth_file"]) != manifest.get("truth_sha256"):
        raise ValueError("Sealed independent truth hash mismatch")
    if truth.get("case_id") != case_id or truth.get("origin") != "independent_human_review":
        raise ValueError("Truth origin must be independent human review")
    if ready and (manifest.get("review_status") != "human_verified"
                  or truth.get("review_status") != "human_verified"
                  or not isinstance(truth.get("reviewer"), str) or not truth["reviewer"].strip()
                  or not re.fullmatch(r"\d{4}-\d{2}-\d{2}", str(truth.get("reviewed_at", "")))):
        raise ValueError("Case lacks completed human ground-truth review")
    claims = truth.get("claims")
    if not isinstance(claims, list) or (ready and manifest["category"] == "real_human_observations"
                                         and not claims):
        raise ValueError("Backend-only truth must contain explicit positive or refusal claims")
    seen = set()
    spans = {row["key"]: row for row in snapshot["spans"]}
    nodes = {row["id"]: row for row in snapshot["nodes"]}
    def check_references(item: dict) -> None:
        if not isinstance(item["source_references"], list) or not item["source_references"]:
            raise ValueError(f"Exact source references required: {item['id']}")
        for ref in item["source_references"]:
            if not isinstance(ref, dict) or not all(k in ref for k in
                    ("role", "span_id", "start_offset", "end_offset", "quote")):
                raise ValueError(f"Incomplete source reference: {item['id']}")
            if not isinstance(ref["role"], str) or not ref["role"].strip():
                raise ValueError(f"Source role is missing: {item['id']}")
            start, end = ref["start_offset"], ref["end_offset"]
            span = spans.get(ref["span_id"])
            if not isinstance(start, int) or not isinstance(end, int) or not span \
                    or not span["start_offset"] <= start < end <= span["end_offset"] \
                    or snapshot["text"][start:end] != ref["quote"]:
                raise ValueError(f"Source quote/offset/span mismatch: {item['id']}")
            if ref.get("node_id") is not None:
                node = nodes.get(ref["node_id"])
                if node is None or not node.get("start", -1) <= start < end <= node.get("end", -1):
                    raise ValueError(f"Native cell/page reference mismatch: {item['id']}")
            if ref.get("page") is not None and ref["page"] != span["page"]:
                raise ValueError(f"Page reference differs from extracted span: {item['id']}")
            if kind == "PDF" and not isinstance(ref.get("page"), int):
                raise ValueError(f"PDF source reference requires a page: {item['id']}")
            if kind == "XLSX" and not isinstance(ref.get("sheet"), str):
                raise ValueError(f"XLSX source reference requires a worksheet: {item['id']}")
            if kind == "XLSX" and ref["role"] == "value" and ref.get("node_id") is None:
                raise ValueError(f"XLSX value reference requires an exact cell node: {item['id']}")
            if kind == "XLSX" and ref.get("node_id") is not None \
                    and nodes[ref["node_id"]].get("sheet") != ref["sheet"]:
                raise ValueError(f"Worksheet differs from native cell: {item['id']}")
            for field in ("table", "row", "column", "cell"):
                if field in ref and ref[field] is not None and not isinstance(ref[field], (str, int)):
                    raise ValueError(f"Invalid structural locator {field}: {item['id']}")
    def check_semantics(item: dict) -> None:
        for field in ("metric", "value"):
            if not isinstance(item[field], str) or not item[field].strip():
                raise ValueError(f"{field} must be transcribed explicitly: {item['id']}")
        for field in ("unit", "period", "basis", "entity", "population"):
            value = item[field]
            if isinstance(value, str) and value.strip():
                continue
            if value is not None or item.get(field + "_status") not in \
                    ("missing", "ambiguous", "dimensionless", "count_unprinted", "not_applicable"):
                raise ValueError(f"Missing {field} needs an explicit status: {item['id']}")
    source_facts = truth.get("source_facts")
    if not isinstance(source_facts, list) or (ready and manifest["category"] == "real_recorded_model"
                                                 and not source_facts):
        raise ValueError("Recorded-model evaluation needs independent source facts")
    fact_ids = set()
    for fact in source_facts:
        if not isinstance(fact, dict) or any(field not in fact for field in
                ("id", "metric", "value", "unit", "period", "basis", "entity", "population",
                 "analytical_eligible", "source_references")):
            raise ValueError("Independent source fact lacks a required field")
        if not isinstance(fact["id"], str) or not fact["id"] or fact["id"] in fact_ids \
                or not isinstance(fact["analytical_eligible"], bool):
            raise ValueError("Duplicate or invalid independent source fact")
        fact_ids.add(fact["id"])
        check_semantics(fact)
        check_references(fact)
    for claim in claims:
        if not isinstance(claim, dict) or any(key not in claim for key in FIELDS):
            raise ValueError("Truth claim lacks a required semantic or decision field")
        claim_id = claim["id"]
        if not isinstance(claim_id, str) or not claim_id or claim_id in seen:
            raise ValueError("Duplicate or invalid truth claim ID")
        seen.add(claim_id)
        if claim["expected_decision"] not in ("accept", "reject") \
                or not isinstance(claim["analytical_eligible"], bool):
            raise ValueError(f"Invalid decision or eligibility: {claim_id}")
        if claim["expected_decision"] == "reject" and (claim["analytical_eligible"]
                or not claim.get("expected_refusal_reason")):
            raise ValueError(f"Refusal needs a reason and cannot be eligible: {claim_id}")
        if claim.get("source_fact_id") is not None and claim["source_fact_id"] not in fact_ids:
            raise ValueError(f"Claim maps to unknown independent source fact: {claim_id}")
        check_semantics(claim)
        check_references(claim)
    for field in ("expected_chart_membership", "expected_comparison_membership"):
        memberships = truth.get(field)
        if not isinstance(memberships, list) or any(not isinstance(group, dict)
                or not isinstance(group.get("claim_ids"), list)
                or not set(group["claim_ids"]).issubset(seen) for group in memberships):
            raise ValueError(f"Invalid {field}")
    if ready:
        candidate_key = "recorded_response_file" if manifest["category"] == "real_recorded_model" else "candidate_file"
        candidate_hash = "recorded_response_sha256" if manifest["category"] == "real_recorded_model" else "candidate_sha256"
        candidate = within_case(directory, manifest.get(candidate_key))
        if digest(candidate) != manifest.get(candidate_hash):
            raise ValueError("Recorded response or human candidate hash mismatch")
        provenance_key = "recorded_response_provenance" if manifest["category"] == "real_recorded_model" \
            else "candidate_provenance"
        if not isinstance(manifest.get(provenance_key), str) or not manifest[provenance_key].strip():
            raise ValueError("Candidate or recorded response provenance missing")
        if manifest["category"] == "real_recorded_model" and manifest.get("candidate_file") is not None:
            raise ValueError("Recorded-model case cannot have human candidate observations")
        if manifest["category"] == "real_recorded_model" and manifest.get("recorded_prompt_version") != 10:
            raise ValueError("Current recorded-model adapter supports V10 responses only")
        if manifest["category"] == "real_human_observations" and manifest.get("recorded_response_file") is not None:
            raise ValueError("Backend-only case cannot claim recorded model extraction")
        if manifest["category"] == "real_human_observations":
            candidate_data = load(candidate)
            if candidate_data.get("origin") != "human_prepared_observations" \
                    or not isinstance(candidate_data.get("claims"), list):
                raise ValueError("Human candidates must be labeled backend-only observations")
            candidate_ids = set()
            required = ("id", "label", "value", "unit", "period", "concept", "scope",
                        "metric_type", "basis", "source_context")
            for row in candidate_data["claims"]:
                if not isinstance(row, dict) or any(key not in row for key in required) \
                        or not isinstance(row["source_context"], dict) or row["id"] in candidate_ids:
                    raise ValueError("Human candidate lacks required fields or has duplicate ID")
                candidate_ids.add(row["id"])
            mapped = {claim.get("candidate_id") for claim in claims}
            if mapped != candidate_ids or None in mapped:
                raise ValueError("Every human candidate needs independent truth with matching candidate_id")
        else:
            envelope = load(candidate)
            if not isinstance(envelope.get("content"), list) or not envelope["content"]:
                raise ValueError("Recorded response must be a complete provider envelope")
            if any(not isinstance(claim.get("candidate_index"), int)
                   or claim["candidate_index"] < 0 for claim in claims):
                raise ValueError("Recorded-model truth needs explicit candidate_index mappings")
    return manifest, snapshot, truth


def attach(case_id: str, source_path: str, *, response: bool, provenance: str,
           prompt_version: int | None = None) -> dict:
    directory = case_dir(case_id)
    manifest, _, _ = validate(case_id, ready=False)
    if manifest["review_status"] != "draft":
        raise ValueError("Reviewed case is immutable; create a new case for changed inputs")
    if response != (manifest["category"] == "real_recorded_model"):
        raise ValueError("Input type conflicts with case category")
    if not provenance.strip():
        raise ValueError("Input provenance must identify its author or recording")
    if response and prompt_version != 10:
        raise ValueError("Recorded response must declare V10 prompt provenance")
    if not response and prompt_version is not None:
        raise ValueError("Human candidate observations cannot declare a model prompt version")
    original = Path(source_path).expanduser().resolve(strict=True)
    if not original.is_file():
        raise ValueError("Input must be a local file")
    payload = load(original)
    if response and (not isinstance(payload.get("content"), list) or not payload["content"]):
        raise ValueError("Recorded response must be an Anthropic provider envelope")
    if not response and payload.get("origin") != "human_prepared_observations":
        raise ValueError("Backend-only input must identify its human origin")
    name = "recorded-response.json" if response else "human-candidates.json"
    target = directory / name
    if target.exists():
        raise ValueError("Candidate input already registered; create a new case for replacements")
    with original.open("rb") as src, target.open("xb") as dst:
        shutil.copyfileobj(src, dst)
    target.chmod(0o600)
    key = "recorded_response" if response else "candidate"
    manifest[key + "_file"] = name
    manifest[key + "_sha256"] = digest(target)
    manifest[key + "_provenance"] = provenance.strip()
    if response:
        manifest["recorded_prompt_version"] = prompt_version
    updated = directory / "manifest.updated.json"
    private_json(updated, manifest)
    updated.replace(directory / "manifest.json")
    return {"case_id": case_id, "input_origin": "recorded_model" if response else "human_prepared",
            "sha256": manifest[key + "_sha256"]}


def review(case_id: str) -> dict:
    directory = case_dir(case_id)
    manifest, _, truth = validate(case_id, ready=False)
    if manifest["review_status"] != "draft" or truth["review_status"] != "human_verified" \
            or not truth.get("reviewer") or not truth.get("reviewed_at"):
        raise ValueError("Human reviewer must complete truth.json before sealing the case")
    manifest["review_status"] = "human_verified"
    manifest["truth_sha256"] = digest(directory / manifest["truth_file"])
    updated = directory / "manifest.updated.json"
    private_json(updated, manifest)
    updated.replace(directory / "manifest.json")
    try:
        validate(case_id)
    except Exception:
        manifest["review_status"] = "draft"
        private_json(updated, manifest)
        updated.replace(directory / "manifest.json")
        raise
    return {"case_id": case_id, "review_status": "human_verified",
            "reviewed_claims": len(truth["claims"])}


def score_real_run(report: dict, manifest: dict, truth: dict) -> dict:
    if report.get("case_id") != manifest["id"] or report.get("category") != manifest["category"] \
            or report.get("source_sha256") != manifest["source_sha256"] \
            or report.get("extraction_sha256") != manifest["extraction_sha256"] \
            or report.get("source_span_revision") != manifest["source_span_revision"] \
            or report.get("paid_inference_calls") != 0:
        raise ValueError("Replay provenance mismatch")
    rows = report.get("candidates")
    if not isinstance(rows, list):
        raise ValueError("Replay lacks candidate rows")
    expected_origin = "recorded_model" if manifest["category"] == "real_recorded_model" else "human_prepared"
    if manifest["category"] == "real_human_observations" and report.get("model_observations") != 0:
        raise ValueError("Human observations were miscounted as model extraction")
    if manifest["category"] == "real_recorded_model" and report.get("human_prepared_observations") != 0:
        raise ValueError("Recorded-model replay includes human candidate observations")
    if len(rows) != sum(report.get(k, -1) for k in
                        ("model_observations", "human_prepared_observations", "server_expanded_observations")):
        raise ValueError("Candidate origin counts do not reconcile")
    by_index = {row["candidate_index"]: row for row in rows}
    by_human = {row["human_candidate_id"]: row for row in rows if row.get("human_candidate_id") is not None}
    if len(by_index) != len(rows) or len(by_human) != report["human_prepared_observations"]:
        raise ValueError("Candidate mapping is ambiguous")
    if any((row["analytical_eligible"] or row["exact_cell_proven"]) and not row["source_linked"]
           for row in rows):
        raise ValueError("Analytical or cell proof lacks persisted source evidence")
    false_accept = false_reject = eligible_mismatch = 0
    fields = {key: {"mismatch": 0, "denominator": 0} for key in
              ("metric", "value", "unit", "period", "basis", "entity", "population")}
    reviewed = set()
    numeric_model = set()
    covered_facts = set()
    facts = {fact["id"]: fact for fact in truth["source_facts"]}
    for claim in truth["claims"]:
        row = by_index.get(claim["candidate_index"]) if expected_origin == "recorded_model" \
            else by_human.get(claim["candidate_id"])
        if row is None:
            raise ValueError(f"Reviewed claim missing from replay: {claim['id']}")
        if expected_origin == "human_prepared" and row["origin"] != expected_origin:
            raise ValueError("Human claim received an incorrect origin")
        if row["candidate_index"] in reviewed:
            raise ValueError("Two truth claims map to the same candidate")
        reviewed.add(row["candidate_index"])
        fact = facts.get(claim.get("source_fact_id"))
        if fact is not None and row["origin"] == "recorded_model" \
                and str(row.get("value")).strip() == str(fact["value"]).strip():
            numeric_model.add(row["candidate_index"])
            covered_facts.add(fact["id"])
        accepted = bool(row["source_linked"])
        false_accept += claim["expected_decision"] == "reject" and accepted
        false_reject += claim["expected_decision"] == "accept" and not accepted
        eligible_mismatch += bool(row["analytical_eligible"]) != claim["analytical_eligible"]
        identity = row.get("identity") or {}
        observed = {"metric": row.get("label"), "value": row.get("value"),
                    "unit": row.get("unit"), "period": row.get("period"),
                    "basis": identity.get("value_basis"), "entity": identity.get("scope"),
                    "population": identity.get("metric_population")}
        for field, result in fields.items():
            if observed[field] is None:
                continue
            result["denominator"] += 1
            result["mismatch"] += str(observed[field]).strip().casefold() \
                != str(claim[field]).strip().casefold()
    negatives = sum(c["expected_decision"] == "reject" for c in truth["claims"])
    positives = len(truth["claims"]) - negatives
    return {"case_id": manifest["id"], "category": manifest["category"],
            "feature_enabled": report["feature_enabled"],
            "observations": {"recorded_model": report["model_observations"],
                             "human_prepared": report["human_prepared_observations"],
                             "server_expanded": report["server_expanded_observations"]},
            "reviewed_claims": len(reviewed), "unreviewed_predictions": len(rows) - len(reviewed),
            "numerically_correct_model_observations": {"count": len(numeric_model),
                                                       "denominator": report["model_observations"],
                                                       "unknown": report["model_observations"] - len(numeric_model)},
            "numerically_matched_source_facts": {"count": len(covered_facts),
                                                 "denominator": len(facts)},
            "source_linked": {"count": sum(bool(row["source_linked"]) for row in rows),
                              "denominator": len(rows)},
            "exact_cell_proven": {"count": sum(bool(row["exact_cell_proven"]) for row in rows),
                                  "denominator": len(rows)},
            "analytically_eligible": {"count": sum(bool(row["analytical_eligible"]) for row in rows),
                                      "denominator": len(rows)},
            "false_acceptance": {"count": false_accept, "denominator": negatives},
            "false_rejection": {"count": false_reject, "denominator": positives},
            "analytical_eligibility_disagreement": {"count": eligible_mismatch,
                                                       "denominator": len(reviewed)},
            "claim_field_disagreements": fields,
            "charts": report["chart_count"], "comparisons": report["comparison_count"],
            "api_evidence_mappings": report["api_evidence_count"],
            "latency_ms": report["latency_ms"], "paid_inference_calls": 0,
            "candidate_decisions": [{"candidate_index": row["candidate_index"],
                                     "origin": row["origin"], "human_candidate_id": row["human_candidate_id"],
                                     "source_linked": row["source_linked"],
                                     "exact_cell_proven": row["exact_cell_proven"],
                                     "analytical_eligible": row["analytical_eligible"],
                                     "rejection": row["rejection"]} for row in rows]}


def run_case(case_id: str, repeat: int) -> dict:
    if not 2 <= repeat <= 10:
        raise ValueError("Repeat must be between 2 and 10")
    manifest, _, truth = validate(case_id)
    from tools.evidence_first_gate import run_test
    os.umask(0o077)
    OUTPUT.mkdir(mode=0o700, parents=True, exist_ok=True)
    environment = os.environ.copy()
    environment.update({"APP_DEMO_MODE": "0", "DB_CONNECTION": "pgsql", "DB_HOST": "127.0.0.1",
                        "DB_PORT": "55449", "DB_DATABASE": "docintel_v9_eval", "DB_URL": "",
                        "DOCINTEL_REAL_CORPUS_RUN": "1", "DOCINTEL_CORPUS_CASE_ID": case_id,
                        "DOCINTEL_V10_EVAL_LIVE": "0", "DOCINTEL_V10_EVAL_APPROVED": "0",
                        "DOCINTEL_V9_EVAL_LIVE": "0"})
    runs = {"off": [], "on": []}
    for index in range(repeat):
        for mode in (("off", "on") if index % 2 == 0 else ("on", "off")):
            path = OUTPUT / f"{case_id}-{mode}-{time.time_ns()}.json"
            environment["DOCINTEL_CORPUS_FEATURE"] = mode
            environment["DOCINTEL_CORPUS_RESULT"] = str(path)
            run_test("tests/Feature/RealDocumentCorpusReplayTest.php", [], environment, timeout=300)
            if not path.is_file():
                raise RuntimeError("Guarded replay did not produce its local result")
            score = score_real_run(load(path), manifest, truth)
            if score["feature_enabled"] != (mode == "on"):
                raise ValueError("Replay used the wrong feature state")
            runs[mode].append(score)
    summary = {"schema_version": 1, "case_id": case_id, "category": manifest["category"],
               "source_sha256": manifest["source_sha256"],
               "source_span_revision": manifest["source_span_revision"],
               "reviewed_claims": len(truth["claims"]), "feature_off": runs["off"],
               "feature_on": runs["on"], "actual_paid_inference_calls": 0,
               "real_document_count": 1,
               "generalization_verdict": "not_assessable_from_one_real_document"}
    stable_keys = ("observations", "reviewed_claims", "unreviewed_predictions", "source_linked",
                   "exact_cell_proven", "analytically_eligible", "false_acceptance", "false_rejection",
                   "charts", "comparisons", "api_evidence_mappings", "candidate_decisions")
    summary["nondeterministic"] = {mode: any(
        tuple((key, json.dumps(run[key], sort_keys=True)) for key in stable_keys)
        != tuple((key, json.dumps(runs[mode][0][key], sort_keys=True)) for key in stable_keys)
        for run in runs[mode][1:]) for mode in ("off", "on")}
    path = OUTPUT / f"real-{case_id}-{time.time_ns()}.json"
    private_json(path, summary)
    first_off, first_on = runs["off"][0], runs["on"][0]
    lines = ["# Real-document offline evidence gate", "",
             f"Case: `{case_id}`. Category: `{manifest['category']}`. Reviewed claims: {len(truth['claims'])}.",
             f"Source SHA-256: `{manifest['source_sha256']}`. Paid inference calls: 0.", "",
             "| Measure | Feature off | Feature on |", "|---|---:|---:|"]
    measures = (("Recorded model observations", "observations", "recorded_model"),
                ("Human-prepared observations", "observations", "human_prepared"),
                ("Server-expanded observations", "observations", "server_expanded"),
                ("Numerically correct model observations", "numerically_correct_model_observations", "count"),
                ("Source linked", "source_linked", "count"),
                ("Exact cell proven", "exact_cell_proven", "count"),
                ("Analytically eligible", "analytically_eligible", "count"),
                ("False acceptances", "false_acceptance", "count"),
                ("False rejections", "false_rejection", "count"))
    for label, key, field in measures:
        def fmt(run: dict) -> str:
            item = run[key]
            return f"{item[field]}/{item['denominator']}" if "denominator" in item else str(item[field])
        lines.append(f"| {label} | {fmt(first_off)} | {fmt(first_on)} |")
    lines += ["", f"Unreviewed predictions: off {first_off['unreviewed_predictions']}; "
              f"on {first_on['unreviewed_predictions']}. They are unknown, not correct.",
              f"Charts: off {first_off['charts']}, on {first_on['charts']}; comparisons: "
              f"off {first_off['comparisons']}, on {first_on['comparisons']}.",
              f"API evidence mappings: off {first_off['api_evidence_mappings']}, "
              f"on {first_on['api_evidence_mappings']}.",
              f"Runs per state: {repeat}. Output variation: off={summary['nondeterministic']['off']}, "
              f"on={summary['nondeterministic']['on']}.",
              "Generalization verdict: not assessable from one document.", ""]
    human = path.with_suffix(".md")
    with human.open("x", encoding="utf-8") as stream:
        stream.write("\n".join(lines))
    human.chmod(0o600)
    return {"case_id": case_id, "category": manifest["category"],
            "result": "passed", "report": str(path.relative_to(ROOT)),
            "human_report": str(human.relative_to(ROOT)), "replay_pairs": repeat,
            "reviewed_claims": len(truth["claims"])}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    create = sub.add_parser("intake", help="Copy an authorized local original into the ignored corpus")
    create.add_argument("--id", required=True)
    create.add_argument("--source", required=True)
    create.add_argument("--category", required=True, choices=CATEGORIES)
    create.add_argument("--authorization", required=True)
    create.add_argument("--provenance", required=True)
    create.add_argument("--reviewer", required=True)
    check = sub.add_parser("validate", help="Verify source, extraction and completed human truth")
    check.add_argument("--id", required=True)
    check.add_argument("--draft", action="store_true")
    added = sub.add_parser("attach", help="Register a frozen response or human candidate file")
    added.add_argument("--id", required=True)
    added.add_argument("--file", required=True)
    added.add_argument("--response", action="store_true")
    added.add_argument("--provenance", required=True)
    added.add_argument("--prompt-version", type=int)
    sealed = sub.add_parser("review", help="Validate and seal completed human truth")
    sealed.add_argument("--id", required=True)
    replay = sub.add_parser("run", help="Run both feature states against the guarded local database")
    replay.add_argument("--id", required=True)
    replay.add_argument("--repeat", type=int, default=2)
    listing = sub.add_parser("list", help="List local cases without reading document contents")
    args = parser.parse_args()
    if args.command == "intake":
        result = intake(args)
    elif args.command == "validate":
        manifest, _, truth = validate(args.id, ready=not args.draft)
        result = {"case_id": args.id, "category": manifest["category"], "format": manifest["format"],
                  "review_status": truth["review_status"], "claims": len(truth["claims"]), "result": "valid"}
    elif args.command == "attach":
        result = attach(args.id, args.file, response=args.response, provenance=args.provenance,
                        prompt_version=args.prompt_version)
    elif args.command == "review":
        result = review(args.id)
    elif args.command == "run":
        result = run_case(args.id, args.repeat)
    else:
        result = {"cases": []}
        for path in sorted(CORPUS.glob("*/manifest.json")):
            row = load(path)
            result["cases"].append({"id": row.get("id"), "category": row.get("category"),
                                    "format": row.get("format"), "review_status": row.get("review_status")})
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (OSError, ValueError, RuntimeError, subprocess.TimeoutExpired) as exc:
        print(json.dumps({"result": "failed", "reason": str(exc)}), file=sys.stderr)
        raise SystemExit(1)
