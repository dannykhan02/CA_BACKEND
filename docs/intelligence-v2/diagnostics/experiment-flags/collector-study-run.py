"""Guarded future paid runner. Never sends without --execute and ANTHROPIC_API_KEY.

No provider calls are made by importing this module or running without --execute.
One frozen body is posted exactly once per cell. No retries or continuations.
"""
import hashlib
import json
import os
import pathlib
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone
from decimal import Decimal

BASE = pathlib.Path(__file__).resolve().parent
OUTPUT = BASE / 'paid-study'
DRY_RUN_PATH = BASE / 'runner-dry-run.json'
LIMIT = Decimal('1.50')
PRICE_INPUT = Decimal('1')
PRICE_OUTPUT = Decimal('5')
PRICE_CACHE_WRITE = Decimal('1.25')
PRICE_CACHE_READ = Decimal('0.10')
FROZEN = {
    ('unicef_reduced', 'CONTROL'): 'd304bc2997f958dc6784390c62266758571ad87fcca391a1fc9c68fbc4d296fe',
    ('unicef_reduced', 'COLLECTOR_ONLY'): 'e7e913c49784741f3a966ce6eea45cdbc18d29655f5b38941e015ee747990ae3',
    ('india_wash', 'CONTROL'): 'f3f0f6f7313153c1e8693d2fe5ad3470d46425ec43af8c0277512af40bcc7669',
    ('india_wash', 'COLLECTOR_ONLY'): '12d55bc7b35a631fef9305eb20a46a07e3b9075675e9131dd44edc9860a4feeb',
}
ORDER = [(chunk, variant, run) for run in (1, 2, 3)
         for chunk in ('unicef_reduced', 'india_wash')
         for variant in ('CONTROL', 'COLLECTOR_ONLY')]


def sha(data):
    return hashlib.sha256(data).hexdigest()


def conservative_cost(request_bytes):
    # Frozen pre-registration: 1.25 tokens per byte, all at cache-write price,
    # full 16,000 output tokens at output price.
    size = Decimal(request_bytes)
    return (size * Decimal('1.25') * PRICE_CACHE_WRITE
            + Decimal(16000) * PRICE_OUTPUT) / Decimal(1000000)


def usage_cost(usage):
    fields = ('input_tokens', 'output_tokens', 'cache_creation_input_tokens',
              'cache_read_input_tokens')
    if not isinstance(usage, dict) or any(not isinstance(usage.get(k, 0), int)
                                          or usage.get(k, 0) < 0 for k in fields):
        return None
    if 'input_tokens' not in usage or 'output_tokens' not in usage:
        return None
    return (Decimal(usage['input_tokens']) * PRICE_INPUT
            + Decimal(usage['output_tokens']) * PRICE_OUTPUT
            + Decimal(usage.get('cache_creation_input_tokens', 0)) * PRICE_CACHE_WRITE
            + Decimal(usage.get('cache_read_input_tokens', 0)) * PRICE_CACHE_READ) / Decimal(1000000)


def gate_request(chunk, variant, base=BASE):
    path = base / 'requests' / f'{chunk}-A-{variant}.json'
    try:
        body = path.read_bytes()
    except FileNotFoundError:
        return None, {'status': 'REQUEST_DRIFT', 'chunk': chunk, 'variant': variant,
                      'expected_sha256': FROZEN[(chunk, variant)],
                      'actual_sha256': None, 'reason': 'frozen request missing'}
    actual = sha(body)
    expected = FROZEN[(chunk, variant)]
    if actual != expected:
        return None, {'status': 'REQUEST_DRIFT', 'chunk': chunk, 'variant': variant,
                      'expected_sha256': expected, 'actual_sha256': actual}
    try:
        decoded = json.loads(body)
    except ValueError:
        return None, {'status': 'REQUEST_DRIFT', 'chunk': chunk, 'variant': variant,
                      'expected_sha256': expected, 'actual_sha256': actual,
                      'reason': 'frozen request is invalid JSON'}
    if decoded.get('model') != 'claude-haiku-4-5-20251001' or decoded.get('max_tokens') != 16000:
        return None, {'status': 'REQUEST_DRIFT', 'chunk': chunk, 'variant': variant,
                      'expected_sha256': expected, 'actual_sha256': actual,
                      'reason': 'model or max_tokens differs'}
    return body, None


