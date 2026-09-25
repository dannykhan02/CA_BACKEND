<?php

namespace App\Console\Commands;

use App\Models\DocumentKpi;
use App\Models\Workspace;
use App\Services\Kpis\KpiIdentityProfile;
use App\Services\Kpis\KpiIdentityResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackfillKpiIdentities extends Command
{
    protected $signature = 'kpis:backfill-identities {--workspace= : Required workspace UUID} {--limit=500 : Maximum unlinked observations to inspect} {--after-id=0 : Resume after this observation ID} {--apply : Persist high-confidence links; default is read-only preview}';

    protected $description = 'Conservatively link historical Ready KPIs without AI calls or label edits';

    public function handle(KpiIdentityResolver $resolver, KpiIdentityProfile $profiles): int
    {
        $workspaceId = $this->option('workspace');
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $afterId = filter_var($this->option('after-id'), FILTER_VALIDATE_INT);
        if (! is_string($workspaceId) || ! Str::isUuid($workspaceId) || ! Workspace::whereKey($workspaceId)->exists()
            || ! $limit || $limit < 1 || $limit > 5000 || $afterId === false || $afterId < 0) {
            $this->error('Provide an existing --workspace UUID, --limit between 1 and 5000, and a nonnegative --after-id.');

            return self::FAILURE;
        }
        $rows = DocumentKpi::where('workspace_id', $workspaceId)->whereNull('kpi_definition_id')
            ->where('id', '>', $afterId)
            ->whereHas('document', fn ($q) => $q->where('workspace_id', $workspaceId)->where('status', 'Ready'))
            ->orderBy('id')->limit($limit)->get();
        $duplicates = [];
        foreach ($rows as $row) {
            $p = $profiles->make($this->payload($row));
            if ($p['valid']) {
                $duplicates[$p['normalized_label'].'|'.$p['context_key']][$row->document_id] = true;
            }
        }
        $eligible = $unresolved = 0;
        foreach ($rows as $row) {
            $p = $profiles->make($this->payload($row));
            // A singleton vague historical label cannot establish a new identity.
            $allowCreate = $p['rule'] === 'charter' || count($duplicates[$p['normalized_label'].'|'.$p['context_key']] ?? []) >= 2;
            if (! $this->option('apply')) {
                $result = $resolver->resolve($workspaceId, $this->payload($row), $allowCreate, dryRun: true);
                $linked = $result['definition_id'] !== null || $result['method'] === 'would_create';
            } else {
                $linked = DB::transaction(function () use ($row, $workspaceId, $allowCreate, $resolver) {
                    $current = DocumentKpi::whereKey($row->id)->where('workspace_id', $workspaceId)
                        ->whereNull('kpi_definition_id')->lockForUpdate()->first();
                    if (! $current) {
                        return false;
                    }
                    $result = $resolver->resolve($workspaceId, $this->payload($current), $allowCreate);
                    if (! $result['definition_id']) {
                        return false;
                    }
                    $current->update(['kpi_definition_id' => $result['definition_id'], 'period' => $result['profile']['period']]);

                    return true;
                });
            }
            $linked ? $eligible++ : $unresolved++;
        }
        $this->info(($this->option('apply') ? 'Linked' : 'Eligible (preview)').": {$eligible}; unresolved/skipped: {$unresolved}; inspected: {$rows->count()}.");
        $this->line('Ambiguous observations remain unlinked. Preview does not simulate learned aliases; applying may additionally link later equivalent rows.');
        if ($rows->isNotEmpty()) {
            $this->line('Next batch: --after-id='.$rows->last()->id);
        }

        return self::SUCCESS;
    }

    private function payload(DocumentKpi $row): array
    {
        return ['label' => $row->label, 'unit' => $row->unit, 'value' => $row->value, 'identity' => $row->identity_metadata];
    }
}
