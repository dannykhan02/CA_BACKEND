"""Synthetic labels fixed before M0. No production data or provider calls."""
import json
from pathlib import Path
from copy import deepcopy
root=Path(__file__).parent
base={'category':'kpis','label':'Revenue','value':{'value':'12000000','unit':'KES','period':'2025'},'context':{'subject':'Example reporting company','owner':'registry:synthetic-001','value_basis':'actual','aggregation':'total','analytical_eligible':True,'verification':'source_verified'},'source':{'document_id':'A','intelligence_revision_id':'revision-A','insight_id':'kpi-1','evidence':'Example reporting company revenue for calendar 2025: KES 12,000,000.','location_kind':'page','page_number':1}}
cases=[]
def add(id,scenario,a=None,b=None,outcome='equivalent',identity='same_concept',compatibility='compatible',abstention='none',explanation=None):
    a=deepcopy(base if a is None else a); b=deepcopy(a if b is None else b)
    cases.append({'id':id,'scenario':scenario,'inputs':{'a':a if isinstance(a,list) else [a],'b':b if isinstance(b,list) else [b]},'expected':{'outcome':outcome,'identity':identity,'compatibility':compatibility,'abstention':abstention},'supporting_evidence':'Synthetic passages and locations are attached to each input; they establish only the context stated in this case.','explanation':explanation or scenario+'. '+('No substantive assertion is supported.' if outcome in ['not_comparable','insufficient_evidence'] else 'Preserve both original representations and source references.')})
def pair(id,scenario,value,unit=None,period=None,outcome='equivalent',**kw):
    b=deepcopy(base); b['value']['value']=value
    if unit is not None:b['value']['unit']=unit
    if period is not None:b['value']['period']=period
    b['source'].update(document_id='B',intelligence_revision_id='revision-B',evidence=f"Example reporting company revenue: {b['value']['value']} {b['value']['unit']}; {b['value']['period']}.")
    add(id,scenario,b=b,outcome=outcome,**kw)
for id,sc,val in [('n01','Comma versus no comma','12,000,000'),('n02','Decimal formatting','12000000.00'),('n03','Different precision with equal value','12000000.0000')]:pair(id,sc,val)
a=deepcopy(base);a['value']['value']='-1234'; b=deepcopy(a);b['value']['value']='(1,234)';add('n04','Accounting parentheses equal a negative value',a,b)
pair('n05','Negative and positive are different compatible values','-12000000',outcome='supported_change')
pair('n06','Zero versus null',None,outcome='insufficient_evidence',compatibility='unknown',abstention='invalid_numeric')
a=deepcopy(base);a['value']['value']='0';b=deepcopy(a);b['value']['value']='0.00';add('n07','Exact zero versus decimal zero',a,b)
pair('n08','Million scale equality','12','KES million')
pair('n09','Billion scale equality','0.012','KES billion')
pair('n10','Thousand scale equality','12000','KES thousand')
pair('n11','Malformed grouping','12,00,000',outcome='insufficient_evidence',compatibility='unknown',abstention='invalid_numeric')
pair('n12','Malformed value contains multiple numbers','12 of 20',outcome='insufficient_evidence',compatibility='unknown',abstention='invalid_numeric')
a=deepcopy(base);a['value'].update(value='25',unit='%');b=deepcopy(a);b['value']['unit']='percentage points';add('n13','Percent versus percentage points',a,b,'not_comparable',compatibility='incompatible',abstention='measurement_mismatch')
a=deepcopy(base);a['value']['value']='9007199254740993.01';b=deepcopy(a);b['value']['value']='9007199254740993.02';add('n14','Decimal difference beyond float precision',a,b,'supported_change')
pair('u01','Same currency and unit, substantive amount difference','12500000',outcome='supported_change')
a=deepcopy(base);a['label']='Length';a['value'].update(value='1',unit='kilometres');b=deepcopy(a);b['value'].update(value='1000',unit='metres');add('u02','Equivalent physical length units',a,b)
a=deepcopy(base);a['label']='Area';a['value'].update(value='1',unit='hectares');b=deepcopy(a);b['value'].update(value='10000',unit='square metres');add('u03','Hectares versus square metres',a,b)
pair('u04','Different measurement families','12','employees',outcome='not_comparable',compatibility='incompatible',abstention='measurement_mismatch')
pair('u05','Missing unit','12','',outcome='insufficient_evidence',compatibility='unknown',abstention='unknown_unit')
pair('u06','Unknown unit','12','unknown',outcome='insufficient_evidence',compatibility='unknown',abstention='unknown_unit')
pair('u07','KES versus USD','12000000','USD',outcome='not_comparable',compatibility='incompatible',abstention='measurement_mismatch')
pair('u08','FX unavailable; do not invent exchange rate','93000','USD',outcome='not_comparable',compatibility='incompatible',abstention='measurement_mismatch')
pair('p01','Same reporting period','12500000',outcome='supported_change')
pair('p02','Comparable different calendar annual periods','12500000',period='2026',outcome='period_comparison',compatibility='period_comparable')
pair('p03','Q2 versus full year','12500000',period='Q2 2025',outcome='not_comparable',compatibility='incompatible',abstention='period_mismatch')
a=deepcopy(base);a['value']['period']='twelve months ended June 30, 2025';b=deepcopy(a);b['value']['period']='twelve months ended December 31, 2025';add('p04','Different fiscal year ends',a,b,'not_comparable',compatibility='incompatible',abstention='period_mismatch')
for id,sc,field,val in [('p05','Forecast versus actual','value_basis','forecast'),('p06','Budget versus actual','value_basis','budget'),('p07','Original versus revised forecast','value_basis','revised_forecast')]:
    b=deepcopy(base);b['context'][field]=val;add(id,sc,b=b,outcome='not_comparable',compatibility='incompatible',abstention='basis_mismatch')
