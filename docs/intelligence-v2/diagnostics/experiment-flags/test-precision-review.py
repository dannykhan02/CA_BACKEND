"""Offline smoke tests for mechanical blinding and judgment hash lock."""
import importlib.util
import json
import pathlib
import tempfile
import unittest

path = pathlib.Path(__file__).with_name('precision-review.py')
spec = importlib.util.spec_from_file_location('precision_review', path)
review = importlib.util.module_from_spec(spec)
spec.loader.exec_module(review)


class PrecisionReviewTests(unittest.TestCase):
    def test_blind_freeze_and_unblind(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = pathlib.Path(tmp)
            cells = []
            for cell, chunk, variant, citation in [('unicef_reduced-C1', 'unicef_reduced', 'CONTROL', 'E107'),
                                                    ('india_wash-K1', 'india_wash', 'COLLECTOR_ONLY', 'E201')]:
                accepted = [{'kind': 'metric', 'label': f'claim {i}', 'value': '1',
                             'evidence_ids': [citation], 'prompt_version': 'SECRET',
                             'variant': variant, 'run': 1} for i in range(24)]
                file = root / f'{cell}.json'
                file.write_text(json.dumps(accepted))
                cells.append({'cell': cell, 'chunk': chunk, 'variant': variant,
                              'run': 1, 'accepted_file': str(file)})
            manifest = root / 'manifest.json'
            manifest.write_text(json.dumps(cells))
            out = root / 'review'
            self.assertEqual(review.prepare(manifest, out), 40)
            blind = json.loads((out / 'precision-review-blinded.json').read_text())
            self.assertEqual(len(blind), 40)
            text = (out / 'precision-review-blinded.json').read_text()
            for forbidden in ('COLLECTOR_ONLY', 'CONTROL', 'prompt_version', 'SECRET', 'unicef_reduced-C1'):
                self.assertNotIn(forbidden, text)
            self.assertTrue(all(row['source'][0]['text'] for row in blind))
            judgments = [{'sample_id': row['sample_id'], 'judgment': 'SUPPORTED'} for row in blind]
            judgments_file = out / 'precision-review-judgments.json'
            original = json.dumps(judgments).encode()
            judgments_file.write_bytes(original)
            lock = review.freeze(out)
            self.assertEqual(lock['judgments_sha256'], review.digest(original))
            judgments_file.write_bytes(original + b' ')
            with self.assertRaises(ValueError):
                review.unblind(out)
            judgments_file.write_bytes(original)
            result = review.unblind(out)
            self.assertEqual(len(result['by_chunk_variant']), 2)
            self.assertTrue(all(x['supported_precision'] == 1.0 for x in result['by_chunk_variant'].values()))


if __name__ == '__main__':
    unittest.main()
