<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentNote;
use App\Models\User;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** All Notes lookups are scoped before an ID is resolved or returned. */
class DocumentNoteAccess
{
    public function document(User $user, string $id, bool $lock = false): Document
    {
        abort_unless($user->current_workspace_id !== null && Str::isUuid($id), 404);
        $member = WorkspaceMember::where('workspace_id', $user->current_workspace_id)
            ->where('user_id', $user->id);
        abort_unless(($lock ? $member->lockForUpdate() : $member)->exists(), 404);

        $query = Document::query()->whereKey($id)->where('workspace_id', $user->current_workspace_id);
        $document = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($document !== null && Gate::forUser($user)->allows('create', [DocumentNote::class, $document]), 404);

        return $document;
    }

    public function note(User $user, Document $document, string $id, bool $lock = false): DocumentNote
    {
        abort_unless(Str::isUuid($id), 404);
        $query = DocumentNote::query()->whereKey($id)->where('workspace_id', $document->workspace_id)
            ->where('document_id', $document->id)->where('user_id', $user->id);
        $note = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($note !== null && Gate::forUser($user)->allows('view', [$note, $document]), 404);

        return $note;
    }
}
