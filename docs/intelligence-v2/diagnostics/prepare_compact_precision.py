"""Mechanically blind a frozen 20-per-cell accepted-record sample."""
import hashlib
import json
import pathlib
import random

HERE = pathlib.Path(__file__).resolve().parent / 'compact-parity-study'
REVIEW = HERE / 'precision-review'
REVIEW.mkdir(exist_ok=True)
if any(REVIEW.iterdir()):
    raise SystemExit('Precision packet already exists; no resampling')
rng = random.Random(20261009)
entries = []
for chunk in ('unicef_reduced', 'india_wash'):
    for arm in 'AB':
        for run in range(1, 4):
            cell = f'{chunk}-{arm}{run}'
            accepted = json.loads((HERE / 'analysis' / f'{cell}.replay.json').read_text())['accepted']
            for index in rng.sample(range(len(accepted)), min(20, len(accepted))):
                record = accepted[index]
                entries.append({'cell': cell, 'accepted_index': index, 'record': record,
                                'source': [{'evidence_id': e['span_id'], 'text': e['text']} for e in record.get('evidence', [])]})
rng.shuffle(entries)
packet = []
mapping = []
for number, entry in enumerate(entries, 1):
    sample = f'S{number:04d}'
    packet.append({'sample_id': sample, 'record': entry['record'], 'source': entry['source']})
    mapping.append({'sample_id': sample, 'cell': entry['cell'], 'accepted_index_zero_based': entry['accepted_index']})
raw = (json.dumps(packet, indent=2, ensure_ascii=False) + '\n').encode()
(REVIEW / 'precision-review-blinded.json').write_bytes(raw)
(REVIEW / 'precision-review-private-mapping.json').write_text(json.dumps({'seed': 20261009,
    'packet_sha256': hashlib.sha256(raw).hexdigest(), 'mapping': mapping}, indent=2) + '\n')
print('blinded samples', len(packet), 'packet sha256', hashlib.sha256(raw).hexdigest())