pair('p08','Incomplete reporting period','12',period='first five months of 2025',outcome='insufficient_evidence',compatibility='unknown',abstention='unknown_period')
a=deepcopy(base);a['value']['period']=None;b=deepcopy(a);b['value']['value']='13';add('p09','Both reporting periods unknown',a,b,'insufficient_evidence',compatibility='unknown',abstention='unknown_period')
pair('p10','Unknown period on one side','13',period='unknown',outcome='insufficient_evidence',compatibility='unknown',abstention='unknown_period')
def entity(name):
    r=deepcopy(base);r.update(category='entities',label='organization',value={'value':name},context={});r['source']['evidence']=name;return r
add('e01','Exact entity observation names',entity('Example Ltd'),entity('Example Ltd'))
add('e02','Ltd versus Limited is not verified entity identity',entity('Example Ltd'),entity('Example Limited'),'insufficient_evidence',identity='unverified',compatibility='unknown',abstention='identity_unverified')
for id,sc,owner in [('e03','Similar names belong to different companies','registry:synthetic-002'),('e04','Parent and subsidiary have different identities','registry:subsidiary-001'),('e05','Multiple organizations share a label','registry:synthetic-003')]:
    b=deepcopy(base);b['context']['owner']=owner;add(id,sc,b=b,outcome='not_comparable',compatibility='incompatible',abstention='owner_mismatch')
b=deepcopy(base);b['context']['owner']=None;add('e06','Unknown reporting entity identity',b=b,outcome='insufficient_evidence',compatibility='unknown',abstention='unknown_owner')
def clause(title='Payment deadline',days='45 days',evidence=None):
    r=deepcopy(base);r.update(category='deadlines',label=title,value={'description':'Buyer shall pay the invoice.','due_date':None,'relative_text':days,'date_type':'relative'},context={'responsible_party':'Buyer','obligation_id':'agreement:payment'});r['source']['evidence']=evidence or f'Buyer shall pay within {days}.';return r
