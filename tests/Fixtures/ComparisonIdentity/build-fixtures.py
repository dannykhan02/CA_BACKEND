"""Tier 1 only: synthetic, labelled identity contracts independent of M0 labels."""
import copy
import json
from pathlib import Path
root=Path(__file__).resolve().parent

def lei(prefix):
    numeric=''.join(c if c.isdigit() else str(ord(c)-55) for c in prefix+'00')
    return prefix+str(98-int(numeric)%97).zfill(2)
ID1=lei('123400ABCDEFGHIJKL')
ID2=lei('567800ABCDEFGHIJKL')

def fact(doc, owner='Example Ltd', identifier=None):
    quote=f'{owner}'+(f' (LEI: {identifier})' if identifier else '')+' reports consolidated actual Revenue 100 KES in 2025.'
    return {'category':'kpis','label':'Revenue','value':{'value':'100','unit':'KES','period':'2025'},
            'context':{'concept_id':'definition:revenue','reported_owner':owner,'owner':None,'subject':'consolidated','value_basis':'actual','aggregation':'total','analytical_eligible':True,'verification':'source_verified'},
            'source':{'document_id':doc,'source_id':'kpi:1','insight_id':1,'intelligence_revision_id':'rev-'+doc,'evidence':quote,'evidence_ids':['evidence-'+doc],
                      'spans':[{'quote':quote,'span_id':'E001','start_offset':0,'end_offset':len(quote)}]}}

cases=[]
def add(id, name, a=None, b=None, expected='insufficient_evidence', state='candidate', action=None, **extra):
    a=copy.deepcopy(a or fact('a')); b=copy.deepcopy(b or fact('b'))
    if b['category']=='kpis' and b['value']['value']=='100': b['value']['value']='200'
    cases.append({'id':id,'scenario':name,'a':a if isinstance(a,list) else [a],'b':b if isinstance(b,list) else [b],
                  'expected_outcome':expected,'expected_identity':state,'action':action,**extra})

a=fact('a',identifier=ID1); b=fact('b',identifier=ID1)
add('i01','Shared source-bound organization identifier',a,b,'supported_change','established')
add('i02','Same name without identity proof')
add('i03','Terminal legal suffix variant',b=fact('b','Example Limited'))
add('i04','Different entities with similar names',b=fact('b','Example Holdings Ltd'))
a=fact('a','Example Holdings Ltd',ID1); b=fact('b','Example Subsidiary Ltd',ID2)
add('i05','Parent versus subsidiary',a,b,state='contradictory')
a=fact('a');b=fact('b');b['context']['reported_owner']=None
add('i06','Mentioned lender is not reporting owner',a,b,state='unknown')
a=fact('a',identifier=ID1);b=fact('b',identifier=ID1);dup=copy.deepcopy(b);dup['source']['source_id']='kpi:2';dup['source']['insight_id']=2
# Avoid duplicate source-key overwrites: keep both independently reviewable occurrences.
cases.append({'id':'i07','scenario':'Multiple identical-name candidates','a':[a],'b':[b,dup],'expected_outcome':'insufficient_evidence','expected_identity':'established','action':None})
add('i08','Explicit user-reviewed owner correspondence',expected='supported_change',state='confirmed',action='confirmed')
add('i09','Rejected owner candidate',state='rejected',action='rejected')
add('i10','Revoked correspondence',state='revoked',action='revoked')
add('i11','Revision changed since review',state='candidate',action='confirmed',stale_revision=True)
add('i12','Conflicting source identifiers',fact('a',identifier=ID1),fact('b',identifier=ID2),state='contradictory')
add('i13','Same KPI, different known owner',fact('a','First Ltd',ID1),fact('b','Second Ltd',ID2),state='contradictory')
a=fact('a');b=fact('b');b['context']['concept_id']='definition:profit';b['label']='Profit'
add('i14','Same owner, different KPI',a,b,'mixed','unknown',action='confirmed')
a=fact('a');b=fact('b');b['value']['period']='2026'
add('i15','Confirmed owner, incompatible period',a,b,'not_comparable','confirmed',action='confirmed')
add('i16','Confirmed owner, compatible context',expected='supported_change',state='confirmed',action='confirmed')

