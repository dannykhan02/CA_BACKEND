"""Deterministic, offline audit of saved raw response periods and context spans."""
import collections
import json
import pathlib
import re

BASE = pathlib.Path(__file__).resolve().parent
PAID = BASE / 'paid-study'
OUT = PAID / 'analysis' / 'context-span-audit.json'
MAX_IDS = 3
LOCALITY = 12


def read_spans(text):
    chunks = re.split(r'(?m)^\[(E\d{3})\]\n', text)
    return {chunks[i]: chunks[i + 1].strip() for i in range(1, len(chunks), 2)}


def periods(text, span_id):
    if span_id == 'E103':
        return ['2020', '2021', '2022', '2023', '2024']
    out = re.findall(r'(?<!\d)(?:19|20)\d{2}(?!\d)', text)
    out += re.findall(r'\bsince\s+(?:19|20)\d{2}\b', text, re.I)
    for pattern, label in ((r'\b(?:last|past|over the|in the)\s+(?:five|5)\s+years\b', 'five years'),
                           (r'\b(?:last|past|over|over the|in the)\s+10\s+years\b', '10 years'),
                           (r'\b(?:last|over a|over the|decade-long)\s+decade\b', 'decade'),
                           (r'\bdecade-long\b', 'decade'),
                           (r'\bfive years\b', 'five years'),
                           (r'\bCOVID-19 pandemic\b', 'COVID-19 pandemic'),
                           (r'\baftermath\s+of\s+cyclones\b', 'aftermath of cyclones')):
        if re.search(pattern, text, re.I):
            out.append(label)
    return list(dict.fromkeys(out))


def norm(value):
    s = str(value).lower().strip().replace('–', '-').replace('—', '-')
    if 'covid-19 pandemic' in s:
        return 'COVID-19 pandemic'
    if 'aftermath of cyclones' in s:
        return 'aftermath of cyclones'
    if re.search(r'\b(?:last|past|over a|over the|decade-long|decade)\b', s) and 'decade' in s:
        return '10 years'
    if '10 years' in s:
        return '10 years'
    if 'five years' in s or '5 years' in s:
        return 'five years'
    if re.fullmatch(r'20\d{2}-20\d{2}', s):
        return s
    if re.fullmatch(r'(?:19|20)\d{2}', s):
        return s
    return s


def page(span_id):
    n = int(span_id[1:])
    if 100 <= n <= 104:
        return 5
    if 105 <= n <= 117:
        return 6
    if 118 <= n <= 125:
        return 7
    if 126 <= n <= 139:
        return 8
    if 200 <= n <= 210:
        return 14
    if 211 <= n <= 231:
        return 15
    if 232 <= n <= 238:
        return 16
    return None


