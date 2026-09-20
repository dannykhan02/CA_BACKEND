<?php

namespace App\Services;

use App\Enums\WorkspaceType;
use App\Models\Document;
use App\Models\Matter;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Policies\DocumentPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class IntelligenceAccess
{
    public function workspace(User $user, bool $write = false): string
    {
        abort_unless($user->current_workspace_id && WorkspaceMember::where('workspace_id', $user->current_workspace_id)->where('user_id', $user->id)->exists(), 403);
        if ($write && $user->currentWorkspace?->type !== WorkspaceType::Personal) {
            abort_unless(in_array($user->role, ['Analyst', 'Reviewer', 'Administrator'], true), 403);
        }

        return $user->current_workspace_id;
    }

    public function documents(User $user): Builder
    {
        $query = Document::where('workspace_id', $this->workspace($user));

        return $user->currentWorkspace?->type === WorkspaceType::Personal
            ? $query->where('uploaded_by', $user->id)
            : $query->whereIn('classification', DocumentPolicy::allowedClassificationsFor($user));
    }

    public function document(User $user, string $id, bool $write = false): Document
    {
        $this->workspace($user, $write);
        $document = $this->documents($user)->findOrFail($id);
        Gate::forUser($user)->authorize('view', $document);

        return $document;
    }

    public function matters(User $user): Builder
    {
        $query = Matter::where('workspace_id', $this->workspace($user));
        if ($user->currentWorkspace?->type === WorkspaceType::Personal) {
            $query->where('created_by', $user->id);
        }

        return $query;
    }

    public function matter(User $user, string $id, bool $write = false): Matter
    {
        $this->workspace($user, $write);

        return $this->matters($user)->findOrFail($id);
    }
}
