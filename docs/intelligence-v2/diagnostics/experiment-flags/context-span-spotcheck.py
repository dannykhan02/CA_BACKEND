"""Record manual source-text review of selected deterministic classifications."""
import json
import pathlib

BASE = pathlib.Path(__file__).resolve().parent
ANALYSIS = BASE / 'paid-study' / 'analysis'
audit = json.loads((ANALYSIS / 'context-span-audit.json').read_text())
selected = [
    ('india_wash-C1', 22, 'SAFE_CONTEXT_CANDIDATE', 'E212 continues E211 with the same Swachh Bharat subject and the pronoun it; By 2024 can qualify the described state.'),
    ('india_wash-K3', 23, 'SAFE_CONTEXT_CANDIDATE', 'The same E211/E212 paragraph continuation is in the frozen India request; a second citation would use 2 of 3 IDs.'),
    ('unicef_reduced-C1', 5, 'AMBIGUOUS_CONTEXT', 'E103 has five years and several value arrays flattened from charts; no reliable value-to-year column ownership remains.'),
    ('india_wash-C1', 9, 'AMBIGUOUS_CONTEXT', 'E232 says investment over ten years, then a results list continues into E233; the ten-year phrase may modify investment only.'),
    ('unicef_reduced-C1', 15, 'STRUCTURALLY_UNSAFE', 'E108 describes the investment and pandemic mitigation in one sentence, but COVID timing may attach only to mitigation. Other nearby periods belong to other country rows.'),
    ('india_wash-C1', 6, 'STRUCTURALLY_UNSAFE', 'E216 describes a Jal Jeevan launch; E218 five years modifies household connections, not the launch.'),
    ('unicef_reduced-C1', 13, 'STRUCTURALLY_UNSAFE', 'E107 groundwater mapping gives no year; E108 pandemic timing belongs to Guatemala, not Horn of Africa.'),
    ('india_wash-C3', 3, 'OUTSIDE_LOCALITY', 'E206 and E233 already span pages 14 and 16; E205 launch year cannot safely date the constructed-toilets outcome.'),
    ('unicef_reduced-C1', 36, 'OUTSIDE_LOCALITY', 'E128 SDG count is page 8; E124 five years is Sierra Leone on page 7 and cannot date it.'),
    ('india_wash-C1', 0, 'ALREADY_SUPPORTED', 'E201 explicitly states 2014 to 2022 and the 37 percent child-mortality claim in one span.'),
    ('unicef_reduced-C1', 31, 'ALREADY_CITED_CONFLICT', 'E122 explicitly says the stunting reduction was over a decade. The model supplied 2024; E120 nearby has 2024 for an India water row.'),
    ('india_wash-C1', 19, 'ALREADY_SUPPORTED', 'E229 explicitly gives groundwater quality improvement in the last five years.'),
]
rows = []
for cell, index, manual, rationale in selected:
    case = next(c for c in audit['cases'] if c['cell'] == cell and c['raw_index'] == index)
    rows.append({'cell': cell, 'raw_index': index,
                 'rule_context_class': case['context_class'], 'manual_context_class': manual,
                 'rule_period_accuracy': case['period_accuracy'],
                 'model_period': case['model_period'], 'cited_value_spans': case['cited_value_spans'],
                 'selected_candidate_id': case['selected_candidate_id'],
                 'candidate_source_excerpt': case['context_candidates'][0].get('source_excerpt') if case['context_candidates'] else None,
                 'rationale': rationale, 'disagreement': case['context_class'] != manual})
result = {'reviewer': 'Codex AI agent, source-text spot check; not independent human review',
          'selected_count': len(rows), 'current_rule_disagreements': sum(x['disagreement'] for x in rows),
          'no_context_found_examples': 'None available: the rule engine found a nearby explicit period expression for every unresolved case, although most were unsafe, ambiguous or outside locality.',
          'initial_rule_implementation_corrections': [
              {'issue': 'The first regex missed decade-long in E200 and Over 10 years in E237.',
               'initial_effect': 'Three E200 records were misclassified NO_EXPLICIT_PERIOD_CONTEXT; five E237 records were AMBIGUOUS.',
               'resolution': 'Added the literal temporal patterns and reran all cases; these eight are now CORRECT_AND_SUPPORTED.'},
              {'issue': 'First pass treated E108 investment 2024 as definitely WRONG.',
               'initial_effect': 'One false certainty: pandemic timing can attach to mitigation only.',
               'resolution': 'Changed that record to AMBIGUOUS; E122 remains the one clear WRONG case.'},
              {'issue': 'First pass treated E112 and E124 model 2024 as ambiguous because their spans mention other temporal phrases.',
               'initial_effect': 'Two unsupported years were hidden in AMBIGUOUS.',
               'resolution': 'Changed both to NO_EXPLICIT_PERIOD_CONTEXT for the specific recorded claims.'},
              {'issue': 'First pass treated the E103 aggregate 2020-2024 series like a single-year chart point.',
               'initial_effect': 'One caption-range period was marked AMBIGUOUS with the unowned chart points.',
               'resolution': 'Marked only the aggregate period range CORRECT_AND_SUPPORTED; individual value-year pairings remain unverified.'},
          ], 'cases': rows}
(ANALYSIS / 'context-span-spotcheck.json').write_text(json.dumps(result, indent=2, ensure_ascii=False) + '\n')
print(json.dumps({'reviewed': len(rows), 'current_rule_disagreements': result['current_rule_disagreements']}))
