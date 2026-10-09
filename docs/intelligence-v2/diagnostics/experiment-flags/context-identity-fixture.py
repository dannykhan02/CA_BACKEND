"""Reuse the frozen four-call primary/strict identity functions offline."""
import json
import pathlib

DIAG = pathlib.Path(__file__).resolve().parent.parent
source = (DIAG / 'unicef-4call-analyze.py').read_text()
prefix = source.split('\nprimary_sets = {}', 1)[0]
namespace = {'__file__': str(DIAG / 'unicef-4call-analyze.py')}
exec(compile(prefix, str(DIAG / 'unicef-4call-analyze.py'), 'exec'), namespace)
response = json.loads((DIAG / 'unicef-4call/C1.response.json').read_text())
record = json.loads(response['content'][0]['text'])['records'][0]
assert record['evidence_ids'] == ['E033'] and record['value'] == '69.4'
a = dict(record, evidence_ids=['E033'])
b = dict(record, evidence_ids=['E032', 'E033'])
base = namespace['base_identity']
primary = namespace['primary']
strict = namespace['strict']
out = {'A': {'evidence_ids': a['evidence_ids'], 'base_identity': namespace['id_text'](base(a)),
             'experiment_primary_identity': primary(a), 'strict_identity': strict(a)},
       'B': {'evidence_ids': b['evidence_ids'], 'base_identity': namespace['id_text'](base(b)),
             'experiment_primary_identity': primary(b), 'strict_identity': strict(b)},
       'primary_equal': primary(a) == primary(b), 'strict_equal': strict(a) == strict(b),
       'note': 'B uses E032 only as a counterfactual identity fixture; E032 is absent from all four exact saved requests and would be rejected by span validation.'}
(pathlib.Path(__file__).with_name('context-identity-fixture.json')).write_text(json.dumps(out, indent=2)+'\n')
print(json.dumps({'primary_equal': out['primary_equal'], 'strict_equal': out['strict_equal']}))
