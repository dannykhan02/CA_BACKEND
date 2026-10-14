<?php

namespace App\Policies;

use App\Enums\WorkspaceType;
use App\Models\Document;
use App\Models\DocumentNote;
use App\Models\User;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\Gate;

class DocumentNotePolicy
{
    public function create(User $user, Document $document): bool
    {
        if ($document->trashed() || $document->workspace_id === null
            || $document->workspace_id !== $user->current_workspace_id
            || ! WorkspaceMember::where('workspace_id', $document->workspace_id)
                ->where('user_id', $user->id)->exists()
            || ! Gate::forUser($user)->allows('view', $document)) {
            return false;
        }

        return $document->workspace?->type !== WorkspaceType::Personal
            || $document->uploaded_by === $user->id;
    }

    public function viewAny(User $user, Document $document): bool
    {
        return $this->create($user, $document);
    }

    public function view(User $user, DocumentNote $note, Document $document): bool
    {
        return $note->deleted_at === null
            && $note->user_id === $user->id
            && $note->workspace_id === $document->workspace_id
            && $note->document_id === $document->id
            && $this->create($user, $document);
    }

    public function update(User $user, DocumentNote $note, Document $document): bool
    {
        return $this->view($user, $note, $document);
    }

    public function delete(User $user, DocumentNote $note, Document $document): bool
    {
        return $this->view($user, $note, $document);
    }
}
