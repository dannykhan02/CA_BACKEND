"""Run existing downstream PHP replay on compact-expanded saved responses."""
import json
import pathlib
import subprocess
import tempfile
from compact_codec import CompactEvidenceExpander as Codec

HERE = pathlib.Path(__file__).resolve().parent
STUDY = HERE.parent / "experiment-flags"
PAID = STUDY / "paid-study"
REPLAY = STUDY / "collector-study-replay.php"


def main():
    results = []
    for path in sorted(PAID.glob("*.response.json")):
        cell = path.name.removesuffix(".response.json")
        provider = json.loads(path.read_text())
        canonical = json.loads(provider["content"][0]["text"])
        expanded = Codec.expand(Codec.encode(canonical))
        assert expanded == canonical
        provider["content"][0]["text"] = json.dumps(expanded, separators=(",", ":"), ensure_ascii=False)
        with tempfile.NamedTemporaryFile(mode="w", suffix=".json", delete=True) as temp:
            json.dump(provider, temp, ensure_ascii=False)
            temp.flush()
            output = subprocess.check_output(["php", str(REPLAY), cell, temp.name], text=True)
        current = json.loads(output)
        saved = json.loads((PAID / "analysis" / (cell + ".replay.json")).read_text())
        saved_acc = json.loads((PAID / "analysis" / (cell + ".accepted.json")).read_text())
        actual = current["replay"]
        actual.pop("response_sha256")
        saved.pop("response_sha256")
        equal_replay = actual == saved
        equal_accepted = current["accepted"] == saved_acc
        results.append({"cell": cell, "replay_equal": equal_replay, "accepted_equal": equal_accepted,
                        "validation_accept_reject_equal": actual["validation"] == saved["validation"] and actual["accepted_count"] == saved["accepted_count"],
                        "typed_provenance_identity_key_figure_equal": actual["trace"] == saved["trace"] and actual["origin"] == saved["origin"] and actual["key_figure_eligible_count"] == saved["key_figure_eligible_count"]})
    artifact = {"cells": results, "all_pass": all(all(v for k,v in r.items() if k != "cell") for r in results),
                "note": "Existing offline PHP replay was invoked with compact-expanded provider content. Raw response hash excluded from comparison because serializing equivalent JSON changes bytes; future store design records both raw compact and expanded canonical hashes."}
    (HERE / "compact-codec-equivalence.json").write_text(json.dumps(artifact, indent=2)+"\n")
    print("cells", len(results), "all pass", artifact["all_pass"])


if __name__ == "__main__": main()
