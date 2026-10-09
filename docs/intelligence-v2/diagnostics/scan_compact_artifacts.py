"""Scan generated parity artifacts without printing credential values."""
import json
import os
import pathlib
import re

HERE = pathlib.Path(__file__).resolve().parent
FILES = [p for p in (HERE / 'compact-parity-study').rglob('*') if p.is_file()]
FILES += [HERE / 'compact-parity-prepared.json', HERE / 'compact-structured-output-schema-v1.json']
FILES += [HERE / 'compact-json-parity-acceptance.md', HERE / 'run_compact_parity.py',
          HERE / 'analyze_compact_parity.py', HERE / 'prepare_compact_precision.py',
          HERE / 'finalize_compact_parity.py', HERE / 'write_compact_parity_report.py']
secret_values = set()
for name, value in os.environ.items():
    if any(tag in name.upper() for tag in ('KEY', 'TOKEN', 'SECRET', 'PASSWORD', 'CREDENTIAL')) and len(value) >= 8:
        secret_values.add(value)
env_file = HERE.parents[2] / '.env'
if env_file.exists():
    for line in env_file.read_text().splitlines():
        if '=' not in line or line.lstrip().startswith('#'):
            continue
        name, value = line.split('=', 1)
        value = value.strip().strip('"\'')
        if any(tag in name.upper() for tag in ('KEY', 'TOKEN', 'SECRET', 'PASSWORD', 'CREDENTIAL')) and len(value) >= 8:
            secret_values.add(value)
patterns = [re.compile(rb'(?i)\bauthorization["\']?\s*:', re.I), re.compile(rb'(?i)\bbearer\s+[A-Za-z0-9._~+/=-]{8,}', re.I),
            re.compile(rb'sk-ant-[A-Za-z0-9_-]{12,}')]
findings = []
for file in FILES:
    data = file.read_bytes()
    if any(pattern.search(data) for pattern in patterns) or any(value.encode() in data for value in secret_values):
        findings.append(str(file.relative_to(HERE)))
result = {'files_scanned': len(FILES), 'secret_values_checked': len(secret_values), 'findings_count': len(findings),
          'finding_paths': findings, 'status': 'PASS' if not findings else 'HOLD_FOR_REDACTION'}
(HERE / 'compact-parity-study' / 'artifact-secret-scan.json').write_text(json.dumps(result, indent=2) + '\n')
print(json.dumps(result))