def period_accuracy(record, cited_text, available):
    ids = record.get('evidence_ids') or []
    supplied = norm(record['period'])
    if 'E103' in ids:
        if supplied == '2020-2024' and '2020' in record.get('value', '') and '2024' in record.get('value', ''):
            return 'CORRECT_AND_SUPPORTED', 'Chart caption explicitly covers 2020-2024 for the aggregate series; individual value-year ownership remains unverified', ['2020-2024']
        return 'AMBIGUOUS', 'OCR-flattened chart has multiple years and unowned values', ['2020', '2021', '2022', '2023', '2024']
    if 'E066' in ids and supplied == '2020-2024':
        return 'AMBIGUOUS', 'E064/E065 give a multi-year table range and competing year columns, but E066 values lack column ownership', ['2020-2024 in E064', '2020, 2021, 2022, 2023, 2024 in E065']
    if any(i in ('E076', 'E077') for i in ids) and supplied == 'since 2000':
        return 'AMBIGUOUS', 'Since 2000 is in E075; whether it governs later sentences is structurally uncertain', ['since 2000 in E075']
    if 'E108' in ids and supplied == '2024':
        return 'AMBIGUOUS', 'COVID-19 timing may modify mitigation only; investment timing is not safely resolved', ['COVID-19 pandemic']
    if 'E122' in ids and supplied == '2024':
        return 'WRONG', 'Claim covers a decade, not a cited calendar-year observation', ['over a decade']
    if supplied == '2024' and ('E112' in ids or 'E124' in ids):
        return 'NO_EXPLICIT_PERIOD_CONTEXT', 'Event or other claim timeframe does not establish 2024 for this observation', []
    explicit = list(dict.fromkeys(x for sid, text in cited_text.items() for x in periods(text, sid)))
    cited_years = {x for x in explicit if re.fullmatch(r'(?:19|20)\d{2}', x)}
    if len(cited_years) > 1 and re.fullmatch(r'(?:19|20)\d{2}', supplied):
        return 'AMBIGUOUS', 'Multiple cited years lack a proven value-to-column relationship', sorted(cited_years)
    matched = [x for x in explicit if norm(x) == supplied or
               (supplied == '2014-2022' and {'2014', '2022'} <= set(explicit))]
    if matched:
        # A cited period still has to belong to the particular claim.
        if 'E237' in ids and supplied == '2024' and not any(v in record.get('value', '') for v in ('17.9', '59.3')):
            return 'AMBIGUOUS', 'Year in long span is not clearly owned by this value', explicit
        if 'E124' in ids and supplied == 'five years' and 'social worker' in record.get('label', '').lower():
            return 'AMBIGUOUS', 'Five years modifies protection-service reach, not clearly accreditation', explicit
        return 'CORRECT_AND_SUPPORTED', 'Explicit period and claim occur in cited value span(s)', explicit
    # The only source relationship that passes the separately frozen ownership
    # rule is the adjacent E211/E212 paragraph continuation. No saved E212-only
    # raw record supplies a period, but this branch makes the unsupported-but-
    # correct category independently reachable and auditable.
    if ids == ['E212'] and supplied == '2024' and 'E211' in available:
        return 'CORRECT_BUT_UNSUPPORTED_BY_CITATION', 'E211 owns the period for the E212 same-paragraph continuation but is not cited', ['2024 in E211']
    if supplied == '2024' and ids and all(re.fullmatch(r'E0(?:3[3-9]|[45]\d|60)', i) for i in ids) \
            and 'E061' in available and 'Top 30 Core Resources partners' in available['E061'] \
            and record.get('kind') == 'metric':
        return 'CORRECT_BUT_UNSUPPORTED_BY_CITATION', 'E061 names the 2024 Top 30 partners table containing this row, but is not cited', ['2024 in E061']
    if explicit:
        return 'AMBIGUOUS', 'Cited span has a different temporal expression but ownership is not decisive', explicit
    return 'NO_EXPLICIT_PERIOD_CONTEXT', 'No claim-owned explicit period in cited spans', []


