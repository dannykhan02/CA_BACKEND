<?php
// Offline deterministic downstream replay of one saved paid-study response. No provider calls.
$cell = $argv[1] ?? '';
if (! preg_match('/^(unicef_reduced|india_wash)-[CK][123]$/', $cell)) {
    fwrite(STDERR, "Expected study cell, e.g. unicef_reduced-C1\n"); exit(2);
}
$base = __DIR__; $root = dirname(__DIR__, 4); $paid = $base.'/paid-study';
$metaFile = $paid.'/'.$cell.'.metadata.json'; $rawFile = $paid.'/'.$cell.'.response.json';
if (! is_file($metaFile) || ! is_file($rawFile)) { fwrite(STDERR, "Missing successful call artifacts\n"); exit(3); }
$meta = json_decode(file_get_contents($metaFile), true, 512, JSON_THROW_ON_ERROR);
if (($meta['status'] ?? null) !== 'success') { fwrite(STDERR, "Cell is not successful\n"); exit(3); }
$raw = file_get_contents($rawFile);
if (hash('sha256', $raw) !== $meta['response_sha256']) { fwrite(STDERR, "Response hash changed\n"); exit(3); }
$study = str_starts_with($cell, 'india_') ? 'india_wash' : 'unicef_reduced';
$manifest = json_decode(file_get_contents($base.'/study-chunks.json'), true, 512, JSON_THROW_ON_ERROR)[$study];
$source = file_get_contents($root.'/unicef-full-extracted.txt');
$stored = json_decode(file_get_contents($root.'/unicef-source-spans.json'), true, 512, JSON_THROW_ON_ERROR);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$provider = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
$decoded = (new ReflectionMethod(app(App\Services\AnthropicClient::class), 'decodeJsonContent'))
    ->invoke(app(App\Services\AnthropicClient::class), $provider);
$map = array_map(fn ($r) => ['ordinal'=>(int)$r['ordinal'], 'key'=>$r['span_key'], 'page'=>(int)$r['page'],
    'start_offset'=>(int)$r['start_offset'], 'end_offset'=>(int)$r['end_offset'], 'type'=>$r['type']], $stored);
$version = '8acd7d011fac21b31b65c5dfa251423d';
$spans = (new App\Services\AI\Incremental\EvidenceSpanSet($version, $source, $map))
    ->forRange($manifest['start_offset'], $manifest['end_offset']);
$slice = mb_substr($source, $manifest['start_offset'], $manifest['end_offset']-$manifest['start_offset']);
$all = App\Services\AI\Incremental\EvidenceSchema::validate($decoded, $slice, $spans, $version);
$merger = app(App\Services\AI\Incremental\EvidenceMerger::class);
$projector = app(App\Services\Intelligence\Values\TypedEvidenceProjector::class);
$accepted = []; $trace = []; $identities = []; $origin = ['document'=>0, 'unknown'=>0]; $eligible = 0;
foreach ($decoded['records'] as $index => $record) {
    try {
        $one = App\Services\AI\Incremental\EvidenceSchema::validate(['records'=>[$record]], $slice, $spans, $version);
        if (! isset($one['records'][0])) {
            $trace[] = ['raw_index'=>$index, 'accepted'=>false, 'rejection'=>$one['_validation']];
            continue;
        }
        $item = $one['records'][0];
        $identity = $merger->identity($item);
        $sources = array_map(fn ($e) => ['quote'=>$e['text'], 'span_id'=>$e['span_id'],
            'extraction_version'=>$version, 'page'=>$e['page'], 'start_offset'=>$e['start_offset'],
            'end_offset'=>$e['end_offset']], $item['evidence'] ?? []);
        $row = new App\Models\DocumentEvidence;
        $row->forceFill(['kind'=>$item['kind'], 'data'=>$item, 'sources'=>$sources,
            'identity'=>$identity, 'source_id'=>$item['evidence_ids'][0] ?? null]);
        $projected = $projector->project($row, []);
        $provenance = $projected['provenance']['origin'] ?? 'unknown';
        $origin[$provenance] = ($origin[$provenance] ?? 0) + 1;
        $typed = $projected['typed']['value'] ?? null;
        $keyEligible = $item['kind']==='metric' && $provenance==='document'
            && ($typed['type'] ?? null)==='money' && ($typed['unit_kind'] ?? null)==='currency'
            && is_string($typed['currency'] ?? null) && $typed['currency']!==''
            && is_numeric($typed['number'] ?? null) && is_finite((float)$typed['number'])
            && is_string($row->source_id);
        if ($keyEligible) $eligible++;
        $accepted[] = $item;
        $trace[] = ['raw_index'=>$index, 'accepted'=>true, 'identity'=>$identity,
            'premerge_collision'=>isset($identities[$identity]), 'origin'=>$provenance,
            'key_figure_eligible'=>$keyEligible, 'typed'=>$projected['typed']];
        $identities[$identity] = true;
    } catch (App\Exceptions\AiProcessingException $e) {
        $trace[] = ['raw_index'=>$index, 'accepted'=>false, 'rejection_class'=>$e->classification,
            'rejection_reason'=>$e->diagnostics['rejection_reasons'] ?? $e->diagnostics['reason'] ?? null];
    }
}
if (count($accepted) !== count($all['records'])) { fwrite(STDERR, "Aggregate/single validation mismatch\n"); exit(4); }
$out = ['cell'=>$cell, 'response_sha256'=>hash('sha256',$raw),
    'returned_count'=>count($decoded['records']), 'accepted_count'=>count($accepted),
    'rejected_count'=>count($decoded['records'])-count($accepted),
    'validation'=>$all['_validation'], 'origin'=>$origin, 'key_figure_eligible_count'=>$eligible,
    'premerge_identity_count'=>count($identities), 'trace'=>$trace];
$dir = $paid.'/analysis'; if (! is_dir($dir)) mkdir($dir, 0770, true);
file_put_contents($dir.'/'.$cell.'.accepted.json', json_encode($accepted, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n", LOCK_EX);
file_put_contents($dir.'/'.$cell.'.replay.json', json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n", LOCK_EX);
echo json_encode(['cell'=>$cell,'returned'=>$out['returned_count'],'accepted'=>$out['accepted_count'],
    'rejected'=>$out['rejected_count'],'origin'=>$origin,'eligible'=>$eligible],JSON_THROW_ON_ERROR)."\n";
