"""Apply the frozen context-span classifier to saved UNICEF C1/C2/T1/T2 only."""
import collections
import hashlib
import json
import pathlib

HERE = pathlib.Path(__file__).resolve().parent
DIAG = HERE.parent
EARLIER = DIAG / 'unicef-4call'
source_code = (HERE / 'audit-context-spans.py').read_text()
prefix = source_code.split('\nfull_unicef = ', 1)[0]
rules = {'__file__': str(HERE / 'audit-context-spans.py')}
exec(compile(prefix, str(HERE / 'audit-context-spans.py'), 'exec'), rules)
read_spans = rules['read_spans']
periods = rules['periods']
period_accuracy = rules['period_accuracy']
context_class = rules['context_class']
source_spans = read_spans((HERE / 'unicef_quantitative.source.txt').read_text())
stored = json.loads((DIAG / 'unicef-safe-attribution-stored-span-rows.json').read_text())
metadata = {row['span_key']: row for row in stored}
rules['page'] = lambda sid: metadata.get(sid, {}).get('page')
page = rules['page']

cases = []
per_call = {}
membership = {}
for call in ('C1', 'C2', 'T1', 'T2'):
    request = json.loads((EARLIER / f'{call}.request.json').read_text())
    payload = json.loads(request['messages'][0]['content'])
    available = read_spans(payload['evidence_spans'])
    response_bytes = (EARLIER / f'{call}.response.json').read_bytes()
    meta = json.loads((EARLIER / f'{call}.metadata.json').read_text())
    assert hashlib.sha256(response_bytes).hexdigest() == meta['response_sha256']
    assert set(available) <= set(source_spans)
    assert all(available[sid] == source_spans[sid] for sid in available)
    raw = json.loads(json.loads(response_bytes)['content'][0]['text'])['records']
    counts = collections.Counter()
    for index, record in enumerate(raw):
        ids = record.get('evidence_ids') or []
        cited = {sid: available[sid] for sid in ids if sid in available}
        supplied = record.get('period')
        if isinstance(supplied, str) and supplied.strip():
            accuracy, reason, explicit = period_accuracy(record, cited, available)
            counts[accuracy] += 1
        else:
            accuracy, reason, explicit = None, None, []
            counts['PERIOD_NULL'] += 1
        context, candidates, selected = context_class(record, ids, available, source_spans, accuracy)
        ordinals = [metadata[sid]['ordinal'] for sid in ids if sid in metadata]
        explicit_sources = []
        if ordinals:
            for sid, text in source_spans.items():
                expressions = periods(text, sid)
                if expressions and sid in metadata:
                    explicit_sources.append((min(abs(metadata[sid]['ordinal']-o) for o in ordinals), sid, expressions))
        nearest_distance = min((x[0] for x in explicit_sources), default=None)
        nearest = [{'span_id': sid, 'periods': expressions, 'ordinal_distance': distance,
                    'page': page(sid), 'in_same_request_payload': sid in available,
                    'is_cited': sid in ids, 'source_excerpt': source_spans[sid][:220]}
                   for distance, sid, expressions in explicit_sources if distance == nearest_distance]
        chosen = next((c for c in candidates if c['span_id'] == selected), None)
        cases.append({'call': call, 'raw_index': index, 'model_period': supplied,
                      'period_accuracy': accuracy, 'period_reason': reason,
                      'explicit_nearby_periods': explicit, 'label': record.get('label'),
                      'value': record.get('value'), 'kind': record.get('kind'),
                      'evidence_ids': ids, 'citation_count': len(ids),
                      'cited_value_spans': [{'span_id': sid, 'page': page(sid),
                                             'type': metadata.get(sid, {}).get('type'),
                                             'text': cited.get(sid)} for sid in ids],
                      'nearest_explicit_period_context': nearest,
                      'context_class': context, 'selected_candidate_id': selected,
                      'context_in_same_request_payload': chosen.get('in_same_request_payload') if chosen else None,
                      'candidate_addition_cap_result': chosen.get('cap_result') if chosen else None,
                      'context_candidates': candidates})
    per_call[call] = {'returned': len(raw), 'period_categories': dict(counts)}
    membership[call] = {'E032': 'E032' in available, 'E033': 'E033' in available,
                        'E045': 'E045' in available, 'E061': 'E061' in available,
                        'span_count': len(available)}