def deadline(doc):
    return {'category':'deadlines','label':'Payment','value':{'description':'Pay invoice','relative_text':'within 45 days of invoice receipt','date_type':'relative','due_date':None},
            'context':{'responsible_party':'Buyer','deadline_type':'obligation','obligation_reference':'Agreement X Clause 4'},
            'source':{'document_id':doc,'source_id':'deadline:1','intelligence_revision_id':'rev-'+doc,'evidence':'Buyer pays under Agreement X Clause 4.','evidence_ids':['ev-'+doc],'spans':[{'quote':'Buyer pays under Agreement X Clause 4.'}]}}
a=deadline('a');b=deadline('b');b['context']['obligation_reference']='Agreement Y Clause 5'
add('i17','Same title, different underlying obligation',a,b,'not_comparable')
a=deadline('a');b=deadline('b');b['context']['responsible_party']='Supplier'
add('i18','Same deadline, different responsible party',a,b,'not_comparable')
a=deadline('a');b=deadline('b');b['label']='Invoice payment';b['value']['relative_text']='within 30 days of invoice receipt'
add('i19','Explicit reviewed individual clause alignment',a,b,'supported_change','confirmed',action='confirmed')
a=deadline('a');b=deadline('b');b['label']='Another provision';b['context']['obligation_reference']='Clause 8'
add('i20','Amendment omission is not deletion',a,b,'mixed','unknown')
a=fact('a');b=fact('b');b['source']['evidence']=None
add('i21','Missing source passage despite confirmation',a,b,state='confirmed',action='confirmed')
a=fact('a');b=fact('b');dup=copy.deepcopy(b);dup['source']['source_id']='kpi:2'
cases.append({'id':'i22','scenario':'Duplicate fact candidates are not arbitrarily zipped','a':[a],'b':[b,dup],'expected_outcome':'insufficient_evidence','expected_identity':'confirmed','action':'confirmed'})
a=fact('a');b=fact('b');b['context']['subject']='standalone'
add('i23','Confirmed owner, different reporting scope',a,b,'not_comparable','confirmed',action='confirmed')
a=fact('a');b=fact('b');b['value']['unit']='USD'
add('i24','Confirmed owner, different currency',a,b,'not_comparable','confirmed',action='confirmed')
a=fact('a');b=fact('b');b['context']['value_basis']='forecast'
add('i25','Confirmed owner, actual versus forecast',a,b,'not_comparable','confirmed',action='confirmed')
a=fact('a');b=fact('b');b['context']['analytical_eligible']=False
add('i26','Confirmed owner does not cure ineligibility',a,b,state='confirmed',action='confirmed')
a=deadline('a');b=deadline('b');b['value']['relative_text']='deleted'
add('i27','Aligned explicit deletion still needs interpretation',a,b,state='confirmed',action='confirmed')
a=deadline('a');b=deadline('b');b['label']='Reworded payment provision'
add('i28','Reworded provision with explicit reference and review',a,b,'equivalent','confirmed',action='confirmed')
a=fact('a');b=fact('b');a['context']['metric_status']='actual';b['context']['metric_status']='forecast'
add('i29','Separate actual status survives equal measurement basis',a,b,'not_comparable','confirmed',action='confirmed')
a=fact('a');b=fact('b');b['source']['intelligence_revision_id']='unpublished-revision'
add('i30','Unpublished revision cannot inherit reviewed binding',a,b,state='candidate',action='confirmed',stale_revision=True)
# Keep all positive source passages faithful to the varied synthetic facts.
for case in cases:
    for side in ['a', 'b']:
        for row in case[side]:
            source=row['source']
            if source.get('evidence') is None:
                continue
            if row['category']=='deadlines':
                source['evidence']=f"{row['context']['responsible_party']} shall pay under {row['context']['obligation_reference']}: {row['value']['relative_text']}."
            elif row['category']=='kpis':
                source['evidence']=source['evidence'].replace('Revenue 100 KES in 2025', f"{row['label']} {row['value']['value']} {row['value']['unit']} in {row['value']['period']}")
            for span in source.get('spans',[]):
                span['quote']=source['evidence'];span['start_offset']=0;span['end_offset']=len(source['evidence'])
(root/'cases.json').write_text(json.dumps(cases,indent=2)+'\n')
