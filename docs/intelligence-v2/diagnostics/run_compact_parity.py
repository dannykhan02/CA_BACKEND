"""Authorized compact parity execution. No retries, replacements, or request regeneration."""
import copy
import hashlib
import importlib.util
import json
import pathlib
import subprocess
import sys
import time
from datetime import datetime, timezone
from decimal import Decimal

HERE = pathlib.Path(__file__).resolve().parent
PRE = HERE / 'pre-b2'
FROZEN = PRE / 'final-study'
ROOT = HERE.parents[2]
OUT = HERE / 'compact-parity-study'
sys.path.insert(0, str(PRE))
from candidate_inventory import DETECTOR_VERSION, MATCHER_VERSION, split_spans
spec = importlib.util.spec_from_file_location('saved_transport', HERE / 'experiment-flags/collector-study-run.py')
saved = importlib.util.module_from_spec(spec)
spec.loader.exec_module(saved)
MANIFEST = json.loads((HERE / 'compact-parity-prepared.json').read_text())
FREEZE = json.loads((FROZEN / 'final-extraction-freeze.json').read_text())
COMPACT = json.loads((HERE / 'compact-structured-output-schema-v1.json').read_text())
LIMIT = Decimal('0.60')


def dump(x):
    return json.dumps(x, separators=(',', ':'), ensure_ascii=False)


def sha(x):
    return hashlib.sha256(x if isinstance(x, bytes) else x.encode()).hexdigest()


def write(name, value):
    path = OUT / name
    temp = path.with_suffix(path.suffix + '.tmp')
    temp.write_text(json.dumps(value, indent=2, ensure_ascii=False, default=str) + '\n')
    temp.replace(path)


def verify(step):
    chunk = step['chunk']
    if (MANIFEST['max_calls'] != 12 or Decimal(str(MANIFEST['max_total_cost_usd'])) != LIMIT
        or MANIFEST['model'] != FREEZE['model_id'] or MANIFEST['prompt_sha256'] != FREEZE['system_prompt_sha256']
        or sha(dump(COMPACT)) != MANIFEST['compact_schema_sha256']
        or DETECTOR_VERSION != FREEZE['candidate_inventory_detector_version']
        or MATCHER_VERSION != FREEZE['candidate_representation_matcher_version']):
        raise ValueError('manifest/model/schema/detector contract drift')
    planner_hash = sha((ROOT / 'app/Services/AI/Incremental/ExtractionCapacity.php').read_bytes()
                       + (ROOT / 'app/Services/AI/Incremental/ChunkPlanner.php').read_bytes())
    if planner_hash != FREEZE['planner_config_sha256']:
        raise ValueError('planner/config drift')
    source = (HERE / 'experiment-flags' / f'{chunk}.source.txt').read_text()
    frozen = FREEZE['control_request_contracts'][chunk]
    if sha(source) != frozen['source_sha256'] or sha(dump(split_spans(source))) != frozen['span_set_sha256']:
        raise ValueError('source/chunk/span drift')
    request = json.loads((FROZEN / f'{chunk}-A1.request.json').read_text())
    canonical_format = request['output_config']['format']
    php = ('$r=getcwd(); require $r."/vendor/autoload.php"; $a=require $r."/bootstrap/app.php"; '
           '$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); '
           'echo json_encode(["prompt"=>App\\Services\\AI\\Incremental\\EvidenceSchema::instructions("span_reference"),'
           '"canonical"=>App\\Services\\AI\\Incremental\\EvidenceSchema::extraction("span_reference"),'
           '"compact"=>App\\Services\\AI\\Incremental\\CompactEvidenceExpander::schema()]);')
    live = json.loads(subprocess.check_output(['php', '-r', php], cwd=ROOT, text=True, timeout=20))
    if live['prompt'] != request['system'][0]['text'] or live['canonical'] != canonical_format['schema'] or live['compact'] != COMPACT:
        raise ValueError('live production prompt/schema/config drift')
    if (sha(dump(canonical_format)) != FREEZE['schema_sha256']
        or sha(request['system'][0]['text']) != MANIFEST['prompt_sha256']
        or request['model'] != MANIFEST['model'] or request['max_tokens'] != 16000
        or request['output_config']['format']['type'] != 'json_schema'
        or any(k in request for k in ('temperature', 'top_p', 'top_k', 'stop_sequences'))
        or json.loads(request['messages'][0]['content'])['max_records'] != 79):
        raise ValueError('frozen Control request/config drift')
    if step['arm'] == 'B':
        if step['wire_format'] != 'compact-json-v1':
            raise ValueError('compact wire version drift')
        request['output_config']['format']['schema'] = COMPACT
    elif step['arm'] != 'A' or step['wire_format'] != 'canonical-json-span-v1':
        raise ValueError('canonical wire version drift')
    body = dump(request).encode()
    if sha(body) != step['request_sha256']:
        raise ValueError('request hash mismatch')
    if step['arm'] == 'A' and sha(body) != frozen['request_body_sha256']:
        raise ValueError('frozen canonical request hash drift')
    return body