def context_class(record, ids, available, all_spans, accuracy):
    if accuracy == 'CORRECT_AND_SUPPORTED':
        return 'ALREADY_SUPPORTED', [], None
    if accuracy == 'WRONG':
        return 'ALREADY_CITED_CONFLICT', [], None
    if 'E103' in ids:
        return 'AMBIGUOUS_CONTEXT', [{'span_id': 'E103', 'periods': periods(available['E103'], 'E103'),
                                      'in_same_request_payload': True, 'page': page('E103'),
                                      'ordinal_distance': 0, 'relationship': 'unowned multi-year chart columns',
                                      'cap_result': 'NO_ADDITION', 'class': 'AMBIGUOUS_CONTEXT',
                                      'source_excerpt': available['E103'][:220]}], 'E103'
    ordinals = [int(i[1:]) for i in ids if re.fullmatch(r'E\d{3}', i)]
    if not ordinals:
        return 'NO_CONTEXT_FOUND', [], None
    candidates = []
    for sid, text in all_spans.items():
        if sid in ids:
            continue
        ps = periods(text, sid)
        if not ps:
            continue
        distance = min(abs(int(sid[1:]) - n) for n in ordinals)
        # Keep distant explicit candidates visible so OUTSIDE_LOCALITY is an
        # auditable decision; 50 spans is only an artifact-size screen and does
        # not relax the 12-span acceptance limit.
        if distance > 50:
            continue
        in_payload = sid in available
        same_page = page(sid) is not None and all(page(i) == page(sid) for i in ids)
        after_count = len(set(ids + [sid]))
        cap_result = 'EXCEEDS_CAP' if after_count > MAX_IDS else ('EXACTLY_REACHES_CAP' if after_count == MAX_IDS else 'BELOW_CAP')
        # Only explicit continuation structures are eligible. The remaining
        # nearby periods are retained as negative/ambiguous audit inputs.
        safe_continuation = ids == ['E212'] and sid == 'E211'
        ambiguous_continuation = sid == 'E232' and any(i in ('E233', 'E234', 'E235', 'E236') for i in ids)
        if not in_payload or not same_page or distance > LOCALITY:
            relation = 'outside request/page/locality'
            klass = 'OUTSIDE_LOCALITY'
        elif len(ps) > 1 or ambiguous_continuation:
            relation = 'multiple periods or uncertain continuation ownership'
            klass = 'AMBIGUOUS_CONTEXT'
        elif safe_continuation:
            relation = 'same paragraph: E212 continues the E211 By 2024 Swachh Bharat statement'
            klass = 'SAFE_CONTEXT_CANDIDATE' if after_count <= MAX_IDS else 'CAP_EXCEEDED'
        else:
            relation = 'different observation, chart, heading, country, or no proven temporal ownership'
            klass = 'STRUCTURALLY_UNSAFE'
        candidates.append({'span_id': sid, 'periods': ps, 'in_same_request_payload': in_payload,
                           'page': page(sid), 'same_page': same_page, 'ordinal_distance': distance,
                           'relationship': relation, 'class': klass, 'cap_result': cap_result,
                           'source_excerpt': text[:220]})
    priority = {'SAFE_CONTEXT_CANDIDATE': 0, 'CAP_EXCEEDED': 1, 'AMBIGUOUS_CONTEXT': 2,
                'STRUCTURALLY_UNSAFE': 3, 'OUTSIDE_LOCALITY': 4}
    candidates.sort(key=lambda c: (priority[c['class']], c['ordinal_distance'], c['span_id']))
    if not candidates:
        return 'NO_CONTEXT_FOUND', [], None
    selected = candidates[0]
    return selected['class'], candidates, selected['span_id']


full_unicef = read_spans((BASE / 'unicef_quantitative.source.txt').read_text())
full_india = read_spans((BASE / 'india_wash.source.txt').read_text())
cases = []
period_counts = collections.Counter()
context_counts = collections.Counter()
citations = collections.Counter()
for response_file in sorted(PAID.glob('*.response.json')):
    cell = response_file.name.removesuffix('.response.json')
    chunk = cell.rsplit('-', 1)[0]
    request = json.loads((PAID / f'{cell}.request.json').read_text())
    user = json.loads(request['messages'][0]['content'])
    available = read_spans(user['evidence_spans'])
    all_spans = full_unicef if chunk == 'unicef_reduced' else full_india
    raw = json.loads(json.loads(response_file.read_text())['content'][0]['text'])['records']
    for index, record in enumerate(raw):
        ids = record.get('evidence_ids') or []
        citations[len(ids)] += 1
        cited = {i: available[i] for i in ids if i in available}
        has_period = isinstance(record.get('period'), str) and bool(record['period'].strip())
        if has_period:
            accuracy, reason, explicit = period_accuracy(record, cited, available)
            period_counts[accuracy] += 1
        else:
            accuracy, reason, explicit = None, None, []
        context, candidates, selected_id = context_class(record, ids, available, all_spans, accuracy)
        context_counts[context] += 1
        selected = next((c for c in candidates if c['span_id'] == selected_id), None)
        ordinals = [int(i[1:]) for i in ids if re.fullmatch(r'E\d{3}', i)]
        explicit_sources = []
        if ordinals:
            for sid, span_text in all_spans.items():
                values = periods(span_text, sid)
                if values:
                    explicit_sources.append((min(abs(int(sid[1:]) - n) for n in ordinals), sid, values))
        nearest_distance = min((row[0] for row in explicit_sources), default=None)
        nearest = [{'span_id': sid, 'periods': values, 'ordinal_distance': distance,
                    'page': page(sid), 'in_same_request_payload': sid in available,
                    'is_cited': sid in ids, 'source_excerpt': all_spans[sid][:220]}
                   for distance, sid, values in explicit_sources if distance == nearest_distance]
        cases.append({'cell': cell, 'raw_index': index, 'chunk': chunk,
                      'model_period': record.get('period'), 'period_accuracy': accuracy,
                      'period_reason': reason, 'explicit_nearby_periods': explicit,
                      'label': record.get('label'), 'value': record.get('value'),
                      'evidence_ids': ids, 'citation_count': len(ids),
                      'cited_value_spans': [{'span_id': i, 'page': page(i), 'text': cited.get(i)} for i in ids],
                      'nearest_explicit_period_context': nearest,
                      'context_class': context, 'selected_candidate_id': selected_id,
                      'context_in_same_request_payload': selected.get('in_same_request_payload') if selected else None,
                      'candidate_addition_cap_result': selected.get('cap_result') if selected else None,
                      'context_candidates': candidates,
                      'wrong_period_could_come_from_nearby_structure': bool(accuracy == 'WRONG' and record['period'] == '2024'),
                      'wrong_nearby_period_sources': [{'span_id': 'E120', 'period': '2024', 'structure': 'adjacent India country row, two spans before E122'}] if accuracy == 'WRONG' and 'E122' in ids else [],
                      'wrong_period_structure_note': 'The 2024 in E120 belongs to the India row, not the Bangladesh stunting row E122; spillover is plausible but cannot be proven' if accuracy == 'WRONG' and 'E122' in ids else None})

