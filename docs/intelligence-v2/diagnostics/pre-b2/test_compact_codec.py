import copy
import json
import pathlib
import random
import subprocess
import sys
import unittest

from compact_codec import CompactEvidenceExpander as Codec, CompactExpansionError

DIAG = pathlib.Path(__file__).resolve().parent.parent
FILES = sorted((DIAG / "unicef-4call").glob("*.response.json")) + sorted((DIAG / "experiment-flags/paid-study").glob("*.response.json"))


def canonical(path):
    return json.loads(json.loads(path.read_text())["content"][0]["text"])


class CodecTest(unittest.TestCase):
    def test_all_saved_round_trip_and_order(self):
        total = 0
        for path in FILES:
            original = canonical(path)
            total += len(original["records"])
            for short in (True, False):
                expanded = Codec.expand(Codec.encode(original, short), short)
                self.assertEqual(expanded, original, path.name)
                self.assertEqual(len(expanded["records"]), len(original["records"]))
                self.assertEqual([x["evidence_ids"] for x in expanded["records"]], [x["evidence_ids"] for x in original["records"]])
        self.assertEqual(total, 912)

    def test_fail_closed_and_fuzz(self):
        item = Codec.encode(canonical(FILES[0]))["m"][0]
        bad = []
        for key in ("v", "k", "e", "cf"):
            x = copy.deepcopy(item); x.pop(key); bad.append(x)
        for key, value in (("bogus", "x"), ("v", 12), ("e", "E001"), ("k", "new_kind"), ("u", 5), ("cf", True)):
            x = copy.deepcopy(item); x[key] = value; bad.append(x)
        rng = random.Random(811)
        for _ in range(100):
            x = copy.deepcopy(item)
            x["unknown_"+str(rng.randrange(10000))] = rng.choice([{}, [], 42, False])
            bad.append(x)
        for x in bad:
            with self.assertRaises(CompactExpansionError):
                Codec.expand({"m": [x]})

    def test_truncated_compact_salvage(self):
        encoded = json.dumps(Codec.encode(canonical(FILES[0])), separators=(",", ":"), ensure_ascii=False)
        first_end = encoded.find("},{")
        self.assertGreater(first_end, 0)
        rows = Codec.salvage(encoded[:first_end+10])
        self.assertEqual(len(rows), 1)
        self.assertEqual(len(Codec.expand({"m": rows})["records"]), 1)
        self.assertEqual(Codec.salvage('{"m":[{"v":"unfinished'), [])

    def test_fresh_process_determinism(self):
        code = "import json,sys; from compact_codec import CompactEvidenceExpander as C; x=json.load(sys.stdin); print(json.dumps(C.expand(C.encode(x)),sort_keys=True,ensure_ascii=False))"
        source = canonical(FILES[0])
        outputs = [subprocess.check_output([sys.executable, "-c", code], input=json.dumps(source).encode(), cwd=pathlib.Path(__file__).parent)
                   for _ in range(2)]
        self.assertEqual(outputs[0], outputs[1])


if __name__ == "__main__":
    unittest.main()
