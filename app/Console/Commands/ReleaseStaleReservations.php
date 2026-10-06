<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\EntitlementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reservations are normally released lazily when a workspace's credits are read. This sweep applies the
 * same release rules to workspaces nobody is looking at: expired (billing.pipeline_hours), failed,
 * Needs Review and removed documents. It never settles or debits anything the lazy path would not.
 */
class ReleaseStaleReservations extends Command
{
    protected $signature = 'billing:release-stale-reservations';

    protected $description = 'Release expired, failed and orphaned credit reservations for every workspace that holds one';

    public function handle(EntitlementService $entitlements): int
    {
        $cutoff = now()->subHours((int) config('billing.pipeline_hours'));
        $released = 0;
        DB::table('billing_operations')->where('status', 'reserved')->whereIn('kind', ['document', 'ocr', 'reanalysis'])
            ->where(function ($q) use ($cutoff) {
                $q->where('updated_at', '<=', $cutoff)
                    ->orWhereNotIn('resource_id', Document::select('id'))
                    ->orWhereIn('resource_id', Document::whereIn('status', ['Failed', 'Needs Review'])->select('id'));
            })->distinct()->orderBy('workspace_id')->pluck('workspace_id')->each(function ($workspaceId) use ($entitlements, &$released) {
                $entitlements->releaseStaleReservations($workspaceId);
                $released++;
            });
        $this->info("Swept {$released} workspace(s) with stale reservations.");

        return self::SUCCESS;
    }
}
