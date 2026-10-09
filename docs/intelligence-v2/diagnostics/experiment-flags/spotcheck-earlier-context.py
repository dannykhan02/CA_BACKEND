"""Preserve a source-text spot check of earlier four-response classifications."""
import json
import pathlib

DIAG = pathlib.Path(__file__).resolve().parent.parent
path = DIAG / 'unicef-4call/context-span-audit.json'
audit = json.loads(path.read_text())
selected = [
    ('C1', 0, 'OUTSIDE_LOCALITY', 'E033 row has 69.4 and no year. E061 titles the 2024 Top 30 table on the same page but 28 spans away; E032 is absent.'),
    ('C2', 0, 'OUTSIDE_LOCALITY', 'The same E033 value is period-null. E061 is present but fails the configured 12-span limit.'),
    ('T1', 0, 'OUTSIDE_LOCALITY', 'Temperature-zero response still cites E033 alone; E061 remains 28 spans away.'),
    ('T2', 0, 'OUTSIDE_LOCALITY', 'Repeated temperature-zero response has the same period-null E033 grounding.'),
    ('C1', 27, 'AMBIGUOUS_CONTEXT', 'E066 gives amounts, while E064/E065 give a 2020–2024 range and five columns without value-column ownership in the saved text.'),
    ('C1', 47, 'ALREADY_SUPPORTED', 'E075 itself says Since 2000 for the 7.9 million child-survival observation.'),
    ('C2', 102, 'AMBIGUOUS_CONTEXT', 'E144 mentions Strategic Plan 2022–2025, but yearly allocations are in E143 and applicability to E144 additional allocations is unclear.'),
    ('T1', 103, 'STRUCTURALLY_UNSAFE', 'E108 has COVID timing for mitigation; whether it dates the investment is ambiguous. Nearby country rows cannot supply 2024.'),
    ('C1', 77, 'OUTSIDE_LOCALITY', 'E137 gives $227 million and $174 million, with no claim-owned 2024 period in that span.'),
    ('C2', 77, 'OUTSIDE_LOCALITY', 'E169 allocation figures have no explicit 2024 period; nearby period phrases concern other blocks.'),
    ('T2', 34, 'AMBIGUOUS_CONTEXT', 'Since 2000 is in E075; E076 vaccination lives saved is a separate following sentence with uncertain temporal scope.'),
]
rows = []
for call, index, manual, reason in selected:
    case = next(c for c in audit['cases'] if c['call']==call and c['raw_index']==index)
    rows.append({'call':call,'raw_index':index,'rule_context_class':case['context_class'],
                 'manual_context_class':manual,'period_accuracy':case['period_accuracy'],
                 'cited_value_spans':case['cited_value_spans'],
                 'selected_candidate_id':case['selected_candidate_id'],
                 'candidate_excerpt':case['context_candidates'][0].get('source_excerpt') if case['context_candidates'] else None,
                 'reason':reason,'disagreement':case['context_class']!=manual})
out={'reviewer':'Codex AI source-text spot check, not independent human review',
     'reviewed':len(rows),'current_rule_disagreements':sum(x['disagreement'] for x in rows),
     'disagreement_handling':'C2 index 102 remains OUTSIDE_LOCALITY in the frozen automated output. Manual inspection finds AMBIGUOUS_CONTEXT because E143 says yearly allocations while E144 describes possible additional allocations; no label or aggregate count was silently changed.',
     'cases':rows}
(DIAG/'unicef-4call/context-span-spotcheck.json').write_text(json.dumps(out,indent=2,ensure_ascii=False)+'\n')
print(json.dumps({'reviewed':out['reviewed'],'disagreements':out['current_rule_disagreements']}))
