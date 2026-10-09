import unittest

from candidate_inventory import CandidateInventory, represent


class CandidateInventoryTest(unittest.TestCase):
    def test_classes_currency_and_chart(self):
        source = "[E001]\n40% 12.5 per cent $97.4m USD 170.5 million KES 2.1bn 10m 2.1bn 2024 42\n[E002]\n10% 20%\n"
        rows = CandidateInventory().detect(source, {"E002"})
        self.assertEqual([x["candidate_class"] for x in rows[:8]],
                         ["percentage", "percentage", "currency", "currency", "currency", "scaled_quantity", "scaled_quantity", "diagnostic_year"])
        self.assertEqual(rows[2]["currency_source_type"], "symbol_ambiguous")
        self.assertEqual(rows[3]["currency"], "USD")
        self.assertEqual(rows[4]["canonical_numeric"], "2100000000.0")
        self.assertFalse(rows[-1]["headline_eligible"])

    def test_repeated_occurrences_indeterminate(self):
        rows = CandidateInventory().detect("[E001]\n10% and 10%\n")
        matched = represent(rows, [{"kind": "metric", "value": "10%", "evidence_ids": ["E001"]}])
        self.assertEqual([r["status"] for r in matched], ["INDETERMINATE", "INDETERMINATE"])

    def test_rejected_and_text_only(self):
        rows = CandidateInventory().detect("[E001]\n40%\n")
        self.assertEqual(represent(rows, [{"kind": "metric", "value": "other", "reference": "40%", "evidence_ids": ["E001"]}])[0]["status"], "MENTIONED_IN_TEXT_ONLY")
        self.assertEqual(represent(rows, [], [{"evidence_ids": ["E001"], "rejection_reason": "invalid_schema"}])[0]["status"], "UNREPRESENTED")


if __name__ == "__main__":
    unittest.main()
