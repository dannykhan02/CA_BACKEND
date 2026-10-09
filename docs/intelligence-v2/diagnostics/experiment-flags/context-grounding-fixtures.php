<?php

// Offline synthetic fixtures for current validation, provenance, typing and identity.
foreach (['APP_ENV'=>'testing','DB_CONNECTION'=>'sqlite','DB_DATABASE'=>':memory:',
    'CACHE_STORE'=>'array','QUEUE_CONNECTION'=>'sync','LOG_CHANNEL'=>'stderr'] as $key=>$value) {
    putenv("$key=$value"); $_ENV[$key]=$value; $_SERVER[$key]=$value;
}
$root=dirname(__DIR__,4);
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$merger=app(App\Services\AI\Incremental\EvidenceMerger::class);
$projector=app(App\Services\Intelligence\Values\TypedEvidenceProjector::class);
$template=json_decode(json_decode(file_get_contents(dirname(__DIR__).'/unicef-4call/C1.response.json'),true,512,JSON_THROW_ON_ERROR)['content'][0]['text'],true,512,JSON_THROW_ON_ERROR)['records'][0];

function fixture(string $name,string $header,string $row,string $value,?string $unit,?string $period,
    string $kind='metric',array $ids=['E001','E002']): array {
    global $template,$merger,$projector;
    $text=$header."\n".$row;
    $headerLen=mb_strlen($header);
    $map=[
        ['ordinal'=>1,'key'=>'E001','page'=>1,'start_offset'=>0,'end_offset'=>$headerLen,'type'=>'heading'],
        ['ordinal'=>2,'key'=>'E002','page'=>1,'start_offset'=>$headerLen+1,'end_offset'=>mb_strlen($text),'type'=>'table_row'],
    ];
    $spans=new App\Services\AI\Incremental\EvidenceSpanSet('context-fixture-v1',$text,$map);
    $record=$template;
    $record['label']='German Committee for UNICEF contribution';
    $record['value']=$value;
    $record['subject']='German Committee for UNICEF';
    $record['reference']='synthetic numeric grounding fixture';
    $record['evidence_ids']=$ids;
    $record['unit']=$unit;
    $record['period']=$period;
    $record['kind']=$kind;
    $record['date_type']=null;
    $record['due_date']=null;
    $record['metric_type']='actual';
    $record['value_basis']='total';
    try {
        $validated=App\Services\AI\Incremental\EvidenceSchema::validate(['records'=>[$record]],$text,$spans,'context-fixture-v1');
        $accepted=$validated['records'][0];
        $sources=array_map(fn($e)=>['quote'=>$e['text'],'span_id'=>$e['span_id'],'page'=>$e['page'],
            'start_offset'=>$e['start_offset'],'end_offset'=>$e['end_offset']],$accepted['evidence']);
        $identity=$merger->identity($accepted);
        $rowModel=new App\Models\DocumentEvidence;
        $rowModel->forceFill(['kind'=>$kind,'data'=>$accepted,'sources'=>$sources,
            'identity'=>$identity,'source_id'=>$ids[0]]);
        $projected=$projector->project($rowModel,[]);
        return ['fixture'=>$name,'accepted'=>true,'evidence_ids'=>$accepted['evidence_ids'],
            'value'=>$accepted['value'],'period'=>$accepted['period'],'unit'=>$accepted['unit'],
            'origin'=>$projected['provenance']['origin'],'typed_value'=>$projected['typed']['value'],
            'typed_period'=>$projected['typed']['dates']['period_covered']['period']['text']??null,
            'merger_identity'=>$identity,'resolved_quote'=>$accepted['quote']];
    } catch (App\Exceptions\AiProcessingException $e) {
        return ['fixture'=>$name,'accepted'=>false,'rejection'=>$e->classification,
            'details'=>$e->diagnostics];
    }
}

$out=[];
$out['header_number_30']=fixture('header_number_30','Top 30 Core Resources partners, 2024',
    'German Committee for UNICEF 69.4','30',null,'2024');
$out['two_year_header_2023']=fixture('two_year_header_2023','Top 30 Core Resources partners, 2023 and 2024',
    'German Committee for UNICEF 69.4','69.4',null,'2023');
$out['two_year_header_2024']=fixture('two_year_header_2024','Top 30 Core Resources partners, 2023 and 2024',
    'German Committee for UNICEF 69.4','69.4',null,'2024');
$out['usd_millions_header']=fixture('usd_millions_header','PARTNER USD (MILLIONS)',
    'German Committee for UNICEF 69.4','69.4','USD millions',null);
$out['usd_millions_row_only']=fixture('usd_millions_row_only','PARTNER USD (MILLIONS)',
    'German Committee for UNICEF 69.4','69.4','USD millions',null,'metric',['E002']);
$out['wrong_currency_with_header']=fixture('wrong_currency_with_header','PARTNER USD (MILLIONS)',
    'German Committee for UNICEF 69.4','69.4','EUR millions',null);
$out['unrelated_header_number']=fixture('unrelated_header_number','Top 42 partners, 2024',
    'German Committee for UNICEF 69.4','42',null,'2024');
