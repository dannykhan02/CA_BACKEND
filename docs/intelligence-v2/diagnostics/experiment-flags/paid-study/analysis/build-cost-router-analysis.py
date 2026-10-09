"""Read-only analysis of frozen calls and source; writes diagnostic JSON only."""
import collections
import json
import math
import pathlib
import re
import statistics

HERE = pathlib.Path(__file__).resolve().parent
PAID = HERE.parent
FLAGS = PAID.parent
ROOT = FLAGS.parents[3]

def read(path):
    return json.loads(path.read_text())

def write(name, data):
    (HERE / name).write_text(json.dumps(data, indent=2, ensure_ascii=False) + '\n')

def size(value):
    return len(json.dumps(value, ensure_ascii=False, separators=(',', ':')).encode())

def token_proxy(chars):
    return round(chars / 4, 1)

gold = read(HERE / 'gold-review.json')
source = (ROOT / 'unicef-full-extracted.txt').read_text()
spans = read(ROOT / 'unicef-source-spans.json')
manifest = read(FLAGS / 'study-chunks.json')
cells = {}
fields = collections.defaultdict(lambda: collections.defaultdict(lambda: {'bytes': 0, 'populated': 0}))
chunk_fields = collections.defaultdict(lambda: collections.defaultdict(lambda: {'bytes': 0, 'populated': 0}))
for chunk in ('unicef_reduced', 'india_wash'):
    for variant in 'CK':
        for run in range(1, 4):
            cell = f'{chunk}-{variant}{run}'
            meta = read(PAID / f'{cell}.metadata.json')
            response = read(PAID / f'{cell}.response.json')
            records = json.loads(response['content'][0]['text'])['records']
            replay = read(HERE / f'{cell}.replay.json')
            output = meta['usage']['output_tokens']
            accepted = replay['accepted_count']
            key = 'CONTROL' if variant == 'C' else 'COLLECTOR_ONLY'
            for record in records:
                for field, value in record.items():
                    fields[key][field]['bytes'] += size(value)
                    fields[key][field]['populated'] += value is not None and value != '' and value != []
                    chunk_fields[f'{chunk}:{key}'][field]['bytes'] += size(value)
                    chunk_fields[f'{chunk}:{key}'][field]['populated'] += value is not None and value != '' and value != []
            typed = collections.Counter()
            for trace in replay['trace']:
                if trace['accepted']:
                    value = trace['typed'].get('value')
                    typed[(value or {}).get('type', 'none')] += 1
            cells[cell] = {
                'chunk': chunk, 'variant': key, 'run': run,
                'input_tokens': meta['usage']['input_tokens'], 'output_tokens': output,
                'cost_usd': float(meta['actual_cost_usd']),
                'returned': len(records), 'accepted': accepted,
                'rejected': replay['rejected_count'],
                'output_tokens_per_returned': round(output / len(records), 2),
                'output_tokens_per_accepted': round(output / accepted, 2),
                'response_record_bytes': sum(size(x) for x in records),
                'record_bytes_per_returned': round(sum(size(x) for x in records) / len(records), 2),
                'origin': replay['origin'], 'typed_value_counts': dict(typed),
                'key_figure_eligible': replay['key_figure_eligible_count'],
                'premerge_identity_count': replay['premerge_identity_count'],
                'premerge_collisions': accepted - replay['premerge_identity_count'],
                'gold_recalled': gold[cell]['matched'],
            }

write('per-call-decomposition.json', cells)
field_out = {}
for variant, rows in fields.items():
    count = sum(c['returned'] for c in cells.values() if c['variant'] == variant)
    field_out[variant] = {
        field: {**data, 'mean_bytes_per_record': round(data['bytes'] / count, 2),
                'token_proxy_total': token_proxy(data['bytes']),
                'token_proxy_per_record': token_proxy(data['bytes'] / count)}
        for field, data in rows.items()
    }
chunk_field_out = {}
for group, rows in chunk_fields.items():
    chunk, variant = group.split(':')
    count = sum(c['returned'] for c in cells.values() if c['chunk'] == chunk and c['variant'] == variant)
    chunk_field_out[group] = {field: {**data, 'mean_bytes_per_record': round(data['bytes']/count, 2),
        'token_proxy_total': token_proxy(data['bytes'])} for field, data in rows.items()}