evaluable = sum(period_counts[k] for k in ('CORRECT_AND_SUPPORTED', 'CORRECT_BUT_UNSUPPORTED_BY_CITATION', 'WRONG'))
safe = [c for c in cases if c['context_class'] == 'SAFE_CONTEXT_CANDIDATE']
summary = {'response_calls': 12, 'raw_record_instances': len(cases),
           'non_null_period_records': sum(period_counts.values()), 'period_categories': dict(period_counts),
           'period_category_percentages': {k: 100 * period_counts[k] / sum(period_counts.values()) for k in ('CORRECT_AND_SUPPORTED', 'CORRECT_BUT_UNSUPPORTED_BY_CITATION', 'WRONG', 'AMBIGUOUS', 'NO_EXPLICIT_PERIOD_CONTEXT')},
           'evaluable_period_records': evaluable,
           'wrong_rate_evaluable': period_counts['WRONG'] / evaluable if evaluable else None,
           'unsupported_but_correct_rate_evaluable': period_counts['CORRECT_BUT_UNSUPPORTED_BY_CITATION'] / evaluable if evaluable else None,
           'unsupported_but_correct_rate_all_period_records': period_counts['CORRECT_BUT_UNSUPPORTED_BY_CITATION'] / sum(period_counts.values()) if period_counts else None,
           'citation_count_distribution': {str(i): citations[i] for i in (1, 2, 3)},
           'records_at_three_id_cap': citations[3], 'two_ids_one_slot_left': citations[2],
           'one_id_comfortable_room': citations[1], 'context_class_counts': dict(context_counts),
           'safe_candidate_in_chunk': sum(c['context_in_same_request_payload'] is True for c in safe),
           'safe_candidate_outside_chunk': sum(c['context_in_same_request_payload'] is False for c in safe),
           'ambiguous_in_chunk': sum(c['context_class'] == 'AMBIGUOUS_CONTEXT' and c['context_in_same_request_payload'] is True for c in cases),
           'ambiguous_outside_chunk': sum(c['context_class'] == 'AMBIGUOUS_CONTEXT' and c['context_in_same_request_payload'] is False for c in cases),
           'safe_candidate_cap_below': sum(c['candidate_addition_cap_result'] == 'BELOW_CAP' for c in safe),
           'safe_candidate_cap_exact': sum(c['candidate_addition_cap_result'] == 'EXACTLY_REACHES_CAP' for c in safe),
           'safe_candidate_cap_exceeded': sum(c['candidate_addition_cap_result'] == 'EXCEEDS_CAP' for c in safe),
           'prompt_only_opportunity_upper_bound_record_instances': len(safe)}
OUT.write_text(json.dumps({'rules_file': 'context-span-audit-rules.md', 'summary': summary, 'cases': cases}, indent=2, ensure_ascii=False) + '\n')
print(json.dumps(summary, indent=2))