add('c01','45 day versus 30 day payment deadline',clause(),clause(days='30 days'),'supported_change')
b=deepcopy(base);b['label']='Contract amount';a=deepcopy(b);b['value']['value']='12500000';add('c02','Different payment amounts under established scope',a,b,'supported_change')
a=clause();b=deepcopy(a);b['value']['description']='Invoice is payable by the buyer.';add('c03','Same obligation expressed with different description',a,b)
add('c04','Amendment explicitly changes one payment clause',clause(),clause(days='30 days',evidence='Clause payment is amended: Buyer shall pay within 30 days.'),'supported_change')
add('c05','Amendment omits an unchanged provision',clause('Renewal notice'),[],'only_a',identity='unmatched',compatibility='not_applicable',abstention='no_deletion')
add('c06','Missing extraction is not removed provision',clause(),[],'only_a',identity='unmatched',compatibility='not_applicable',abstention='no_deletion')
a=clause();b=deepcopy(a);b['context']['responsible_party']='Supplier';add('c07','Same clause title with different responsible parties',a,b,'not_comparable',compatibility='incompatible',abstention='party_mismatch')
b=deepcopy(base);b['context']['value_basis']='bank_settlement';add('c08','Payment schedule versus bank settlement',b=b,outcome='not_comparable',compatibility='incompatible',abstention='basis_mismatch')
a=clause();b=deepcopy(a);b['context']['obligation_id']='agreement:delivery';add('c09','Shared deadline title refers to different obligations',a,b,'not_comparable',compatibility='incompatible',abstention='obligation_mismatch')
a=clause();b=clause(days='deleted',evidence='Clause payment is deleted in its entirety.');add('c10','Explicit deletion has evidence but needs authority review',a,b,'potential_conflict',abstention='no_automatic_legal_effect')
add('c11','Reworded titles require semantic candidate review',clause('Renewal notice'),clause('Notice of renewal'),'equivalent',identity='semantic_candidate',abstention='no_verified_semantic_identity')
for id,sc,update in [('s01','Missing source passage',{'evidence':None}),('s02','Approximate passage location remains approximate',{'location_kind':'approximate','page_number':None})]:
    b=deepcopy(base);b['source'].update(update);add(id,sc,b=b,outcome='insufficient_evidence' if id=='s01' else 'equivalent',compatibility='unknown' if id=='s01' else 'compatible',abstention='missing_evidence' if id=='s01' else 'no_exact_citation')
b=deepcopy(base);b['context']['analytical_eligible']=False;add('s03','Analytically ineligible figure',b=b,outcome='insufficient_evidence',compatibility='unknown',abstention='ineligible')
add('s04','Duplicate identical observations', [deepcopy(base),deepcopy(base)],[deepcopy(base)],'equivalent')
b=deepcopy(base);b['context']['superseded']=True;add('s05','Superseded figure is excluded from substantive assertions',b=b,outcome='insufficient_evidence',compatibility='unknown',abstention='superseded')
b=deepcopy(base);b['value']['value']='13';add('s06','Conflicting observations within one source',[deepcopy(base),b],deepcopy(base),'insufficient_evidence',compatibility='unknown',abstention='ambiguous_candidates')
for id,sc in [('s07','Stale published revision remains readable and visibly outdated'),('s08','Deleted source cannot expose evidence'),('s09','Revoked access denies saved result and export'),('s10','Partially processed document cannot start comparison')]:
    add(id,sc,outcome={'s07':'historical','s08':'denied','s09':'denied','s10':'rejected'}[id],identity='integration_boundary',compatibility='not_applicable',abstention='state_gate');cases[-1]['layer']='integration'
for c in cases:
    for side,rows in c['inputs'].items():
        for r in rows:
            r['source']['document_id']=side.upper()
            r['source']['intelligence_revision_id']='revision-'+side.upper()
# Evidence-only correction: every nonmissing passage states its record's actual values
# and supplied context. Target labels and value/unit/period inputs remain unchanged.
for case in cases:
    for rows in case['inputs'].values():
        for record in rows:
            if record['source'].get('evidence') is None:
                continue
            if record['category'] == 'kpis':
                record['source']['evidence'] = 'Synthetic source statement: ' + json.dumps({
                    'concept': record['label'], **record['value'], **record.get('context', {})}, ensure_ascii=False)
            elif record['category'] == 'deadlines':
                record['source']['evidence'] += ' Synthetic clause context: ' + json.dumps({**record['value'], **record.get('context', {})})
root.joinpath('cases.json').write_text(json.dumps(cases,indent=2)+'\n')
print(f'{len(cases)} labelled cases')
