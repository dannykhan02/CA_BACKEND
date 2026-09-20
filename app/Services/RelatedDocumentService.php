<?php

namespace App\Services;

use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RelatedDocumentService
{
    public function __construct(private IntelligenceAccess $access) {}

    public function suggestions(User $user, Document $document)
    {
        $signals = $document->entities()->whereIn('entity_type', ['contract', 'reference', 'organization'])
            ->whereNotNull('normalized_value')->where('normalized_value', '!=', '')->limit(50)->get();
        if ($signals->isEmpty()) {
            return collect();
        }
        $dismissed = DB::table('document_suggestion_dismissals')->where('user_id', $user->id)->where('document_id', $document->id)->select('related_document_id');
        $query = $this->access->documents($user)->where('id', '!=', $document->id)->whereNotIn('id', $dismissed);
        if ($document->matter_id) {
            $query->where(fn ($q) => $q->whereNull('matter_id')->orWhere('matter_id', '!=', $document->matter_id));
        }
        $matching = function ($q) use ($signals) {
            $q->where(function ($q) use ($signals) {
                foreach ($signals as $signal) {
                    $q->orWhere(fn ($q) => $q->where('entity_type', $signal->entity_type)->where('normalized_value', $signal->normalized_value));
                }
            });
        };

        return $query->whereHas('entities', $matching)->with(['entities' => $matching])->select(['id', 'name', 'matter_id'])->limit(10)->get()->map(fn ($doc) => [
            'id' => $doc->id, 'name' => $doc->name, 'matter_id' => $doc->matter_id,
            'reason' => 'Both documents reference '.$doc->entities->pluck('value')->unique()->take(3)->implode(', ').'.',
        ]);
    }
}
