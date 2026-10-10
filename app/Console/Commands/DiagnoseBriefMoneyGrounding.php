<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\Intelligence\B2\StageASnapshot;
use App\Services\Intelligence\Brief\BriefAssembler;
use App\Services\Intelligence\Brief\BriefVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * How widely the currency-grounding defect is degrading served Briefs.
 *
 * Strictly read-only. It makes no write, dispatches no job, calls no provider and mutates no cache:
 * it asks the real services - StageASnapshot, BriefAssembler, BriefVerifier - the same questions the
 * API read path asks, so the answer is the application's own semantics rather than a second,
 * SQL-shaped guess at money eligibility. Money eligibility is never restated here; it is
 * `BriefVerifier::groundableValue()`, the one the verifier itself uses.
 *
 * The one predicate this command does restate is the *old* rule, so it can attribute an exclusion to
 * this specific defect: before the fix a `money` value needed its ISO code in `currency`, and a
 * record that wrote the symbol there was unusable however clearly its `unit` named the currency.
 *
 * Run it against a read replica or a restored snapshot by preference. Never against production
 * without authorization, even though it cannot write: it reads every document in scope and that is
 * load. Where the driver supports it the work happens inside a read-only transaction, so a write
 * introduced here by a later change fails loudly instead of landing.
 *
 *   php artisan docintel:diagnose-money-grounding --limit=200
 *   php artisan docintel:diagnose-money-grounding --workspace=<id> --json
 *   php artisan docintel:diagnose-money-grounding --json --per-document > findings.json
 *
 * Output is metadata only: ids, counts and currency codes. Never a label, a quote or a figure.
 */
class DiagnoseBriefMoneyGrounding extends Command
{
    protected $signature = 'docintel:diagnose-money-grounding
        {--workspace= : Restrict to one workspace id}
        {--limit=0 : Stop after this many documents (0 = no limit)}
        {--per-document : Include a per-document breakdown (ids and counts only)}
        {--json : Machine-readable output}';

    protected $description = 'Read-only: how many served Briefs lose monetary blocks to the currency-grounding defect';

    public function handle(StageASnapshot $snapshots, BriefAssembler $assembler,
        BriefVerifier $verifier): int
    {
        // `set transaction read only` is only legal as the first statement of a transaction, so it
        // is applied when this command owns the transaction and skipped when it is already inside
        // somebody else's (a test's RefreshDatabase wrapper, for instance). Either way the work is
        // the same reads; the flag reports which guard was actually in force.
        $guarded = DB::transactionLevel() === 0 && DB::connection()->getDriverName() === 'pgsql';
        $inspect = fn (): array => $this->inspect($snapshots, $assembler, $verifier);

        if (! $guarded) {
            $report = $inspect();
        } else {
            $report = DB::transaction(function () use ($inspect): array {
                DB::statement('set transaction read only');

                return $inspect();
            });
        }
        $report['read_only_transaction'] = $guarded;

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        foreach ($report['totals'] as $key => $value) {
            $this->line(sprintf('%-46s %s', str_replace('_', ' ', $key), $value));
        }
        if ($report['by_currency'] !== []) {
            $this->newLine();
            $this->line('Resolved currency of the records this defect excluded:');
            foreach ($report['by_currency'] as $code => $count) {
                $this->line(sprintf('  %-6s %d', $code, $count));
            }
        }
        if ($this->option('per-document')) {
            $this->newLine();
            $this->table(['document', 'workspace', 'money records', 'excluded by defect',
                'figure blocks'], array_map(static fn (array $row) => array_values($row),
                    $report['documents']));
        }

        return self::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function inspect(StageASnapshot $snapshots, BriefAssembler $assembler,
        BriefVerifier $verifier): array
    {
        $asOf = StageASnapshot::today();
        $query = Document::query()->whereNotNull('ai_pipeline')->orderBy('id');
        if (is_string($workspace = $this->option('workspace')) && $workspace !== '') {
            $query->where('workspace_id', $workspace);
        }
        $limit = (int) $this->option('limit');

        $totals = ['documents_inspected' => 0, 'documents_with_evidence' => 0,
            'documents_with_no_figure_bearing_block' => 0, 'monetary_records' => 0,
            'monetary_records_excluded_by_currency_defect' => 0,
            'monetary_records_eligible_under_fixed_code' => 0,
            'monetary_records_ineligible_for_other_reasons' => 0,
            'documents_affected_by_currency_defect' => 0,
            'documents_whose_figures_return_after_the_fix' => 0];
        $byCurrency = [];
        $documents = [];

        $query->chunkById(50, function ($chunk) use (&$totals, &$byCurrency, &$documents, $asOf,
            $snapshots, $assembler, $verifier, $limit): bool {
            foreach ($chunk as $document) {
                if ($limit > 0 && $totals['documents_inspected'] >= $limit) {
                    return false;
                }
                $totals['documents_inspected']++;

                $snapshot = $snapshots->build($document, $asOf);
                if ($snapshot['records'] === []) {
                    continue;
                }
                $totals['documents_with_evidence']++;

                $brief = $assembler->assemble($snapshot['document_name'], $snapshot['document_type'],
                    $snapshot['records'], $snapshot['assignments'], $snapshot['coverage'], $asOf,
                    $snapshot['forced_overflow']);
                $figures = count(array_filter($brief['blocks'], static fn (array $block): bool
                    => is_numeric($block['typed']['value']['number'] ?? null)));
                if ($figures === 0) {
                    $totals['documents_with_no_figure_bearing_block']++;
                }

                $money = 0;
                $excluded = 0;
                $eligible = 0;
                foreach ($snapshot['records'] as $record) {
                    $value = $record['typed']['value'] ?? null;
                    if (! is_array($value) || ($value['type'] ?? null) !== 'money') {
                        continue;
                    }
                    $money++;
                    $totals['monetary_records']++;

                    $groundable = $verifier->groundableValue($value);
                    $groundable ? $eligible++ : null;
                    $totals[$groundable ? 'monetary_records_eligible_under_fixed_code'
                        : 'monetary_records_ineligible_for_other_reasons']++;

                    // The old rule, restated only to attribute the exclusion: an ISO code had to be
                    // in `currency` itself. A record the fixed code can ground but the old rule
                    // could not is a record this defect was silently dropping.
                    $code = $value['currency'] ?? null;
                    $wasExcluded = ! (is_string($code) && preg_match('/^[A-Z]{3}$/D', $code) === 1);
                    if ($groundable && $wasExcluded) {
                        $excluded++;
                        $totals['monetary_records_excluded_by_currency_defect']++;
                        $resolved = $verifier->recordCurrencyCode($value) ?? 'unresolved';
                        $byCurrency[$resolved] = ($byCurrency[$resolved] ?? 0) + 1;
                    }
                }

                if ($excluded > 0) {
                    $totals['documents_affected_by_currency_defect']++;
                    if ($figures === 0) {
                        $totals['documents_whose_figures_return_after_the_fix']++;
                    }
                }
                if ($this->option('per-document') && $money > 0) {
                    $documents[] = ['document_id' => $document->id,
                        'workspace_id' => $document->workspace_id, 'monetary_records' => $money,
                        'excluded_by_currency_defect' => $excluded, 'figure_bearing_blocks' => $figures];
                }
            }

            return true;
        });

        ksort($byCurrency);

        return ['generated_at' => $asOf->format(\DateTimeInterface::ATOM),
            'writes' => 'none', 'totals' => $totals, 'by_currency' => $byCurrency,
            'documents' => $documents];
    }
}