def write_json(path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    temp = path.with_suffix(path.suffix + '.tmp')
    temp.write_text(json.dumps(value, indent=2, default=str) + '\n')
    temp.replace(path)


def transport(body, api_key):
    request = urllib.request.Request('https://api.anthropic.com/v1/messages', data=body,
        headers={'x-api-key': api_key, 'anthropic-version': '2023-06-01',
                 'content-type': 'application/json'}, method='POST')
    try:
        with urllib.request.urlopen(request, timeout=180) as response:
            return response.status, response.headers.get('request-id'), response.read(), None
    except urllib.error.HTTPError as error:
        return error.code, error.headers.get('request-id'), error.read(), str(error)
    except Exception as error:
        return None, None, None, f'{type(error).__name__}: {error}'


def dry_run():
    """Construct every frozen outgoing request and headers without opening a socket."""
    php_client = (BASE.parents[3] / 'app/Services/AnthropicClient.php').read_text()
    header_source = php_client[php_client.index("$request = Http::withHeaders([", php_client.index('private function callWithRetry')):]
    header_source = header_source[:header_source.index('])')]
    for required in ("'x-api-key' => $this->apiKey()", "'anthropic-version' => '2023-06-01'",
                     "'content-type' => 'application/json'"):
        if required not in header_source:
            raise RuntimeError('real AnthropicClient extraction header contract changed')
    if 'anthropic-beta' in header_source:
        raise RuntimeError('real AnthropicClient now requires a beta header')
    expected = {'x-api-key': '<redacted>', 'anthropic-version': '2023-06-01',
                'content-type': 'application/json'}
    cells = []
    projected_total = Decimal('0')
    for chunk, variant, run_number in ORDER:
        body, drift = gate_request(chunk, variant)
        if drift:
            raise RuntimeError(json.dumps(drift))
        # urllib.Request is the exact request type used by transport; do not urlopen.
        outgoing = urllib.request.Request('https://api.anthropic.com/v1/messages', data=body,
            headers={'x-api-key': 'DRY_RUN_DUMMY_KEY', 'anthropic-version': '2023-06-01',
                     'content-type': 'application/json'}, method='POST')
        headers = {key.lower(): ('<redacted>' if key.lower() == 'x-api-key' else value)
                   for key, value in outgoing.header_items()}
        if headers != expected or outgoing.get_method() != 'POST':
            raise RuntimeError('outgoing headers differ from real AnthropicClient path')
        projected_total += conservative_cost(len(body))
        cells.append({'cell': f'{chunk}-{"C" if variant == "CONTROL" else "K"}{run_number}',
                      'request_sha256': sha(body), 'request_bytes': len(body),
                      'headers': headers, 'method': 'POST', 'network_calls': 0})
    if projected_total > LIMIT:
        raise RuntimeError('BUDGET_STOP: conservative full-study estimate exceeds USD 1.50')
    result = {'status': 'PASS', 'network_calls': 0, 'headers_match_anthropic_client': True,
              'beta_headers_required': False, 'api_key_values_persisted': False,
              'conservative_total_usd': str(projected_total), 'cells': cells}
    write_json(DRY_RUN_PATH, result)
    return result


def load_api_key():
    """Read the existing local credential without writing or printing it."""
    value = os.environ.get('ANTHROPIC_API_KEY')
    if value:
        return value
    env_file = BASE.parents[3] / '.env'
    if not env_file.exists():
        return None
    for line in env_file.read_text().splitlines():
        if line.lstrip().startswith('ANTHROPIC_API_KEY='):
            value = line.split('=', 1)[1].strip()
            if len(value) >= 2 and value[0] == value[-1] and value[0] in ('"', "'"):
                value = value[1:-1]
            return value or None
    return None


def run(base=BASE, output=OUTPUT, post=transport, api_key=None):
    """post is injectable only for offline tests; the CLI uses transport exactly once per cell."""
    if not api_key:
        raise RuntimeError('ANTHROPIC_API_KEY unavailable; no call sent')
    output.mkdir(parents=True, exist_ok=True)
    if (output / 'study-stop.json').exists():
        return json.loads((output / 'study-stop.json').read_text())
    actual_total = Decimal('0')
    unknown_reserved = Decimal('0')
    failures = 0
    completed = []
    for chunk, variant, run_number in ORDER:
        cell = f'{chunk}-{"C" if variant == "CONTROL" else "K"}{run_number}'
        meta_path = output / f'{cell}.metadata.json'
        request_path = output / f'{cell}.request.json'
        response_path = output / f'{cell}.response.json'
        if meta_path.exists():
            metadata = json.loads(meta_path.read_text())
            if metadata.get('request_sha256') != FROZEN[(chunk, variant)]:
                stop = {'status': 'REQUEST_DRIFT', 'cell': cell,
                        'expected_sha256': FROZEN[(chunk, variant)],
                        'actual_sha256': metadata.get('request_sha256')}
                write_json(output / 'study-stop.json', stop)
                return stop
            if metadata['status'] == 'failed':
                failures += 1
            actual_total += Decimal(str(metadata.get('actual_cost_usd') or '0'))
            unknown_reserved += Decimal(str(metadata.get('unknown_cost_reserve_usd') or '0'))
            completed.append(cell)
            if metadata.get('http_status') not in (None, 200) or failures >= 2:
                stop = {'status': 'STUDY_INCOMPLETE', 'cell': cell,
                        'reason': 'prior non-200 HTTP or two failed calls'}
                write_json(output / 'study-stop.json', stop)
                return stop
            continue
        if request_path.exists() or response_path.exists():
            stop = {'status': 'STUDY_INCOMPLETE', 'cell': cell,
                    'reason': 'partial artifact; sending status unknown; no retry'}
            write_json(output / 'study-stop.json', stop)
            return stop
        body, drift = gate_request(chunk, variant, base)
        if drift:
            drift['cell'] = cell
            write_json(output / 'study-stop.json', drift)
            return drift
        next_bound = conservative_cost(len(body))
        if actual_total + unknown_reserved + next_bound > LIMIT:
            stop = {'status': 'BUDGET_STOP', 'cell': cell, 'actual_cost_usd': str(actual_total),
                    'unknown_cost_reserve_usd': str(unknown_reserved),
                    'next_conservative_usd': str(next_bound), 'limit_usd': str(LIMIT)}
            write_json(output / 'study-stop.json', stop)
            return stop
        # This exact serialized and verified byte string is the transport body.
        request_path.write_bytes(body)
        start = datetime.now(timezone.utc).isoformat()
        clock = time.monotonic()
        status, provider_id, raw, error = post(body, api_key)
        latency_ms = round((time.monotonic() - clock) * 1000)
        if raw is not None:
            response_path.write_bytes(raw)
        parsed = None
        try:
            parsed = json.loads(raw) if raw is not None else None
        except (ValueError, TypeError):
            error = error or 'invalid response JSON'
        usage = parsed.get('usage') if isinstance(parsed, dict) else None
        provider_id = provider_id or (parsed.get('id') if isinstance(parsed, dict) else None)
        cost = usage_cost(usage)
        success = (status == 200 and isinstance(parsed, dict) and not error
                   and cost is not None and isinstance(parsed.get('stop_reason'), str)
                   and isinstance(provider_id, str) and bool(provider_id))
        if status == 200 and not success and error is None:
            error = 'incomplete provider response'
        if not success:
            failures += 1
        metadata = {'cell': cell, 'chunk': chunk, 'variant': variant, 'run': run_number,
            'status': 'success' if success else 'failed', 'http_status': status,
            'error': error, 'request_sha256': sha(body), 'response_sha256': sha(raw) if raw is not None else None,
            'provider_request_id': provider_id, 'started_at_utc': start, 'latency_ms': latency_ms,
            'stop_reason': parsed.get('stop_reason') if isinstance(parsed, dict) else None,
            'usage': usage, 'actual_cost_usd': str(cost) if cost is not None else None,
            'unknown_cost_reserve_usd': str(next_bound) if cost is None else None}
        write_json(meta_path, metadata)
        actual_total += cost or Decimal('0')
        unknown_reserved += next_bound if cost is None else Decimal('0')
        completed.append(cell)
        write_json(output / 'ledger.json', {'actual_cost_usd': str(actual_total),
            'unknown_cost_reserve_usd': str(unknown_reserved), 'completed_cells': completed,
            'failed_cells': failures, 'limit_usd': str(LIMIT)})
        if status not in (None, 200) or failures >= 2:
            stop = {'status': 'STUDY_INCOMPLETE', 'cell': cell,
                    'reason': 'non-200 HTTP' if status not in (None, 200) else 'two failed calls'}
            write_json(output / 'study-stop.json', stop)
            return stop
    return {'status': 'STUDY_COMPLETE_WITH_MISSING_CELL' if failures else 'COMPLETE',
            'actual_cost_usd': str(actual_total),
            'unknown_cost_reserve_usd': str(unknown_reserved),
            'failed_cells': failures, 'completed_cells': completed}


if __name__ == '__main__':
    if sys.argv[1:] == ['--dry-run']:
        print(json.dumps(dry_run(), indent=2))
    elif sys.argv[1:] == ['--execute']:
        print(json.dumps(run(api_key=load_api_key()), indent=2))
    else:
        raise SystemExit('No calls sent. Use --dry-run or an authorized --execute.')