write('field-size-breakdown.json', {'method': 'compact UTF-8 JSON value bytes; token proxy = bytes/4, not provider tokenizer; excludes field names and JSON punctuation', 'fields': field_out, 'by_chunk_variant': chunk_field_out})

def norm(value):
    return re.sub(r'\s+', ' ', str(value or '').casefold()).strip()

def mechanical_key(r):
    return (norm(r.get('value')), norm(r.get('kind')),
            tuple(sorted(r.get('evidence_ids') or [])), norm(r.get('period')),
            tuple((k, json.dumps(v, sort_keys=True, ensure_ascii=False)) for k, v in sorted(r.items())
                  if k not in ('value', 'kind', 'evidence_ids', 'period', 'reference')))

gold_out = {}
class_out = {}
mapping = read(HERE / 'precision-review' / 'precision-review-private-mapping.json')['mapping']
judgments = {x['sample_id']: x['judgment'] for x in read(HERE / 'precision-review' / 'precision-review-judgments.json')}
sampled = {(x['cell'], x['accepted_index_zero_based']): judgments[x['sample_id']] for x in mapping}
for chunk in ('unicef_reduced', 'india_wash'):
    by_run = {}
    all_c, all_k = set(), set()
    for run in range(1, 4):
        c, k = (f'{chunk}-{v}{run}' for v in 'CK')
        cs = {x['gold_ordinal'] for x in gold[c]['items'] if x['matched']}
        ks = {x['gold_ordinal'] for x in gold[k]['items'] if x['matched']}
        all_c |= cs; all_k |= ks
        by_run[str(run)] = {'both': sorted(cs & ks), 'control_only': sorted(cs - ks),
                            'collector_only': sorted(ks - cs),
                            'missed_both': sorted(set(range(1, gold[c]['total'] + 1)) - cs - ks)}
        records_c = json.loads(read(PAID / f'{c}.response.json')['content'][0]['text'])['records']
        records_k = json.loads(read(PAID / f'{k}.response.json')['content'][0]['text'])['records']
        trace = read(HERE / f'{k}.replay.json')['trace']
        accepted_raw = [i for i, tr in enumerate(trace) if tr['accepted']]
        raw_accepted = {raw: i for i, raw in enumerate(accepted_raw)}
        matched = {accepted_raw[x['accepted_index']] for x in gold[k]['items'] if x['matched']}
        recovered = {accepted_raw[x['accepted_index']] for x in gold[k]['items'] if x['matched'] and x['gold_ordinal'] in ks-cs}
        seen = {mechanical_key(x) for x in records_c}
        classes = collections.Counter()
        rows = []
        for idx, (record, tr) in enumerate(zip(records_k, trace)):
            key = mechanical_key(record)
            if not tr['accepted']:
                cls = 'REJECTED'
            elif idx in matched:
                cls = 'GOLD_RECOVERY' if idx in recovered else 'GOLD_SHARED'
            elif key in seen:
                cls = 'MECHANICAL_DUPLICATE'
            else:
                judgment = sampled.get((k, raw_accepted[idx]))
                cls = ('VALID_NON_GOLD_EVIDENCE' if judgment == 'SUPPORTED' else
                       'NOISE_OR_UNSUPPORTED' if judgment == 'UNSUPPORTED' else
                       'ACCEPTED_NON_GOLD_UNADJUDICATED')
            if tr['accepted']:
                seen.add(key)
            classes[cls] += 1
            rows.append({'raw_index': idx, 'classification': cls, 'accepted': tr['accepted']})
        class_out[k] = {'counts': dict(classes), 'records': rows}
    total = gold[f'{chunk}-C1']['total']
    gold_out[chunk] = {'gold_items': {str(x['gold_ordinal']): x['normalized_claim'] for x in gold[f'{chunk}-C1']['items']},
        'by_run': by_run, 'union_across_runs': {
        'both': sorted(all_c & all_k), 'control_only': sorted(all_c - all_k),
        'collector_only': sorted(all_k - all_c),
        'missed_both': sorted(set(range(1, total + 1)) - all_c - all_k)}}
