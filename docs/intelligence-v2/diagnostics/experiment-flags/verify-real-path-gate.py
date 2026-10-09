"""Rebuild Control/Collector requests twice each with fake HTTP; never calls Anthropic.

If CLI PHP lacks pdo_sqlite, set PHP_SQLITE_EXTENSION to the local extension path.
"""
import hashlib
import json
import os
import pathlib
import subprocess

root = pathlib.Path(__file__).resolve().parents[4]
directory = pathlib.Path(__file__).resolve().parent
probe = directory.parent / 'extraction-experiment-probe.php'
php = ['php']
if os.environ.get('PHP_SQLITE_EXTENSION'):
    php += ['-d', 'extension=' + os.environ['PHP_SQLITE_EXTENSION']]
report = {}
for study in ('unicef_reduced', 'india_wash'):
    report[study] = {}
    for variant in ('CONTROL', 'COLLECTOR_ONLY'):
        runs = []
        for process in ('A', 'B'):
            result = subprocess.run(php + [str(probe), process, variant, study],
                                    cwd=root, text=True, capture_output=True, check=True)
            metadata = json.loads(result.stdout)
            assert metadata['fake_http_requests'] == 1
            assert metadata['anthropic_network_calls'] == 0
            assert metadata['row_counts_identical']
            assert metadata['queue_dispatches'] == metadata['billing_events'] == 0
            assert metadata['error'] is None
            body = (directory / 'requests' / f'{study}-{process}-{variant}.json').read_bytes()
            assert hashlib.sha256(body).hexdigest() == metadata['request_sha256']
            runs.append(metadata)
        assert runs[0]['request_sha256'] == runs[1]['request_sha256']
        x = runs[0]
        report[study][variant] = {k: x[k] for k in ('request_sha256', 'request_bytes',
            'prompt_version', 'max_tokens', 'max_records', 'source_sha256', 'span_map_sha256')}
        report[study][variant].update(fake_http_requests_per_process=1, anthropic_network_calls=0,
            row_counts_identical=True, queue_dispatches=0, billing_events=0)
    control = json.loads((directory / 'requests' / f'{study}-A-CONTROL.json').read_text())
    collector = json.loads((directory / 'requests' / f'{study}-A-COLLECTOR_ONLY.json').read_text())
    assert control['system'][0]['text'] != collector['system'][0]['text']
    control['system'][0]['text'] = collector['system'][0]['text']
    assert control == collector, f'{study}: request isolation failed'
(directory / 'real-path-gate.json').write_text(json.dumps(report, indent=2) + '\n')
print(json.dumps(report, indent=2))
