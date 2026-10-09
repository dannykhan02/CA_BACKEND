"""Apply frozen study metrics to preserved artifacts; never calls the provider."""
import itertools
import json
import pathlib
import statistics
from decimal import Decimal

BASE = pathlib.Path(__file__).resolve().parent
PAID = BASE / 'paid-study'
ANALYSIS = PAID / 'analysis'
gold = json.loads((ANALYSIS / 'gold-review.json').read_text())
negative = json.loads((ANALYSIS / 'negative-review.json').read_text())
precision = json.loads((ANALYSIS / 'precision-review' / 'precision-review-unblinded.json').read_text())
cells = {}
for chunk in ('unicef_reduced', 'india_wash'):
    for variant in 'CK':
        for run in range(1, 4):
            cell = f'{chunk}-{variant}{run}'
            meta = json.loads((PAID / f'{cell}.metadata.json').read_text())
            replay = json.loads((ANALYSIS / f'{cell}.replay.json').read_text())
            returned = replay['returned_count']
            accepted = replay['accepted_count']
            rejects = replay['rejected_count']
            rejection_classes = replay['validation']['rejections'] or {}
            grounding = sum(count for reason, count in rejection_classes.items()
                            if 'evidence' in reason or 'ground' in reason)
            identities = {r['identity'] for r in replay['trace'] if r['accepted']}
            cells[cell] = {
                'chunk': chunk, 'variant': 'CONTROL' if variant == 'C' else 'COLLECTOR_ONLY',
                'run': run, 'gold_recalled': gold[cell]['matched'], 'gold_total': gold[cell]['total'],
                'priority_recalled': gold[cell]['priority_matched'],
                'priority_total': gold[cell]['priority_total'],
                'negative_matches': negative[cell]['matched'], 'negative_total': negative[cell]['total'],
                'negative_false_positive_rate': negative[cell]['matched'] / negative[cell]['total'],
                'sampled_precision': precision['by_cell'][cell],
                'returned': returned, 'accepted': accepted, 'rejected': rejects,
                'rejection_rate': rejects / returned if returned else None,
                'grounding_rejects': grounding,
                'grounding_failure_rate': grounding / returned if returned else None,
                'origin_document_share': replay['origin']['document'] / accepted if accepted else None,
                'origin_unknown_share': replay['origin']['unknown'] / accepted if accepted else None,
                'key_figure_eligible_count': replay['key_figure_eligible_count'],
                'key_figure_eligibility_rate': replay['key_figure_eligible_count'] / accepted if accepted else None,
                'stop_reason': meta['stop_reason'], 'output_tokens': meta['usage']['output_tokens'],
                'input_tokens': meta['usage']['input_tokens'],
                'cache_creation_input_tokens': meta['usage']['cache_creation_input_tokens'],
                'cache_read_input_tokens': meta['usage']['cache_read_input_tokens'],
                'cost_usd': meta['actual_cost_usd'], '_identities': identities,
            }