write('gold-recovery-comparison.json', {'gold': gold_out, 'collector_record_classification': class_out,
    'classification_note': 'VALID_NON_GOLD and NOISE_OR_UNSUPPORTED use existing sampled precision judgments only; partial support and unsampled records remain unadjudicated. Exact structured duplicates only.'})

def profile(text, these_spans):
    tokens = re.findall(r'\S+', text)
    numeric = len(re.findall(r'(?<!\w)[+-]?(?:[$€£₹]\s*)?\d[\d,]*(?:\.\d+)?%?(?!\w)', text))
    percent = len(re.findall(r'\b\d+(?:\.\d+)?\s*(?:%|per\s*cent|percent)\b|\d+(?:\.\d+)?%', text, re.I))
    currency = len(re.findall(r'(?:[$€£₹]\s*\d|\b\d[\d,.]*\s*(?:USD|INR|rupees|dollars|euros|pounds)\b)', text, re.I))
    dates = len(re.findall(r'\b(?:19|20)\d{2}\b|\b(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+\d{1,2}\b', text, re.I))
    lines = [x.strip() for x in text.splitlines() if x.strip()]
    table = sum('\t' in x or x.count('|') >= 2 or len(re.findall(r'\b\d[\d,.]*%?\b', x)) >= 3 for x in lines)
    numeric_ratio = sum(bool(re.fullmatch(r'[(+-]?[$€£]?\d[\d,.]*%?\)?', x)) for x in tokens) / max(1, len(tokens))
    table_ratio = table / max(1, len(lines))
    dense = numeric_ratio >= .18 or table_ratio >= .30
    rate = 6 if dense else 4
    pages = sorted({x['page'] for x in these_spans})
    scale = 1000 / max(1, len(tokens))
    estimate = math.ceil(len(text.encode()) / 3)
    return {'pages': len(pages), 'page_range': [min(pages), max(pages)] if pages else None,
            'characters': len(text), 'token_estimate_bytes_over_3': estimate,
            'whitespace_tokens': len(tokens), 'span_count': len(these_spans),
            'numeric_per_1k': round(numeric * scale, 2), 'percent_per_1k': round(percent * scale, 2),
            'currency_per_1k': round(currency * scale, 2), 'date_year_per_1k': round(dates * scale, 2),
            'table_like_lines': table, 'table_like_line_ratio': round(table_ratio, 3),
            'heading_spans': sum(x['type'] in ('heading', 'section') for x in these_spans),
            'planner_numeric_ratio': round(numeric_ratio, 3), 'planner_dense': dense,
            'planner_records_per_1k': rate, 'estimated_record_pressure': round(estimate / 1000 * rate, 2)}

all_profile = profile(source, spans)
features = {'document': all_profile, 'chunks': {}}
window_starts = list(range(0, len(spans)-39, 20))
parent_windows = [profile(source[spans[i]['start_offset']:spans[i+39]['end_offset']], spans[i:i+40])
                  for i in window_starts]
features['parent_40_span_windows'] = [{'first_span': spans[i]['span_key'],
    'last_span': spans[i+39]['span_key'], **parent_windows[j]}
    for j, i in enumerate(window_starts)]
for name, item in manifest.items():
    text = source[item['start_offset']:item['end_offset']]
    subset = [x for x in spans if x['start_offset'] >= item['start_offset'] and x['end_offset'] <= item['end_offset']]
    p = profile(text, subset)
    p['document_percentile_numeric'] = round(sum(w['numeric_per_1k'] <= p['numeric_per_1k'] for w in parent_windows) / len(parent_windows), 3)
    p['document_percentile_pressure'] = round(sum(w['estimated_record_pressure'] <= p['estimated_record_pressure'] for w in parent_windows) / len(parent_windows), 3)
    p['source_range'] = [item['first_span'], item['last_span']]
    features['chunks'][name] = p
write('router-feature-table.json', features)

