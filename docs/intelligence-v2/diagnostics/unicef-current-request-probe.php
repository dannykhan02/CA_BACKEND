<?php

// Offline diagnostic. One invocation is one fresh PHP process. No production DB or network.
$index = isset($argv[1]) ? (int) $argv[1] : -1;
if (! in_array($index, [0, 1], true)) { fwrite(STDERR, "chunk index 0 or 1 required\n"); exit(2); }
$root = dirname(__DIR__, 3);
$source = file_get_contents($root.'/unicef-full-extracted.txt');
$spanBytes = file_get_contents($root.'/unicef-source-spans.json');
if ($source === false || $spanBytes === false || strlen($source) !== 58536
    || hash('sha256', $source) !== 'c919b4bb36fb3467906cc176a72d57c422260d6a17bbb4f4affc05c93415015c'
    || hash('sha256', $spanBytes) !== 'df213788ebdc82a9fa98280cc47eed71c2f90862e027fe02b42fbd0882021b55') {
    fwrite(STDERR, "artifact gate failed\n"); exit(3);
}
$rows = json_decode($spanBytes, true, 512, JSON_THROW_ON_ERROR);
if (count($rows) !== 360) { fwrite(STDERR, "span count gate failed\n"); exit(3); }
foreach (['APP_ENV'=>'testing','DB_CONNECTION'=>'sqlite','DB_DATABASE'=>':memory:',
    'CACHE_STORE'=>'array','CACHE_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync',
    'SESSION_DRIVER'=>'array','MAIL_MAILER'=>'array','LOG_CHANNEL'=>'stderr'] as $key=>$value) {
    putenv("$key=$value"); $_ENV[$key]=$value; $_SERVER[$key]=$value;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['services.anthropic.extraction_model'=>'claude-haiku-4-5-20251001',
    'ai_credits.enabled'=>false,'document_intelligence.provider_gate.driver'=>'memory']);
$schema = Illuminate\Support\Facades\Schema::connection('sqlite');
$schema->create('documents', function ($t) {
    $t->string('id')->primary();
    foreach (['workspace_id','uploaded_by','name','type','status','classification','size_kb',
        'year','pages','file_hash','extracted_text','ai_pipeline','deleted_at','created_at','updated_at'] as $c) $t->text($c)->nullable();
});
$schema->create('document_chunks', function ($t) {
    $t->string('id')->primary();
    foreach (['workspace_id','document_id','pipeline_key','pipeline_version','prompt_version','identity',
        'stage','status','start_offset','end_offset','start_page','end_page','input_hash','attempts',
        'depth','parent_id','token_count','cost_accounting','reserved_cost','dispatched_at',
        'created_at','updated_at'] as $c) $t->text($c)->nullable();
});
$schema->create('document_source_spans', function ($t) {
    $t->string('id')->primary();
    $t->integer('ordinal');
    foreach (['workspace_id','document_id','extraction_version','span_key','page',
        'start_offset','end_offset','type','created_at'] as $c) $t->text($c)->nullable();
});
$schema->create('document_ai_runs', function ($t) {
    $t->string('id')->primary();
    foreach ((new App\Models\DocumentAiRun)->getFillable() as $c) $t->text($c)->nullable();
});
foreach (['document_evidence','processing_jobs','jobs','billing_operations','operation_quotes',
    'workspace_credits','audit_logs'] as $table) $schema->create($table, fn ($t)=>$t->string('id')->primary());
Illuminate\Support\Facades\Bus::fake(); Illuminate\Support\Facades\Queue::fake();
Illuminate\Support\Facades\Event::fake(); Illuminate\Support\Facades\Mail::fake();
Illuminate\Support\Facades\Notification::fake();
Illuminate\Support\Facades\Http::preventStrayRequests();
$db = Illuminate\Support\Facades\DB::connection();
$docId='01a11cf0-4071-737b-b92a-f4a55c1053d8';
$workspaceId=$rows[0]['workspace_id'];
$document=App\Models\Document::query()->forceCreate([
    'id'=>$docId,'workspace_id'=>$workspaceId,'uploaded_by'=>'00000000-0000-4000-8000-000000000104',
    'name'=>'UNICEF-Core-Resources-Annual-Report-2024.pdf','type'=>'PDF','status'=>'Processing',
    'classification'=>'Internal','pages'=>22,'extracted_text'=>$source,'file_hash'=>hash('sha256',$source),
]);
$version='8acd7d011fac21b31b65c5dfa251423d';
$grounding=app(App\Services\AI\Incremental\EvidenceGrounding::class);
if ($grounding->version($document)!==$version) { fwrite(STDERR,"extraction version mismatch\n"); exit(4); }
$document->forceFill(['ai_pipeline'=>['grounding'=>App\Services\AI\Incremental\EvidenceGrounding::SPANS,
    'extraction_version'=>$version,'key'=>'diagnostic']])->save();
