"""Offline canonical replay, candidate scoring, frozen gold matcher, and metrics."""
import json
import pathlib
import statistics
import subprocess
import sys
from collections import Counter
from decimal import Decimal

HERE = pathlib.Path(__file__).resolve().parent
OUT = HERE / 'compact-parity-study'
PRE = HERE / 'pre-b2'
FROZEN = PRE / 'final-study'
STUDY = HERE / 'experiment-flags'
sys.path.insert(0, str(PRE))
from candidate_inventory import represent
sys.path.insert(0, str(FROZEN))
from review_frozen_items import gold_hit


def save(path, obj):
    path.write_text(json.dumps(obj, indent=2, ensure_ascii=False) + '\n')


def mean(values):
    return statistics.mean(values) if values else None


def range_stats(values):
    return {'mean': mean(values), 'minimum': min(values) if values else None, 'maximum': max(values) if values else None}


def process():
    analysis = OUT / 'analysis'
    analysis.mkdir(exist_ok=True)
    inv = json.loads((PRE / 'candidate-inventory.json').read_text())['chunks']
    metrics = {}
    for chunk in ('unicef_reduced', 'india_wash'):
        gold = json.loads((STUDY / f'{chunk}.gold-items.json').read_text())
        for arm in 'AB':
            for number in range(1, 4):
                cell = f'{chunk}-{arm}{number}'
                meta = json.loads((OUT / f'{cell}.metadata.json').read_text())
                provider = json.loads((OUT / f'{cell}.response.json').read_text())
                expanded = json.loads((OUT / f'{cell}.expanded.json').read_text())
                provider['content'] = [{'type': 'text', 'text': json.dumps(expanded, separators=(',', ':'), ensure_ascii=False)}]
                override = analysis / f'{cell}.canonical-provider.json'
                save(override, provider)
                replay = json.loads(subprocess.check_output(['php', str(FROZEN / 'replay_final.php'), cell, str(override)], text=True))
                accepted = replay['accepted']
                trace = replay['replay']['trace']
                raw = expanded['records']
                rejected = [{'evidence_ids': raw[t['raw_index']].get('evidence_ids', []),
                             'rejection_reason': t.get('rejection_reason') or t.get('rejection_class')}
                            for t in trace if not t['accepted']]
                matched = represent(inv[chunk], accepted, rejected)
                headline = [x for x in matched if x['headline_eligible']]
                gold_rows = []
                for ordinal, item in enumerate(gold, 1):
                    cited = [(i, r) for i, r in enumerate(accepted)
                             if set(item['evidence_ids']) & set(r.get('evidence_ids', []))]
                    hits = [i for i, r in cited if gold_hit(chunk, ordinal, r)]
                    gold_rows.append({'ordinal': ordinal, 'matched': bool(hits), 'accepted_indices': hits})
                record_metrics = {}
                metric_trace = [t for t in trace if t.get('accepted') and raw[t['raw_index']].get('kind') == 'metric']
                for field in ('period', 'unit', 'currency'):
                    if field == 'currency':
                        # Use the existing typed projector's currency field, not a new string heuristic.
                        values = metric_trace
                        count = sum(bool(((t.get('typed') or {}).get('value') or {}).get('currency')) for t in values)
                    else:
                        values = [r for r in accepted if r.get('kind') == 'metric']
                        count = sum(r.get(field) is not None and str(r.get(field)).strip() != '' for r in values)
                    record_metrics[field + '_populated_rate'] = count / len(values) if values else None
                returned = replay['replay']['returned_count']
                rejected_count = replay['replay']['rejected_count']
                rejection_reasons = replay['replay']['validation'].get('rejection_reasons') or {}
                grounding = sum(n for reason, n in rejection_reasons.items() if 'evidence' in reason or 'ground' in reason)
                usage = meta['usage']
                metrics[cell] = {'chunk': chunk, 'arm': arm, 'run': number,
                                 'headline_explicit_quantity_representation': sum(x['status'] == 'REPRESENTED' for x in headline) / len(headline),
                                 'headline_represented': sum(x['status'] == 'REPRESENTED' for x in headline),
                                 'headline_denominator': len(headline),
                                 'gold_recalled': sum(x['matched'] for x in gold_rows), 'gold_total': len(gold_rows),
                                 **record_metrics, 'returned_records': returned, 'accepted_records': len(accepted),
                                 'rejection_rate': rejected_count / returned if returned else 0,
                                 'grounding_failure_rate': grounding / returned if returned else 0,
                                 'parser_failure': meta['failure_class'] in ('PARSER_FAILURE', 'COMPACT_PARSER_FAILURE'),
                                 'structured_output_failure': meta['failure_class'] in ('STRUCTURED_OUTPUT_FAILURE', 'COMPACT_EXPANSION_FAILURE'),
                                 'truncated': meta['stop_reason'] == 'max_tokens', 'stop_reason': meta['stop_reason'],
                                 'input_tokens': usage['input_tokens'], 'output_tokens': usage['output_tokens'],
                                 'output_tokens_per_returned_record': usage['output_tokens'] / returned if returned else None,
                                 'cost_usd': meta['cost_usd'], 'cost_per_accepted_record_usd': str(Decimal(meta['cost_usd']) / len(accepted)) if accepted else None}
                save(analysis / f'{cell}.replay.json', replay)
                save(analysis / f'{cell}.candidate-representation.json', matched)
                save(analysis / f'{cell}.gold-review.json', gold_rows)
    save(OUT / 'analysis' / 'cell-metrics.json', metrics)
    print('replayed', len(metrics), 'cells')


if __name__ == '__main__':
    process()