period_counts = collections.Counter(c['period_accuracy'] for c in cases if c['period_accuracy'])
contexts = collections.Counter(c['context_class'] for c in cases)
citations = collections.Counter(c['citation_count'] for c in cases)
evaluable = sum(period_counts[k] for k in ('CORRECT_AND_SUPPORTED', 'CORRECT_BUT_UNSUPPORTED_BY_CITATION', 'WRONG'))
e033 = {call: next(c for c in cases if c['call'] == call and c['raw_index'] == 0) for call in per_call}
summary = {'calls': 4, 'raw_record_instances': len(cases),
           'period_null_records': sum(c['model_period'] in (None, '') for c in cases),
           'non_null_period_records': sum(period_counts.values()),
           'period_categories': {k: period_counts[k] for k in ('CORRECT_AND_SUPPORTED', 'CORRECT_BUT_UNSUPPORTED_BY_CITATION', 'WRONG', 'AMBIGUOUS', 'NO_EXPLICIT_PERIOD_CONTEXT')},
           'period_category_percentages': {k: 100*period_counts[k]/sum(period_counts.values()) for k in ('CORRECT_AND_SUPPORTED', 'CORRECT_BUT_UNSUPPORTED_BY_CITATION', 'WRONG', 'AMBIGUOUS', 'NO_EXPLICIT_PERIOD_CONTEXT')},
           'evaluable_period_records': evaluable,
           'wrong_rate_evaluable': period_counts['WRONG']/evaluable if evaluable else None,
           'unsupported_but_correct_rate_evaluable': period_counts['CORRECT_BUT_UNSUPPORTED_BY_CITATION']/evaluable if evaluable else None,
           'citation_count_distribution': {str(i): citations[i] for i in (1,2,3)},
           'records_at_three_id_cap': citations[3], 'two_ids_one_slot_left': citations[2],
           'one_id_comfortable_room': citations[1],
           'context_class_counts': dict(contexts),
           'safe_candidate_in_chunk': sum(c['context_class']=='SAFE_CONTEXT_CANDIDATE' and c['context_in_same_request_payload'] is True for c in cases),
           'safe_candidate_outside_chunk': sum(c['context_class']=='SAFE_CONTEXT_CANDIDATE' and c['context_in_same_request_payload'] is False for c in cases),
           'ambiguous_in_chunk': sum(c['context_class']=='AMBIGUOUS_CONTEXT' and c['context_in_same_request_payload'] is True for c in cases),
           'ambiguous_outside_chunk': sum(c['context_class']=='AMBIGUOUS_CONTEXT' and c['context_in_same_request_payload'] is False for c in cases),
           'E033': {call: {'model_period': c['model_period'], 'period_accuracy': c['period_accuracy'],
                           'context_class': c['context_class'], 'selected_candidate_id': c['selected_candidate_id']}
                    for call,c in e033.items()},
           'E032_membership': {call: membership[call]['E032'] for call in membership},
           'E061_header': {'period': '2024', 'in_all_four_requests': all(v['E061'] for v in membership.values()),
                          'same_page_as_E033': page('E061')==page('E033'),
                          'ordinal_distance_from_E033': metadata['E061']['ordinal']-metadata['E033']['ordinal'],
                          'configured_locality': rules['LOCALITY']},
           'E045_unit_header': {'text': source_spans['E045'], 'in_all_four_requests': all(v['E045'] for v in membership.values()),
                                'same_page_as_E033': page('E045')==page('E033'),
                                'ordinal_distance_from_E033': metadata['E045']['ordinal']-metadata['E033']['ordinal'],
                                'stored_type': metadata['E045']['type']}}
artifact = {'rules_source': str((HERE/'audit-context-spans.py').relative_to(DIAG.parent.parent.parent)),
            'summary': summary, 'per_call': per_call, 'payload_membership': membership, 'cases': cases}
out = EARLIER / 'context-span-audit.json'
out.write_text(json.dumps(artifact, indent=2, ensure_ascii=False)+'\n')
print(json.dumps({'per_call':per_call,'summary':summary},indent=2))
