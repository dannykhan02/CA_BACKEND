<?php

namespace App\Policies;

use App\Enums\WorkspaceType;
use App\Models\Document;
use App\Models\User;
use App\Models\WorkspaceMember;

class DocumentPolicy
{
    private const MIN_ROLE_FOR_CLASSIFICATION = [
        'Public' => ['Viewer', 'Analyst', 'Reviewer', 'Administrator'],
        'Internal' => ['Viewer', 'Analyst', 'Reviewer', 'Administrator'],
        'Confidential' => ['Analyst', 'Reviewer', 'Administrator'],
        'Restricted' => ['Reviewer', 'Administrator'],
    ];

    public function view(User $user, Document $document): bool
    {
        if (! $user->active || $document->workspace_id !== $user->current_workspace_id
            || ! WorkspaceMember::where('workspace_id', $document->workspace_id)
                ->where('user_id', $user->id)->exists()) {
            return false;
        }

        if ($document->workspace?->type === WorkspaceType::Personal) {
            return $document->uploaded_by === $user->id;
        }

        // Membership and current-workspace ownership above are the tenant
        // boundary. Classification remains a separate role check.
        $allowedRoles = self::MIN_ROLE_FOR_CLASSIFICATION[$document->classification] ?? [];
        return in_array($user->role, $allowedRoles, true);
    }

    public function approve(User $user, Document $document): bool
    {
        if ($document->workspace?->type === WorkspaceType::Personal) {
            return $this->view($user, $document);
        }

        return $this->view($user, $document)
            && in_array($user->role, ['Administrator', 'Reviewer'], true);
    }

    public function reject(User $user, Document $document): bool
    {
        return $this->approve($user, $document);
    }

    public function reprocess(User $user, Document $document): bool
    {
        if ($document->workspace?->type === WorkspaceType::Personal) {
            return $this->view($user, $document);
        }

        return $this->approve($user, $document);
    }

    /**
     * Organization-only concept — Personal workspaces scope by ownership,
     * not classification, so this must not be called for a Personal user's
     * listing query. DocumentController::index() already branches on
     * workspace type and applies an ownership filter for Personal.
     */
    public static function allowedClassificationsFor(User $user): array
    {
        return array_keys(array_filter(
            self::MIN_ROLE_FOR_CLASSIFICATION,
            fn (array $roles) => in_array($user->role, $roles, true)
        ));
    }

    public function delete(User $user, Document $document): bool
    {
        if ($document->workspace?->type === WorkspaceType::Personal) {
            return $this->view($user, $document);
        }

        return $this->approve($user, $document);
    }
}
