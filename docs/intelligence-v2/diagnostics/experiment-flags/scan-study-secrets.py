"""Scan and, if necessary, redact saved diagnostic artifacts without printing secrets."""
import json
import os
import pathlib
import re

BASE = pathlib.Path(__file__).resolve().parent
ROOT = BASE.parents[3]
PAID = BASE / 'paid-study'
key = os.environ.get('ANTHROPIC_API_KEY', '').strip()
if not key:
    env_file = ROOT / '.env'
    if env_file.exists():
        for line in env_file.read_text().splitlines():
            if line.lstrip().startswith('ANTHROPIC_API_KEY='):
                key = line.split('=', 1)[1].strip().strip('"\'')
                break
files = [p for p in PAID.rglob('*') if p.is_file()]
files += [ROOT / 'docs/intelligence-v2/diagnostics/collector-12call-results.json',
          ROOT / 'docs/intelligence-v2/diagnostics/collector-12call-results.md',
          BASE / 'runner-dry-run.json']
files = [p for p in files if p.exists() and p.name != 'key-hygiene.json']
replacements = 0
affected = []
patterns = [
    (re.compile(rb'(?i)("?x-api-key"?\s*[:=]\s*"?)([^"\s,}\[]+)'), rb'\1[REDACTED]'),
    (re.compile(rb'(?i)("?authorization"?\s*[:=]\s*"?)([^"\r\n,}\[]+)'), rb'\1[REDACTED]'),
    (re.compile(rb'sk-ant-[A-Za-z0-9_\-]+'), b'[REDACTED]'),
]
for path in files:
    original = path.read_bytes()
    updated = original
    if key and len(key) > 12:
        updated, count = re.subn(re.escape(key.encode()), b'[REDACTED]', updated)
        replacements += count
    for pattern, replacement in patterns:
        # Header names in natural-language comments are harmless; match only
        # values immediately following a header separator.
        updated, count = pattern.subn(replacement, updated)
        replacements += count
    if updated != original:
        path.write_bytes(updated)
        affected.append(str(path.relative_to(ROOT)))

remaining = 0
for path in files:
    data = path.read_bytes()
    if key and len(key) > 12:
        remaining += data.count(key.encode())
    remaining += len(re.findall(rb'sk-ant-[A-Za-z0-9_\-]+', data))
    for pattern, _ in patterns[:2]:
        remaining += len(pattern.findall(data))
result = {'files_scanned': len(files), 'secret_values_found_and_redacted': replacements,
          'redaction_occurred': replacements > 0, 'affected_artifacts': affected,
          'remaining_secret_value_matches': remaining, 'passed': remaining == 0,
          'actual_key_available_for_exact_scan': bool(key and len(key) > 12)}
(PAID / 'analysis' / 'key-hygiene.json').write_text(json.dumps(result, indent=2) + '\n')
print(json.dumps(result))
