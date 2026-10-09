"""Offline parity metrics. Requires frozen blinded judgments before unblinding."""
import hashlib
import json
import pathlib
import statistics
from collections import Counter, defaultdict
from decimal import Decimal

HERE = pathlib.Path(__file__).resolve().parent
OUT = HERE / 'compact-parity-study'
REVIEW = OUT / 'precision-review'
METRICS = OUT / 'analysis' / 'cell-metrics.json'


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def load(path):
    return json.loads(path.read_text())


def save(path, value):
    path.write_text(json.dumps(value, indent=2, ensure_ascii=False) + '\n')


def stats(values):
    return {'mean': statistics.mean(values), 'minimum': min(values), 'maximum': max(values)}


def summarize(cells, names):
    fields = ('headline_explicit_quantity_representation', 'gold_recalled',
              'period_populated_rate', 'unit_populated_rate', 'currency_populated_rate',
              'supported_precision', 'rejection_rate', 'grounding_failure_rate',
              'parser_failure', 'structured_output_failure', 'truncated')
    result = {}
    for field in fields:
        a = [float(cells[name][field]) for name in names if cells[name]['arm'] == 'A']
        b = [float(cells[name][field]) for name in names if cells[name]['arm'] == 'B']
        assert len(a) == len(b) and a
        difference = statistics.mean(b) - statistics.mean(a)
        limit = {'headline_explicit_quantity_representation': -0.05, 'gold_recalled': -1,
                 'period_populated_rate': -0.05, 'unit_populated_rate': -0.05,
                 'currency_populated_rate': -0.05, 'supported_precision': -0.05,
                 'rejection_rate': 0.05, 'grounding_failure_rate': 0.02}.get(field)
        if field in ('parser_failure', 'structured_output_failure', 'truncated'):
            passed = not any(b) or any(a)
        elif field in ('rejection_rate', 'grounding_failure_rate'):
            passed = difference <= limit
        elif field == 'gold_recalled':
            passed = difference > limit
        else:
            passed = difference >= limit
        result[field] = {'canonical': stats(a), 'compact': stats(b), 'absolute_difference_compact_minus_canonical': difference,
                         'frozen_limit': limit, 'passes_frozen_threshold': passed,
                         'compact_mean_outside_canonical_range': statistics.mean(b) < min(a) or statistics.mean(b) > max(a)}
    return result


def main():
    packet = REVIEW / 'precision-review-blinded.json'
    judgments_file = REVIEW / 'precision-review-judgments.json'
    freeze_file = REVIEW / 'judgment-freeze.json'
    if not freeze_file.exists():
        raise SystemExit('Judgments are not frozen; do not unblind')
    freeze = load(freeze_file)
    if sha(packet) != freeze['packet_sha256'] or sha(judgments_file) != freeze['judgment_sha256']:
        raise SystemExit('Packet or judgment hash changed after freeze')
    mapping = load(REVIEW / 'precision-review-private-mapping.json')
    if mapping['packet_sha256'] != freeze['packet_sha256']:
        raise SystemExit('Mapping packet hash mismatch')
    judgments = load(judgments_file)
    by_id = {x['sample_id']: x for x in judgments}
    if len(judgments) != len(mapping['mapping']) or len(by_id) != len(judgments):
        raise SystemExit('Judgment count or sample ID mismatch')
    precision = defaultdict(Counter)
    unblinded = []
    for item in mapping['mapping']:
        judgment = by_id[item['sample_id']]
        if judgment['judgment'] not in ('SUPPORTED', 'PARTIALLY_SUPPORTED', 'UNSUPPORTED'):
            raise SystemExit('Unknown precision judgment')
        precision[item['cell']][judgment['judgment']] += 1
        unblinded.append({**item, 'judgment': judgment['judgment'], 'review_note': judgment['review_note']})
    save(REVIEW / 'precision-review-unblinded.json', unblinded)
    cells = load(METRICS)
    for name in cells:
        counts = precision[name]
        total = sum(counts.values())
        cells[name]['supported_precision'] = counts['SUPPORTED'] / total
        cells[name]['precision_sample_n'] = total
        cells[name]['precision_judgments'] = dict(counts)
    save(OUT / 'analysis' / 'cell-metrics-with-precision.json', cells)
    all_names = list(cells)
    overall = summarize(cells, all_names)
    chunks = {chunk: summarize(cells, [name for name in all_names if cells[name]['chunk'] == chunk])
              for chunk in ('unicef_reduced', 'india_wash')}
    arm_totals = {}
    for arm in 'AB':
        xs = [x for x in cells.values() if x['arm'] == arm]
        returned = sum(x['returned_records'] for x in xs)
        accepted = sum(x['accepted_records'] for x in xs)
        cost = sum(Decimal(x['cost_usd']) for x in xs)
        output = sum(x['output_tokens'] for x in xs)
        arm_totals[arm] = {'returned_records': returned, 'accepted_records': accepted,
                           'input_tokens': sum(x['input_tokens'] for x in xs), 'output_tokens': output,
                           'output_tokens_per_returned_record': output / returned,
                           'total_cost_usd': str(cost), 'cost_per_accepted_record_usd': str(cost / accepted),
                           'precision_sampled': sum(x['precision_sample_n'] for x in xs),
                           'precision_supported': sum(x['precision_judgments'].get('SUPPORTED', 0) for x in xs)}
    reduction = 1 - arm_totals['B']['output_tokens_per_returned_record'] / arm_totals['A']['output_tokens_per_returned_record']
    metadata_fail = any(not chunks[c][field]['passes_frozen_threshold'] and chunks[c][field]['compact_mean_outside_canonical_range']
                        for c in chunks for field in ('period_populated_rate', 'unit_populated_rate'))
    failures = [f'{chunk}:{field}' for chunk in chunks for field, value in chunks[chunk].items()
                if not value['passes_frozen_threshold']]
    decision = 'KEEP_CANONICAL_WIRE_FORMAT' if metadata_fail else 'COMPACT_JSON_READY_BEHIND_FLAG'
    stop = load(OUT / 'study-stop.json')
    result = {'decision': decision, 'calls_completed': stop['calls_completed'], 'calls_failed': stop['calls_failed'],
              'actual_total_cost_usd': stop['actual_total_cost_usd'], 'provider_schema_compilation': 'accepted in all six compact generation calls',
              'overall': overall, 'by_chunk': chunks, 'arm_totals': arm_totals,
              'observed_output_token_reduction_per_returned_record': reduction,
              'frozen_threshold_failures_by_chunk': failures,
              'precision_review': {'packet_sha256': freeze['packet_sha256'], 'judgment_sha256': freeze['judgment_sha256'],
                                   'reviewer_identity_type': freeze['reviewer_identity_type'],
                                   'unblinding_mapping': 'precision-review/precision-review-private-mapping.json',
                                   'unblinding_mapping_sha256': sha(REVIEW / 'precision-review-private-mapping.json'),
                                   'sample_count': len(judgments)},
              'qualitative_review': load(OUT / 'qualitative-review.json')['summary'],
              'secret_scan': load(OUT / 'artifact-secret-scan.json'),
              'protocol_deviation': None, 'interpretation': 'Engineering acceptance check, not statistical proof'}
    save(OUT / 'compact-parity-report.json', result)
    print('decision', decision, 'output reduction', reduction, 'failed thresholds', failures)


if __name__ == '__main__':
    main()
