"""Prepare, lock, then unblind the Collector study's precision review.

prepare manifest.json [outdir]
freeze [outdir]
unblind [outdir]

Manifest: [{"cell":"unicef_reduced-C1", "chunk":"unicef_reduced",
            "variant":"CONTROL", "run":1, "accepted_file":"/path/to/accepted.json"}, ...]
Only successful calls belong in the manifest. Keep mapping.json away from the reviewer.
"""
import hashlib
import json
import pathlib
import random
import re
import sys
from collections import defaultdict

BASE = pathlib.Path(__file__).resolve().parent
SEED = 20261009
JUDGMENTS = {'SUPPORTED', 'PARTIALLY_SUPPORTED', 'UNSUPPORTED', 'NOISE'}
RECORD_FIELDS = ('kind', 'label', 'value', 'subject', 'reference', 'unit', 'period',
    'date_type', 'due_date', 'metric_type', 'value_basis', 'aggregation',
    'quantity_kind', 'quote', 'evidence_ids')


def digest(raw):
    return hashlib.sha256(raw).hexdigest()


def write(path, value):
    if path.exists():
        raise FileExistsError(f'refusing to overwrite frozen artifact: {path}')
    path.write_text(json.dumps(value, indent=2, ensure_ascii=False) + '\n')


def source_spans(chunk):
    if chunk not in ('unicef_reduced', 'india_wash'):
        raise ValueError(f'unknown chunk: {chunk}')
    source = (BASE / f'{chunk}.source.txt').read_text()
    parts = re.split(r'(?m)^\[(E\d+)\]\n', source)
    return {parts[i]: parts[i+1].removesuffix('\n') for i in range(1, len(parts)-1, 2)}


def prepare(manifest_file, output):
    output.mkdir(parents=True, exist_ok=True)
    manifest = json.loads(pathlib.Path(manifest_file).read_text())
    if not isinstance(manifest, list) or not manifest:
        raise ValueError('manifest must be a nonempty list of successful calls')
    packet, mapping = [], []
    seen_cells = set()
    for entry in manifest:
        cell = entry['cell']
        if cell in seen_cells:
            raise ValueError(f'duplicate cell: {cell}')
        seen_cells.add(cell)
        chunk = entry['chunk']
        spans = source_spans(chunk)
        accepted = json.loads(pathlib.Path(entry['accepted_file']).read_text())
        if not isinstance(accepted, list):
            raise ValueError(f'{cell}: accepted_file must contain a JSON list')
        indices = random.Random(SEED).sample(range(len(accepted)), min(20, len(accepted)))
        for index in indices:
            record = accepted[index]
            ids = record.get('evidence_ids')
            if not isinstance(ids, list) or not ids or any(k not in spans for k in ids):
                raise ValueError(f'{cell}: sampled record has an unknown citation')
            packet.append({'record': {k: record[k] for k in RECORD_FIELDS if k in record},
                'source': [{'evidence_id': key, 'text': spans[key]} for key in ids]})
            mapping.append({'cell': cell, 'chunk': chunk, 'variant': entry['variant'],
                'run': entry['run'], 'accepted_index_zero_based': index})
    order = list(range(len(packet)))
    random.Random(SEED).shuffle(order)
    blind, private = [], []
    for number, original in enumerate(order, 1):
        sample_id = f'S{number:04d}'
        blind.append({'sample_id': sample_id, **packet[original]})
        private.append({'sample_id': sample_id, **mapping[original]})
    blind_path = output / 'precision-review-blinded.json'
    write(blind_path, blind)
    write(output / 'precision-review-private-mapping.json',
          {'seed': SEED, 'blinded_sha256': digest(blind_path.read_bytes()), 'mapping': private})
    return len(blind)


def freeze(output):
    packet = json.loads((output / 'precision-review-blinded.json').read_text())
    raw = (output / 'precision-review-judgments.json').read_bytes()
    judgments = json.loads(raw)
    if not isinstance(judgments, list) or len(judgments) != len(packet):
        raise ValueError('judgment count must equal blind packet count')
    expected = {x['sample_id'] for x in packet}
    actual = {x.get('sample_id') for x in judgments if isinstance(x, dict)}
    if expected != actual or len(actual) != len(judgments):
        raise ValueError('judgment IDs must exactly match the blind packet')
    if any(x.get('judgment') not in JUDGMENTS for x in judgments):
        raise ValueError('invalid or missing judgment')
    report = {'judgments_sha256': digest(raw),
              'blinded_sha256': digest((output / 'precision-review-blinded.json').read_bytes()),
              'judgment_count': len(judgments), 'mapping_applied': False,
              'reviewer_disclosure': 'The precision reviewer was an AI agent using mechanical blinding. The agent conducting the wider experiment had access to study context, so this is not equivalent to an independent human-blinded review.'}
    write(output / 'precision-review-report.json', report)
    return report


def unblind(output):
    report_path = output / 'precision-review-report.json'
    report = json.loads(report_path.read_text())
    if report.get('mapping_applied') is not False:
        raise ValueError('judgments must be frozen before unblinding')
    raw = (output / 'precision-review-judgments.json').read_bytes()
    if digest(raw) != report['judgments_sha256']:
        raise ValueError('judgments changed after hash freeze')
    blind_hash = digest((output / 'precision-review-blinded.json').read_bytes())
    if blind_hash != report['blinded_sha256']:
        raise ValueError('blind packet changed after hash freeze')
    mapping = json.loads((output / 'precision-review-private-mapping.json').read_text())
    if mapping['blinded_sha256'] != blind_hash:
        raise ValueError('private mapping belongs to another packet')
    by_id = {x['sample_id']: x for x in json.loads(raw)}
    counts = defaultdict(lambda: defaultdict(int))
    cells = defaultdict(lambda: defaultdict(int))
    for item in mapping['mapping']:
        judgment = by_id[item['sample_id']]['judgment']
        counts[(item['chunk'], item['variant'])][judgment] += 1
        cells[item['cell']][judgment] += 1
    def summarize(counter):
        n = sum(counter.values())
        return {'sampled': n, 'judgments': dict(counter),
            'supported_precision': counter['SUPPORTED']/n if n else None,
            'supported_plus_partial': (counter['SUPPORTED']+counter['PARTIALLY_SUPPORTED'])/n if n else None}
    result = {'judgments_sha256': report['judgments_sha256'],
        'reviewer_disclosure': report['reviewer_disclosure'],
        'by_chunk_variant': {f'{k[0]}:{k[1]}': summarize(v) for k, v in sorted(counts.items())},
        'by_cell': {k: summarize(v) for k, v in sorted(cells.items())}}
    write(output / 'precision-review-unblinded.json', result)
    return result


if __name__ == '__main__':
    if len(sys.argv) < 2:
        raise SystemExit(__doc__)
    command = sys.argv[1]
    directory = pathlib.Path(sys.argv[3] if command == 'prepare' and len(sys.argv) > 3
                             else sys.argv[2] if command != 'prepare' and len(sys.argv) > 2
                             else BASE / 'precision-review')
    if command == 'prepare' and len(sys.argv) >= 3:
        print(prepare(sys.argv[2], directory))
    elif command == 'freeze':
        print(json.dumps(freeze(directory), indent=2))
    elif command == 'unblind':
        print(json.dumps(unblind(directory), indent=2))
    else:
        raise SystemExit(__doc__)
