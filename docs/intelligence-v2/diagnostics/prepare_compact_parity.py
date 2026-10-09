"""Offline preparation only. Never sends a provider generation request."""
import copy
import hashlib
import json
from pathlib import Path

HERE = Path(__file__).resolve().parent
FROZEN = HERE / 'pre-b2/final-study'
COMPACT = json.loads((HERE / 'compact-structured-output-schema-v1.json').read_text())
FREEZE = json.loads((FROZEN / 'final-extraction-freeze.json').read_text())
MODEL = 'claude-haiku-4-5-20251001'
PROMPT_HASH = FREEZE['system_prompt_sha256']


def encoded(x):
    return json.dumps(x, separators=(',', ':'), ensure_ascii=False).encode()


def sha(x):
    return hashlib.sha256(x).hexdigest()


steps = []
for repeat in range(1, 4):
    for chunk in ('unicef_reduced', 'india_wash'):
        canonical = json.loads((FROZEN / f'{chunk}-A1.request.json').read_text())
        assert canonical['model'] == MODEL and canonical['max_tokens'] == 16000
        assert sha(canonical['system'][0]['text'].encode()) == PROMPT_HASH
        assert sha(encoded(canonical['output_config']['format'])) == FREEZE['schema_sha256']
        for arm in ('A', 'B'):
            request = copy.deepcopy(canonical)
            if arm == 'B':
                request['output_config']['format']['schema'] = COMPACT
            name = f'{chunk}-{arm}{repeat}'
            steps.append({'name': name, 'chunk': chunk, 'arm': arm,
                          'wire_format': 'canonical-json-span-v1' if arm == 'A' else 'compact-json-v1',
                          'request_sha256': sha(encoded(request))})
assert len(steps) == 12
manifest = {'status': 'PREPARED_NOT_AUTHORIZED', 'max_calls': 12, 'max_total_cost_usd': 0.60,
            'model': MODEL, 'prompt_sha256': PROMPT_HASH,
            'compact_schema_sha256': sha(encoded(COMPACT)), 'steps': steps}
(HERE / 'compact-parity-prepared.json').write_text(json.dumps(manifest, indent=2, ensure_ascii=False) + '\n')
print('prepared', len(steps), 'requests; no generation calls')
