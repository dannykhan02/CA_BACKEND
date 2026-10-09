"""Deterministic, variant-blind review packet from one call's accepted-record JSON list.

Usage: python3 precision-sample.py accepted.json blind-packet.json private-key.json
The input must be the accepted records for exactly one call, in accepted order.
Keep the private key and input filenames away from the blinded reviewer.
"""
import json
import pathlib
import random
import sys

if len(sys.argv) != 4:
    raise SystemExit(__doc__)
source, packet_path, key_path = map(pathlib.Path, sys.argv[1:])
records = json.loads(source.read_text())
if not isinstance(records, list):
    raise SystemExit('accepted records must be a JSON list')
indices = random.Random(20261009).sample(range(len(records)), min(20, len(records)))
allowed = ('kind', 'label', 'value', 'subject', 'reference', 'unit', 'period',
           'date_type', 'due_date', 'metric_type', 'value_basis', 'aggregation',
           'quantity_kind', 'quote', 'evidence_ids', 'evidence', 'sources')
packet = [{'sample_id': f'S{i+1:02d}', 'record': {k: records[index][k]
           for k in allowed if k in records[index]}} for i, index in enumerate(indices)]
key = {'seed': 20261009, 'accepted_count': len(records),
       'samples': [{'sample_id': f'S{i+1:02d}', 'accepted_index_zero_based': index}
                   for i, index in enumerate(indices)]}
packet_path.write_text(json.dumps(packet, indent=2, ensure_ascii=False) + '\n')
key_path.write_text(json.dumps(key, indent=2) + '\n')
