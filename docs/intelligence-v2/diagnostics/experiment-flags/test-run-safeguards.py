"""Offline tests for the paid runner. Transport is always an in-process fake."""
import importlib.util
import json
import pathlib
import tempfile
import unittest
from decimal import Decimal

path = pathlib.Path(__file__).with_name('collector-study-run.py')
spec = importlib.util.spec_from_file_location('collector_study_run', path)
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


def response(body, key):
    return (200, 'fake-request', json.dumps({'id': 'fake-request', 'stop_reason': 'end_turn',
        'usage': {'input_tokens': 1, 'output_tokens': 1}}).encode(), None)


class SafeguardTests(unittest.TestCase):
    def test_exact_frozen_requests_and_cost(self):
        for chunk, variant in runner.FROZEN:
            body, drift = runner.gate_request(chunk, variant)
            self.assertIsNone(drift)
            self.assertEqual(runner.sha(body), runner.FROZEN[(chunk, variant)])
        with tempfile.TemporaryDirectory() as tmp:
            seen = []
            result = runner.run(output=pathlib.Path(tmp), post=lambda b, k: (seen.append(b) or response(b, k)), api_key='fake')
            self.assertEqual(result['status'], 'COMPLETE')
            self.assertEqual(len(seen), 12)
            self.assertEqual(len(list(pathlib.Path(tmp).glob('*.metadata.json'))), 12)
            self.assertLess(Decimal(result['actual_cost_usd']), runner.LIMIT)

    def test_one_failure_keeps_missing_cell_and_no_replacement(self):
        with tempfile.TemporaryDirectory() as tmp:
            seen = []
            def post(body, key):
                seen.append(body)
                return (None, None, None, 'fake timeout') if len(seen) == 1 else response(body, key)
            result = runner.run(output=pathlib.Path(tmp), post=post, api_key='fake')
            self.assertEqual(result['status'], 'STUDY_COMPLETE_WITH_MISSING_CELL')
            self.assertEqual(len(seen), 12)
            first = json.loads((pathlib.Path(tmp) / 'unicef_reduced-C1.metadata.json').read_text())
            self.assertEqual(first['status'], 'failed')
            self.assertIsNotNone(first['unknown_cost_reserve_usd'])

    def test_non_200_or_two_failures_stops(self):
        for failure in ('non200', 'two'):
            with self.subTest(failure=failure), tempfile.TemporaryDirectory() as tmp:
                seen = []
                def post(body, key):
                    seen.append(body)
                    if failure == 'non200' and len(seen) == 1:
                        return 429, 'fake-request', b'{"error":"rate limit"}', 'HTTP 429'
                    if failure == 'two' and len(seen) <= 2:
                        return None, None, None, 'fake timeout'
                    return response(body, key)
                result = runner.run(output=pathlib.Path(tmp), post=post, api_key='fake')
                self.assertEqual(result['status'], 'STUDY_INCOMPLETE')
                self.assertEqual(len(seen), 1 if failure == 'non200' else 2)
                runner.run(output=pathlib.Path(tmp), post=post, api_key='fake')
                self.assertEqual(len(seen), 1 if failure == 'non200' else 2)

    def test_budget_stops_before_transport(self):
        with tempfile.TemporaryDirectory() as tmp:
            old = runner.LIMIT
            runner.LIMIT = Decimal('0.01')
            try:
                result = runner.run(output=pathlib.Path(tmp), post=lambda b, k: self.fail('sent'), api_key='fake')
                self.assertEqual(result['status'], 'BUDGET_STOP')
            finally:
                runner.LIMIT = old

    def test_request_drift_blocks_send(self):
        with tempfile.TemporaryDirectory() as tmp:
            base = pathlib.Path(tmp)
            (base / 'requests').mkdir()
            (base / 'requests' / 'unicef_reduced-A-CONTROL.json').write_bytes(b'{}')
            body, drift = runner.gate_request('unicef_reduced', 'CONTROL', base)
            self.assertIsNone(body)
            self.assertEqual(drift['status'], 'REQUEST_DRIFT')
            self.assertEqual(drift['expected_sha256'], runner.FROZEN[('unicef_reduced', 'CONTROL')])
            self.assertEqual(drift['actual_sha256'], runner.sha(b'{}'))


if __name__ == '__main__':
    unittest.main()