def expand_compact(payload):
    code = ('$r=getcwd(); require $r."/vendor/autoload.php"; $a=require $r."/bootstrap/app.php"; '
            '$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); '
            '$x=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); '
            'echo json_encode(App\\Services\\AI\\Incremental\\CompactEvidenceExpander::expand($x),'
            'JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);')
    output = subprocess.check_output(['php', '-r', code], input=dump(payload).encode(), cwd=ROOT, timeout=30)
    return json.loads(output)


def finish(status, calls, spent, reason=None, cell=None):
    write('study-stop.json', {'status': status, 'reason': reason, 'cell': cell, 'calls_completed': len(calls),
                              'calls_failed': sum(c['failure_class'] is not None for c in calls),
                              'actual_total_cost_usd': str(spent), 'max_calls': 12, 'max_total_cost_usd': '0.60'})
    write('compact-parity-report.json', {'status': 'ANALYSIS_PENDING' if status == 'COMPLETE' else 'ABORTED',
         'stop_status': status, 'reason': reason, 'cell': cell, 'calls_completed': len(calls),
         'calls_failed': sum(c['failure_class'] is not None for c in calls), 'actual_total_cost_usd': str(spent),
         'schema_compilation_result': 'accepted' if any(c['arm'] == 'B' and c['http_status'] == 200 for c in calls) else 'unverified',
         'raw_artifacts_preserved': True, 'metrics_status': 'PENDING_OFFLINE_ANALYSIS'})
    print(dump({'status': status, 'calls': len(calls), 'spent_usd': str(spent), 'reason': reason, 'cell': cell}), flush=True)