foreach ($rows as $row) $db->table('document_source_spans')->insert($row);
$spans=$grounding->spans($document);
$planner=app(App\Services\AI\Incremental\ChunkPlanner::class);
$capacity=app(App\Services\AI\Incremental\ExtractionCapacity::class);
$tokens=$planner->estimate($source);
$routing=$capacity->decide($tokens,$capacity->density($source,'PDF'));
$plan=$planner->planFromSpans($spans,$source,$routing['partition_tokens'],true,1/3);
if (count($plan)!==2) { fwrite(STDERR,"plan changed\n"); exit(4); }
$expected=[[1,29519],[28597,58017]];
foreach ($plan as $i=>$planned) {
    if ([$planned['start_offset'],$planned['end_offset']]!==$expected[$i]) {
        fwrite(STDERR,"plan offsets changed\n"); exit(4);
    }
}
$item=$plan[$index]; $slice=mb_substr($source,$item['start_offset'],$item['end_offset']-$item['start_offset']);
$chunk=App\Models\DocumentChunk::query()->forceCreate([
    'id'=>sprintf('00000000-0000-4000-8000-%012d',$index+1),'workspace_id'=>$workspaceId,
    'document_id'=>$docId,'pipeline_key'=>'diagnostic','pipeline_version'=>'1','prompt_version'=>'2',
    'identity'=>'chunk:'.$index,'stage'=>'extraction','status'=>'running','start_offset'=>$item['start_offset'],
    'end_offset'=>$item['end_offset'],'start_page'=>$item['start_page'],'end_page'=>$item['end_page'],
    'input_hash'=>$item['input_hash'],'attempts'=>1,'depth'=>0,
]);
$tables=array_map(fn($r)=>$r->name,$db->select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"));
$counts=function() use ($db,$tables) { $o=[]; foreach($tables as $t) $o[$t]=$db->table($t)->count(); return $o; };
$before=$counts(); $body=null; $fakeCalls=0;
Illuminate\Support\Facades\Http::fake(function($request) use (&$body,&$fakeCalls) {
    $fakeCalls++; if ($request->url()!=='https://api.anthropic.com/v1/messages') throw new RuntimeException('network prohibited');
    $body=$request->body();
    return Illuminate\Support\Facades\Http::response(['id'=>'msg_diagnostic_fake',
        'model'=>'claude-haiku-4-5-20251001','stop_reason'=>'end_turn',
        'usage'=>['input_tokens'=>0,'output_tokens'=>0],
        'content'=>[['type'=>'text','text'=>'{"records":[]}']]],200,['request-id'=>'diagnostic-fake']);
});
$error=null; $db->beginTransaction();
try { app(App\Services\AnthropicClient::class)->extractChunk($document,$chunk,$slice); }
catch(Throwable $e) { $error=get_class($e).': '.$e->getMessage(); }
finally { $db->rollBack(); }
$after=$counts(); $sent=$body===null?null:json_decode($body,true,512,JSON_THROW_ON_ERROR);
$content=$sent['messages'][0]['content']??null;
$message=is_string($content)?json_decode($content,true,512,JSON_THROW_ON_ERROR):[];
$result=['chunk_index'=>$index,'request_sha256'=>$body===null?null:hash('sha256',$body),
    'request_bytes'=>$body===null?null:strlen($body),'payload_sha256'=>hash('sha256',$grounding->payload($document,$chunk,$slice)),
    'source_sha256'=>hash('sha256',$slice),'source_bytes'=>strlen($slice),
    'span_map_sha256'=>hash('sha256',json_encode($spans->forRange($item['start_offset'],$item['end_offset'])->all(),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),
    'model'=>$sent['model']??null,'max_tokens'=>$sent['max_tokens']??null,'max_records'=>$message['max_records']??null,
    'already_extracted_present'=>array_key_exists('already_extracted',$message),
    'sampling'=>array_intersect_key($sent??[],array_flip(['temperature','top_p','top_k','stop_sequences'])),
    'fake_http_requests'=>$fakeCalls,'anthropic_network_calls'=>0,'row_counts_identical'=>$before===$after,
    'queue_dispatches'=>0,'billing_events'=>0,'error'=>$error];
echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
if ($error!==null || $body===null || $fakeCalls!==1 || $before!==$after || $result['sampling']!==[]
    || $result['max_records']!==79 || $result['max_tokens']!==16000 || $result['already_extracted_present']) exit(1);