$out['same_value_2023']=fixture('same_value_2023','2023 revenue 50; 2024 revenue 50',
    'Revenue 50','50',null,'2023');
$out['same_value_2024']=fixture('same_value_2024','2023 revenue 50; 2024 revenue 50',
    'Revenue 50','50',null,'2024');
$out['identity_metric_A']=fixture('identity_metric_A','Top 30 Core Resources partners, 2024',
    'German Committee for UNICEF 69.4','69.4',null,null,'metric',['E002']);
$out['identity_metric_B']=fixture('identity_metric_B','Top 30 Core Resources partners, 2024',
    'German Committee for UNICEF 69.4','69.4',null,null,'metric',['E001','E002']);
$out['identity_metric_period_A']=fixture('identity_metric_period_A','Top 30 Core Resources partners, 2024',
    'German Committee for UNICEF 69.4','69.4',null,'2024','metric',['E002']);
$out['identity_metric_period_B']=fixture('identity_metric_period_B','Top 30 Core Resources partners, 2024',
    'German Committee for UNICEF 69.4','69.4',null,'2024','metric',['E001','E002']);
$out['identity_fact_A']=fixture('identity_fact_A','Top 30 Core Resources partners, 2024',
    'German Committee for UNICEF 69.4','69.4',null,null,'fact',['E002']);
$out['identity_fact_B']=fixture('identity_fact_B','Top 30 Core Resources partners, 2024',
    'German Committee for UNICEF 69.4','69.4',null,null,'fact',['E001','E002']);
$out['comparisons']=[
    'metric_same_period_merger_identity_equal'=>$out['identity_metric_A']['merger_identity']===$out['identity_metric_B']['merger_identity'],
    'metric_explicit_period_merger_identity_equal'=>$out['identity_metric_period_A']['merger_identity']===$out['identity_metric_period_B']['merger_identity'],
    'fact_merger_identity_equal'=>$out['identity_fact_A']['merger_identity']===$out['identity_fact_B']['merger_identity'],
    'different_period_same_value_merger_identity_equal'=>$out['same_value_2023']['merger_identity']===$out['same_value_2024']['merger_identity'],
];
$exactA=$template;
$exactA['kind']='metric'; $exactA['evidence_ids']=['E033'];
$exactA['quote']='German Committee for UNICEF 69.4';
$exactB=$exactA;
$exactB['evidence_ids']=['E032','E033'];
$exactB['quote']="Top 30 Core Resources partners, 2024\n\nGerman Committee for UNICEF 69.4";
$out['exact_counterfactual_identity']=[
    'metric_E033_identity'=>$merger->identity($exactA),
    'metric_E032_E033_identity'=>$merger->identity($exactB),
    'metric_equal'=>$merger->identity($exactA)===$merger->identity($exactB),
    'B_valid_in_earlier_request'=>false,
];
$exactA['kind']='fact'; $exactB['kind']='fact';
$out['exact_counterfactual_identity']['fact_equal']=$merger->identity($exactA)===$merger->identity($exactB);
$savedSource=file_get_contents($root.'/unicef-full-extracted.txt');
$savedRows=json_decode(file_get_contents($root.'/unicef-source-spans.json'),true,512,JSON_THROW_ON_ERROR);
$savedMap=array_map(fn($r)=>['ordinal'=>(int)$r['ordinal'],'key'=>$r['span_key'],
    'page'=>(int)$r['page'],'start_offset'=>(int)$r['start_offset'],
    'end_offset'=>(int)$r['end_offset'],'type'=>$r['type']],$savedRows);
$savedVersion='8acd7d011fac21b31b65c5dfa251423d';
$savedSpans=(new App\Services\AI\Incremental\EvidenceSpanSet($savedVersion,$savedSource,$savedMap))->forRange(4218,29519);
foreach (['E032_E033'=>['E032','E033'],'E033_E061'=>['E033','E061']] as $name=>$citationIds) {
    $candidate=$template; $candidate['evidence_ids']=$citationIds;
    try {
        App\Services\AI\Incremental\EvidenceSchema::validate(['records'=>[$candidate]],
            mb_substr($savedSource,4218,29519-4218),$savedSpans,$savedVersion);
        $out['exact_saved_request_validation'][$name]='ACCEPTED';
    } catch (App\Exceptions\AiProcessingException $e) {
        $out['exact_saved_request_validation'][$name]=[
            'classification'=>$e->classification,
            'rejection_reasons'=>$e->diagnostics['rejection_reasons']??null,
            'reason'=>$e->diagnostics['reason']??null,
        ];
    }
}
$path=__DIR__.'/context-grounding-fixtures.json';
file_put_contents($path,json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n",LOCK_EX);
echo json_encode(array_map(fn($x)=>is_array($x)&&isset($x['fixture'])
    ? ['fixture'=>$x['fixture'],'accepted'=>$x['accepted'],'origin'=>$x['origin']??null] : $x,$out),JSON_THROW_ON_ERROR)."\n";