comparisons = {}
for chunk in ('unicef_reduced', 'india_wash'):
    c = [cells[f'{chunk}-C{i}'] for i in range(1,4)]
    k = [cells[f'{chunk}-K{i}'] for i in range(1,4)]
    def mean(items, field): return statistics.mean(x[field] for x in items)
    cr, kr = mean(c, 'returned'), mean(k, 'returned')
    co, ko = mean(c, 'output_tokens'), mean(k, 'output_tokens')
    count_effect = (kr-cr) * co/cr
    length_effect = kr * (ko/kr-co/cr)
    input_delta = mean(k, 'input_tokens')-mean(c, 'input_tokens')
    gold_delta = mean(k, 'gold_recalled')-mean(c, 'gold_recalled')
    cost_delta = mean(k, 'cost_usd')-mean(c, 'cost_usd')
    comparisons[chunk] = {'control_mean_cost': mean(c, 'cost_usd'), 'collector_mean_cost': mean(k, 'cost_usd'),
        'extra_cost': cost_delta, 'extra_cost_percent': 100*cost_delta/mean(c, 'cost_usd'),
        'mean_returned_delta': kr-cr, 'mean_accepted_delta': mean(k,'accepted')-mean(c,'accepted'),
        'mean_rejected_delta': mean(k,'rejected')-mean(c,'rejected'),
        'mean_output_token_delta': ko-co, 'mean_input_token_delta': input_delta,
        'output_count_effect_tokens': count_effect, 'output_length_effect_tokens': length_effect,
        'mean_gold_delta': gold_delta,
        'extra_cost_per_net_gold': cost_delta/gold_delta if gold_delta else None,
        'extra_accepted_per_net_gold': (mean(k,'accepted')-mean(c,'accepted'))/gold_delta if gold_delta else None,
        'extra_output_tokens_per_net_gold': (ko-co)/gold_delta if gold_delta else None}
write('cost-comparison.json', comparisons)

rules = {
    'all_standard': lambda p: 'STANDARD',
    'all_collector_reference': lambda p: 'COMPACT_COLLECTOR',
    'chunk_pressure_ge_10': lambda p: 'COMPACT_COLLECTOR' if p['estimated_record_pressure'] >= 10 else 'STANDARD',
    'chunk_pressure_ge_10_or_numeric_ge_80': lambda p: 'COMPACT_COLLECTOR' if p['estimated_record_pressure'] >= 10 or p['numeric_per_1k'] >= 80 else 'STANDARD',
    'document_relative_numeric': lambda p: 'COMPACT_COLLECTOR' if p['numeric_per_1k'] >= all_profile['numeric_per_1k'] else 'STANDARD',
    'split_first_pressure_ge_20': lambda p: 'SPLIT_FIRST' if p['estimated_record_pressure'] >= 20 else ('COMPACT_COLLECTOR' if p['estimated_record_pressure'] >= 10 else 'STANDARD'),
}
routes = {}
for name, rule in rules.items():
    choices = {chunk: rule(features['chunks'][chunk]) for chunk in ('unicef_reduced','india_wash')}
    if 'SPLIT_FIRST' in choices.values():
        cost = None; recall = None
    else:
        cost = sum(statistics.mean(cells[f'{chunk}-{variant}{i}']['cost_usd'] for i in range(1,4)) for chunk, variant in
                   ((chunk, 'K' if mode == 'COMPACT_COLLECTOR' else 'C') for chunk, mode in choices.items()))
        recall = sum(statistics.mean(cells[f'{chunk}-{variant}{i}']['gold_recalled'] for i in range(1,4)) for chunk, variant in
                     ((chunk, 'K' if mode == 'COMPACT_COLLECTOR' else 'C') for chunk, mode in choices.items()))
    routes[name] = {'routes': choices, 'estimated_two_chunk_one_run_cost_usd': cost,
                    'estimated_sum_of_chunk_gold_recall': recall,
                    'observed_by_chunk': {chunk: {'control_mean_gold': statistics.mean(cells[f'{chunk}-C{i}']['gold_recalled'] for i in range(1,4)),
                        'collector_mean_gold': statistics.mean(cells[f'{chunk}-K{i}']['gold_recalled'] for i in range(1,4))} for chunk in choices}}
write('candidate-routing-backtest.json', {'caveat': 'COMPACT_COLLECTOR is untested; costs/recall proxy saved COLLECTOR_ONLY. SPLIT_FIRST has no observed cost or recall.', 'candidates': routes})