def run():
    OUT.mkdir(parents=True, exist_ok=True)
    if any(OUT.iterdir()):
        raise RuntimeError('Study output directory is not empty; no automatic resume/retry')
    calls = []
    spent = Decimal('0')
    try:
        if len(MANIFEST['steps']) != 12 or [s['name'] for s in MANIFEST['steps']] != [
            f'{c}-{a}{n}' for n in range(1, 4) for c in ('unicef_reduced', 'india_wash') for a in 'AB']:
            raise ValueError('manifest order/count drift')
        for step in MANIFEST['steps']:
            verify(step)
        key = saved.load_api_key()
        if not key:
            raise ValueError('Anthropic credential unavailable')
        for step in MANIFEST['steps']:
            cell = step['name']
            body = verify(step)
            bound = saved.conservative_cost(len(body))
            budget = {'actual_spend_usd': str(spent), 'next_worst_case_usd': str(bound),
                      'remaining_usd': str(LIMIT - spent), 'ceiling_usd': str(LIMIT)}
            if spent + bound > LIMIT:
                finish('BUDGET_STOP', calls, spent, 'next call can exceed approved ceiling', cell)
                return
            (OUT / f'{cell}.request.json').write_bytes(body)
            started = datetime.now(timezone.utc).isoformat()
            clock = time.monotonic()
            status, request_id, raw, transport_error = saved.transport(body, key)
            if raw is not None:
                (OUT / f'{cell}.response.json').write_bytes(raw)
            response = None
            failure = None
            records = None
            expanded = None
            try:
                response = json.loads(raw) if raw is not None else None
                if status != 200:
                    failure = 'COMPACT_SCHEMA_400' if step['arm'] == 'B' and status == 400 else 'HTTP_FAILURE'
                elif not isinstance(response, dict) or response.get('model') != MANIFEST['model']:
                    failure = 'MODEL_OR_RESPONSE_DRIFT'
                else:
                    text = ''.join(block['text'] for block in response['content'] if block['type'] == 'text')
                    if response.get('stop_reason') == 'max_tokens':
                        failure = 'MAX_TOKENS_TRUNCATION'
                    elif response.get('stop_reason') != 'end_turn':
                        failure = 'STRUCTURED_OUTPUT_FAILURE'
                    else:
                        payload = json.loads(text)
                        if step['arm'] == 'B':
                            try:
                                expanded = expand_compact(payload)
                            except Exception:
                                failure = 'COMPACT_EXPANSION_FAILURE'
                        else:
                            expanded = payload
                        if failure is None:
                            records = expanded['records']
                            if not isinstance(records, list) or not all(isinstance(r, dict) for r in records):
                                failure = 'STRUCTURED_OUTPUT_FAILURE'
            except (ValueError, KeyError, TypeError) as exc:
                failure = 'COMPACT_PARSER_FAILURE' if step['arm'] == 'B' and status == 200 else 'PARSER_FAILURE'
            if transport_error and failure is None:
                failure = 'TRANSPORT_ERROR'
            usage = response.get('usage') if isinstance(response, dict) else None
            cost = saved.usage_cost(usage)
            if cost is None:
                failure = failure or 'MISSING_USAGE'
            else:
                spent += cost
            if expanded is not None:
                (OUT / f'{cell}.expanded.json').write_text(json.dumps(expanded, indent=2, ensure_ascii=False) + '\n')
            meta = {'cell': cell, 'chunk': step['chunk'], 'arm': step['arm'], 'wire_format': step['wire_format'],
                    'http_status': status, 'provider_request_id': request_id, 'started_at_utc': started,
                    'latency_ms': round((time.monotonic()-clock)*1000), 'transport_error': transport_error,
                    'failure_class': failure, 'stop_reason': response.get('stop_reason') if isinstance(response, dict) else None,
                    'response_model': response.get('model') if isinstance(response, dict) else None,
                    'request_sha256': sha(body), 'response_sha256': sha(raw) if raw is not None else None,
                    'expanded_sha256': sha(dump(expanded)) if expanded is not None else None,
                    'usage': usage, 'cost_usd': str(cost) if cost is not None else None,
                    'returned_records': len(records) if records is not None else None,
                    'budget_before_send': budget}
            write(f'{cell}.metadata.json', meta)
            calls.append(meta)
            write('ledger.json', {'actual_spend_usd': str(spent), 'completed_cells': [x['cell'] for x in calls],
                                  'calls_completed': len(calls), 'calls_failed': sum(x['failure_class'] is not None for x in calls)})
            print(dump({'cell': cell, 'failure': failure, 'cost_usd': meta['cost_usd'],
                        'spent_usd': str(spent), 'returned': meta['returned_records']}), flush=True)
            if failure and failure != 'MAX_TOKENS_TRUNCATION':
                finish('PROTOCOL_ABORT', calls, spent, failure, cell)
                return
            if spent > LIMIT:
                finish('BUDGET_ABORT', calls, spent, 'actual cost exceeded ceiling', cell)
                return
        finish('COMPLETE', calls, spent)
    except Exception as exc:
        finish('PREFLIGHT_OR_RUN_ABORT', calls, spent, f'{type(exc).__name__}: {exc}')


if __name__ == '__main__':
    if sys.argv[1:] != ['--execute']:
        raise SystemExit('Requires separately authorized --execute')
    run()
