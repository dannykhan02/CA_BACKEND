<?php

// Pure diagnostic replay of one saved raw response. No application table writes.
$call = $argv[1] ?? ''; $run = $argv[2] ?? '';
if (! in_array($call, ['C1','C2','T1','T2'], true) || ! preg_match('/^[AB]$/', $run)) {
    fwrite(STDERR,"usage: replay.php C1|C2|T1|T2 A|B\n"); exit(2);
}
$dir=__DIR__.'/unicef-4call'; $root=dirname(__DIR__,3);
$raw=file_get_contents($dir.'/'.$call.'.response.json');
$source=file_get_contents($root.'/unicef-full-extracted.txt');
$storedBytes=file_get_contents($root.'/unicef-source-spans.json');
if ($raw===false || $source===false || $storedBytes===false
    || hash('sha256',$source)!=='c919b4bb36fb3467906cc176a72d57c422260d6a17bbb4f4affc05c93415015c'
    || hash('sha256',$storedBytes)!=='df213788ebdc82a9fa98280cc47eed71c2f90862e027fe02b42fbd0882021b55') {
    fwrite(STDERR,"response/source gate failed\n"); exit(3);
}
foreach (['APP_ENV'=>'testing','DB_CONNECTION'=>'sqlite','DB_DATABASE'=>':memory:',
    'CACHE_STORE'=>'array','QUEUE_CONNECTION'=>'sync','LOG_CHANNEL'=>'stderr'] as $key=>$value) {
    putenv("$key=$value"); $_ENV[$key]=$value; $_SERVER[$key]=$value;
}
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Carbon::setTestNow('2026-10-09T00:00:00+03:00');
$provider=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
$client=app(App\Services\AnthropicClient::class);
$decoded=(new ReflectionMethod($client,'decodeJsonContent'))->invoke($client,$provider);
$rows=json_decode($storedBytes,true,512,JSON_THROW_ON_ERROR);
$map=array_map(fn($r)=>['ordinal'=>(int)$r['ordinal'],'key'=>$r['span_key'],
    'page'=>(int)$r['page'],'start_offset'=>(int)$r['start_offset'],
    'end_offset'=>(int)$r['end_offset'],'type'=>$r['type']],$rows);
$version='8acd7d011fac21b31b65c5dfa251423d';
$spans=(new App\Services\AI\Incremental\EvidenceSpanSet($version,$source,$map))->forRange(4218,29519);
$slice=mb_substr($source,4218,29519-4218);
$all=App\Services\AI\Incremental\EvidenceSchema::validate($decoded,$slice,$spans,$version);
$merger=app(App\Services\AI\Incremental\EvidenceMerger::class);
$projector=app(App\Services\Intelligence\Values\TypedEvidenceProjector::class);
$selector=app(App\Services\Intelligence\Brief\KeyFigureSelector::class);
$trace=[]; $prepared=[]; $mergeSeen=[];
foreach ($decoded['records'] as $i=>$record) {
    $entry=['raw_index'=>$i,'raw_record'=>$record,'decoded'=>true,'parsed'=>true,
        'schema_valid'=>false,'validated'=>false,'grounded'=>false,'rejection_class'=>null,
        'rejection_reason'=>null,'typed'=>null,'provenance'=>null,'kind'=>null,
        'merge_identity'=>null,'dedupe_collision'=>false,'key_figure_eligible'=>false];
    try {
        $single=App\Services\AI\Incremental\EvidenceSchema::validate(['records'=>[$record]],$slice,$spans,$version);
        $accepted=$single['records'][0];
        $entry['schema_valid']=true; $entry['validated']=true; $entry['grounded']=isset($accepted['evidence']);
        $entry['accepted_record']=$accepted; $entry['kind']=$accepted['kind'];
        $identity=$merger->identity($accepted);
        $entry['merge_identity']=$identity;
        $entry['dedupe_collision']=isset($mergeSeen[$identity]);
        $mergeSeen[$identity]=true;
        $sources=array_map(fn($e)=>['quote'=>$e['text'],'span_id'=>$e['span_id'],
            'extraction_version'=>$version,'page'=>$e['page'],
            'start_offset'=>$e['start_offset'],'end_offset'=>$e['end_offset']],$accepted['evidence']??[]);
        $row=new App\Models\DocumentEvidence();
        $row->forceFill(['workspace_id'=>'01a0c536-e58b-700c-a98c-939ed8425de0',
            'document_id'=>'01a11cf0-4071-737b-b92a-f4a55c1053d8',
            'kind'=>$accepted['kind'],'data'=>$accepted,'sources'=>$sources,
            'identity'=>$identity,'source_id'=>$accepted['evidence_ids'][0]??null]);
        $projected=$projector->project($row,[]);
        $entry['typed']=$projected['typed']??null;
        $entry['provenance']=$projected['provenance']??null;
        $value=$projected['typed']['value']??null;
        $entry['key_figure_eligible']=$accepted['kind']==='metric'
            && ($projected['provenance']['origin']??null)==='document'
            && ($value['type']??null)==='money' && ($value['unit_kind']??null)==='currency'
            && is_string($value['currency']??null) && $value['currency']!==''
            && is_numeric($value['number']??null) && is_finite((float)$value['number'])
            && is_string($row->source_id);
        $prepared[]=['kind'=>$accepted['kind'],'data'=>$projected,'typed'=>$projected['typed'],
            'provenance'=>$projected['provenance'],'source_id'=>$row->source_id,
            'identity'=>$identity];
    } catch (App\Exceptions\AiProcessingException $e) {
        $entry['rejection_class']=$e->classification;
        $entry['rejection_reason']=$e->diagnostics['rejection_reasons']??$e->diagnostics['reason']??null;
        if ($e->classification!=='invalid_schema') $entry['schema_valid']=true;
        if ($e->classification==='invalid_date') $entry['grounded']=true;
    }
    $trace[]=$entry;
}
$selected=$selector->select($prepared,new DateTimeImmutable('2026-10-09T00:00:00+03:00'));
$artifact=['call'=>$call,'fixed_as_of'=>'2026-10-09T00:00:00+03:00',
    'raw_sha256'=>hash('sha256',$raw),'stop_reason'=>$provider['stop_reason']??null,
    'decoded_count'=>count($decoded['records']),'aggregate_validation'=>$all['_validation'],
    'accepted_count'=>count($all['records']),'merge_identity_count'=>count($mergeSeen),
    'key_figure_eligible_count'=>count(array_filter($trace,fn($x)=>$x['key_figure_eligible'])),
    'selected_top_six_merge_identities'=>array_column($selected,'identity'),
    'trace'=>$trace];
$json=json_encode($artifact,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
file_put_contents($dir.'/'.$call.'.replay-'.$run.'.json',$json,LOCK_EX);
echo json_encode(['call'=>$call,'replay'=>$run,'hash'=>hash('sha256',$json),
    'decoded_count'=>$artifact['decoded_count'],'accepted_count'=>$artifact['accepted_count'],
    'eligible_count'=>$artifact['key_figure_eligible_count']],JSON_THROW_ON_ERROR)."\n";