groups = {}
for chunk in ('unicef_reduced', 'india_wash'):
    groups[chunk] = {}
    for variant in ('CONTROL', 'COLLECTOR_ONLY'):
        xs = [cells[f'{chunk}-{"C" if variant == "CONTROL" else "K"}{run}'] for run in range(1, 4)]
        pairs = []
        for a, b in itertools.combinations(xs, 2):
            union = a['_identities'] | b['_identities']
            pairs.append({'runs': [a['run'], b['run']],
                          'jaccard': len(a['_identities'] & b['_identities']) / len(union) if union else 1})
        groups[chunk][variant] = {
            'mean_gold_recalled': statistics.mean(x['gold_recalled'] for x in xs),
            'mean_priority_recalled': statistics.mean(x['priority_recalled'] for x in xs),
            'mean_negative_false_positive_rate': statistics.mean(x['negative_false_positive_rate'] for x in xs),
            'mean_rejection_rate': statistics.mean(x['rejection_rate'] for x in xs),
            'mean_grounding_failure_rate': statistics.mean(x['grounding_failure_rate'] for x in xs),
            'mean_origin_document_share': statistics.mean(x['origin_document_share'] for x in xs),
            'mean_origin_unknown_share': statistics.mean(x['origin_unknown_share'] for x in xs),
            'mean_key_figure_eligibility_rate': statistics.mean(x['key_figure_eligibility_rate'] for x in xs),
            'mean_accepted': statistics.mean(x['accepted'] for x in xs),
            'mean_output_tokens': statistics.mean(x['output_tokens'] for x in xs),
            'mean_cost_usd': str(sum(Decimal(x['cost_usd']) for x in xs) / 3),
            'supported_precision': precision['by_chunk_variant'][f'{chunk}:{variant}']['supported_precision'],
            'supported_plus_partial': precision['by_chunk_variant'][f'{chunk}:{variant}']['supported_plus_partial'],
            'jaccard_pairs': pairs, 'jaccard_mean': statistics.mean(p['jaccard'] for p in pairs),
            'gold_spread': [min(x['gold_recalled'] for x in xs), max(x['gold_recalled'] for x in xs)],
            'accepted_spread': [min(x['accepted'] for x in xs), max(x['accepted'] for x in xs)],
            'truncated_calls': sum(x['stop_reason'] in ('max_tokens', 'max_output_tokens') for x in xs),
        }

gates = {}
for chunk, group in groups.items():
    control, collector = group['CONTROL'], group['COLLECTOR_ONLY']
    ratio = Decimal(collector['mean_cost_usd']) / Decimal(control['mean_cost_usd'])
    gates[chunk] = {
        'gold_delta_items': collector['mean_gold_recalled'] - control['mean_gold_recalled'],
        'precision_delta_points': 100 * (collector['supported_precision'] - control['supported_precision']),
        'negative_delta_points': 100 * (collector['mean_negative_false_positive_rate'] - control['mean_negative_false_positive_rate']),
        'rejection_delta_points': 100 * (collector['mean_rejection_rate'] - control['mean_rejection_rate']),
        'grounding_delta_points': 100 * (collector['mean_grounding_failure_rate'] - control['mean_grounding_failure_rate']),
        'origin_document_delta_points': 100 * (collector['mean_origin_document_share'] - control['mean_origin_document_share']),
        'cost_ratio': str(ratio), 'cost_increase_percent': float(100 * (ratio - 1)),
        'cost_gate_pass': ratio <= Decimal('1.25'),
        'precision_gate_pass': collector['supported_precision'] >= control['supported_precision'] - .05,
        'rejection_gate_pass': collector['mean_rejection_rate'] <= control['mean_rejection_rate'] + .05,
        'grounding_gate_pass': collector['mean_grounding_failure_rate'] <= control['mean_grounding_failure_rate'] + .02,
        'negative_gate_pass': collector['mean_negative_false_positive_rate'] <= control['mean_negative_false_positive_rate'] + .05,
        'recall_gate_pass': collector['mean_gold_recalled'] >= control['mean_gold_recalled'],
        'collector_only_truncation': collector['truncated_calls'] > 0 and control['truncated_calls'] == 0,
    }

for item in cells.values():
    del item['_identities']
out = {'cells': cells, 'groups': groups, 'gates': gates,
       'precision_judgments_sha256': precision['judgments_sha256']}
(ANALYSIS / 'study-metrics.json').write_text(json.dumps(out, indent=2, ensure_ascii=False) + '\n')
for chunk in groups:
    print(chunk)
    for variant in groups[chunk]:
        g = groups[chunk][variant]
        print(variant, 'gold', g['mean_gold_recalled'], 'precision', round(g['supported_precision'], 4),
              'cost', g['mean_cost_usd'], 'rejection', round(g['mean_rejection_rate'], 4),
              'grounding', round(g['mean_grounding_failure_rate'], 4), 'jaccard', round(g['jaccard_mean'], 4))
    print('gates', json.dumps(gates[chunk]))
