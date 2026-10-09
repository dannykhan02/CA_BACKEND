<?php

// Source-only diagnostic candidate scan. Uses stored spans and current risk estimator.
$root = dirname(__DIR__, 3);
$source = file_get_contents($root.'/unicef-full-extracted.txt');
$raw = file_get_contents($root.'/unicef-source-spans.json');
if ($source === false || $raw === false || strlen($source) !== 58536
    || hash('sha256', $source) !== 'c919b4bb36fb3467906cc176a72d57c422260d6a17bbb4f4affc05c93415015c'
    || hash('sha256', $raw) !== 'df213788ebdc82a9fa98280cc47eed71c2f90862e027fe02b42fbd0882021b55') {
    fwrite(STDERR, "artifact gate failed\n"); exit(1);
}
$rows = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
if (count($rows) !== 360) { fwrite(STDERR, "span count gate failed\n"); exit(1); }
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$stored = array_map(fn($r)=>['ordinal'=>(int)$r['ordinal'],'key'=>$r['span_key'],
    'page'=>(int)$r['page'],'start_offset'=>(int)$r['start_offset'],
    'end_offset'=>(int)$r['end_offset'],'type'=>$r['type']],$rows);
$all = new App\Services\AI\Incremental\EvidenceSpanSet('8acd7d011fac21b31b65c5dfa251423d',$source,$stored);
$plan = json_decode(file_get_contents(__DIR__.'/unicef-current-plan-preflight.json'),true,512,JSON_THROW_ON_ERROR);
$root0=$plan['chunks'][0];
if ($root0['start_offset']!==1 || $root0['end_offset']!==29519
    || $root0['first_span']!=='E001' || $root0['last_span']!=='E186') {
    fwrite(STDERR,"root baseline changed\n"); exit(1);
}
$rootSpans=$all->forRange($root0['start_offset'],$root0['end_offset'])->all();
$risk=app(App\Services\AI\Incremental\ProactiveChunkRisk::class);
$weights=[];
foreach ($rootSpans as $span) {
    $spanText=mb_substr($source,$span['start_offset'],$span['end_offset']-$span['start_offset']);
    $weights[]=max(1,(int)ceil((strlen($spanText)+strlen($span['key'])+5)/3));
}
$candidates=[]; $eligible=[]; $fallback=[]; $totalScanned=0;
foreach ($rootSpans as $first=>$firstSpan) {
    $weight=0;
    for ($last=$first; $last<count($rootSpans); $last++) {
        $weight+=$weights[$last];
        $start=$firstSpan['start_offset']; $end=$rootSpans[$last]['end_offset'];
        $set=$all->forRange($start,$end);
        $slice=mb_substr($source,$start,$end-$start);
        $assessment=$risk->assess($slice,$weight,$set,'PDF');
        $candidate=['first_span'=>$firstSpan['key'],'last_span'=>$rootSpans[$last]['key'],
            'start_offset'=>$start,'end_offset'=>$end,'span_count'=>$last-$first+1,
            'chars'=>mb_strlen($slice),'bytes'=>strlen($slice),'input_tokens_estimate'=>$weight,
            'pressure'=>$assessment['expected_records'],'table_rows'=>$assessment['table_rows'],
            'list_items'=>$assessment['list_items'],'numeric_ratio'=>$assessment['numeric_ratio']];
        $totalScanned++;
        if ($first===0) $candidates[]=$candidate;
        if ($candidate['pressure']>=50 && $candidate['pressure']<=60) $eligible[]=$candidate;
        if ($candidate['pressure']<65) $fallback[]=$candidate;
    }
}
usort($eligible,fn($a,$b)=>$b['chars']<=>$a['chars'] ?: $a['start_offset']<=>$b['start_offset']);
usort($fallback,fn($a,$b)=>$b['pressure']<=>$a['pressure'] ?: $b['chars']<=>$a['chars']);
$chosen=$eligible[0]??$fallback[0]??null;
$out=['source_sha256'=>hash('sha256',$source),'stored_spans_sha256'=>hash('sha256',$raw),
    'root'=>['start_offset'=>$root0['start_offset'],'end_offset'=>$root0['end_offset'],
        'first_span'=>$root0['first_span'],'last_span'=>$root0['last_span']],
    'selection_rule'=>'largest contiguous root-0 span-aligned section with pressure 50..60 inclusive; otherwise pressure closest to 65 from below, then largest section',
    'scanned_count'=>$totalScanned,'eligible_count'=>count($eligible),
    'largest_eligible_candidates'=>array_slice($eligible,0,10),'selected'=>$chosen,'prefix_candidates'=>$candidates];
file_put_contents(__DIR__.'/unicef-safe-subchunk-scan.json',json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
echo json_encode(['selected'=>$chosen,'eligible_count'=>count($eligible),
    'scanned_count'=>$totalScanned,'largest_eligible_candidates'=>array_slice($eligible,0,5)],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
if ($chosen===null) exit(2);
