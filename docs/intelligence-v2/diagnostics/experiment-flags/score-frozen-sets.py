"""Score frozen source-side sets against accepted records; no provider access.

The match indices below are explicit review annotations. A cited span alone does
not count: the accepted claim must express the frozen proposition and quantity.
"""
import json
import pathlib

BASE = pathlib.Path(__file__).resolve().parent
ANALYSIS = BASE / 'paid-study' / 'analysis'
CELLS = [f'{chunk}-{variant}{run}' for chunk in ('unicef_reduced', 'india_wash')
         for variant in 'CK' for run in range(1, 4)]

# Gold item ordinal (1-based) -> accepted record ordinal (0-based). Missing
# entries are audited omissions, not a substitution with a nearby source value.
MATCHES = {
    'india_wash-C1': {1: 0, 2: 1, 6: 2, 7: 3, 8: 5, 9: 15, 10: 6},
    'india_wash-C2': {1: 0, 2: 1, 3: 2, 4: 2, 6: 3, 7: 4, 9: 15, 10: 6, 11: 14, 12: 22},
    'india_wash-C3': {1: 0, 2: 1, 6: 2, 8: 4, 9: 7, 10: 3, 11: 14, 12: 15},
    'india_wash-K1': {1: 0, 2: 1, 3: 2, 4: 2, 6: 3, 7: 4, 8: 5, 9: 12, 10: 6, 11: 14, 12: 18},
    'india_wash-K2': {1: 0, 2: 1, 4: 3, 6: 4, 7: 5, 9: 22, 10: 7, 11: 29, 12: 17},
    'india_wash-K3': {1: 0, 3: 1, 4: 1, 6: 2, 7: 3, 8: 8, 9: 9, 10: 4, 11: 14},
    'unicef_reduced-C1': {1: 15, 3: 19, 4: 20, 5: 23, 6: 26, 7: 27, 8: 28, 9: 31, 10: 13, 11: 17, 13: 24, 14: 34, 15: 40},
    'unicef_reduced-C2': {1: 14, 2: 16, 3: 17, 4: 18, 5: 21, 6: 24, 7: 25, 8: 26, 9: 29, 10: 12, 11: 15, 13: 22, 14: 32, 15: 35},
    'unicef_reduced-C3': {1: 4, 3: 6, 4: 7, 6: 12, 7: 12, 8: 13, 9: 14, 10: 3, 11: 5, 12: 15, 13: 10, 14: 16, 15: 23, 17: 19},
    'unicef_reduced-K1': {1: 13, 2: 14, 3: 16, 4: 17, 5: 20, 6: 23, 7: 24, 8: 25, 9: 28, 10: 11, 11: 14, 13: 21, 14: 31, 15: 36},
    'unicef_reduced-K2': {1: 13, 3: 17, 4: 18, 5: 21, 6: 24, 7: 25, 8: 26, 9: 29, 10: 11, 11: 15, 13: 22, 14: 32, 15: 37, 17: 55},
    'unicef_reduced-K3': {1: 29, 2: 31, 3: 32, 4: 33, 5: 36, 6: 39, 7: 40, 8: 41, 9: 44, 10: 27, 11: 30, 13: 37, 14: 47, 15: 51, 17: 55},
}

# The only exact frozen negative-set match in accepted records is an unsupported
# 2024 period on the Guatemala investment in UNICEF Control run 1.
NEGATIVE_MATCHES = {'unicef_reduced-C1': {1: 15}}

gold_review, negative_review = {}, {}
for cell in CELLS:
    chunk = cell.rsplit('-', 1)[0]
    gold = json.loads((BASE / f'{chunk}.gold-items.json').read_text())
    negative = json.loads((BASE / f'{chunk}.negative-items.json').read_text())
    accepted = json.loads((ANALYSIS / f'{cell}.accepted.json').read_text())
    hits = MATCHES[cell]
    assert all(1 <= ordinal <= len(gold) and 0 <= idx < len(accepted)
               for ordinal, idx in hits.items())
    g_rows = []
    for ordinal, item in enumerate(gold, 1):
        idx = hits.get(ordinal)
        if idx is not None:
            assert set(item['evidence_ids']) & set(accepted[idx]['evidence_ids'])
        g_rows.append({'gold_ordinal': ordinal, 'normalized_claim': item['normalized_claim'],
                       'priority_material': item['priority_material'], 'matched': idx is not None,
                       'accepted_index': idx, 'accepted_label': accepted[idx]['label'] if idx is not None else None,
                       'accepted_value': accepted[idx]['value'] if idx is not None else None})
    n_hits = NEGATIVE_MATCHES.get(cell, {})
    n_rows = []
    for ordinal, item in enumerate(negative, 1):
        idx = n_hits.get(ordinal)
        if idx is not None:
            assert set(item['evidence_ids']) & set(accepted[idx]['evidence_ids'])
        n_rows.append({'negative_ordinal': ordinal, 'excluded_claim': item['excluded_claim'],
                       'matched': idx is not None, 'accepted_index': idx})
    gold_review[cell] = {'matched': len(hits), 'total': len(gold),
                         'priority_matched': sum(r['matched'] and r['priority_material'] for r in g_rows),
                         'priority_total': sum(r['priority_material'] for r in g_rows), 'items': g_rows}
    negative_review[cell] = {'matched': len(n_hits), 'total': len(negative), 'items': n_rows}

(ANALYSIS / 'gold-review.json').write_text(json.dumps(gold_review, indent=2, ensure_ascii=False) + '\n')
(ANALYSIS / 'negative-review.json').write_text(json.dumps(negative_review, indent=2, ensure_ascii=False) + '\n')
for cell in CELLS:
    print(cell, gold_review[cell]['matched'], '/', gold_review[cell]['total'],
          'priority', gold_review[cell]['priority_matched'], '/', gold_review[cell]['priority_total'],
          'negative', negative_review[cell]['matched'], '/', negative_review[cell]['total'])
