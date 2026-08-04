<?php

namespace App\Policies;

use App\Enums\WorkspaceType;
use App\Models\Document;
use App\Models\User;

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
        if ($document->workspace?->type === WorkspaceType::Personal) {
            return $document->uploaded_by === $user->id;
        }

        $allowedRoles = self::MIN_ROLE_FOR_CLASSIFICATION[$document->classification] ?? [];
        return in_array($user->role, $allowedRoles, true);
    }

    public function approve(User $user, Document $document): bool
    {
        if ($document->workspace?->type === WorkspaceType::Personal) {
            return $document->uploaded_by === $user->id;
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
            return $document->uploaded_by === $user->id;
        }

        return $this->approve($user, $document);
    }

    /**
     * Organization-only concept — Personal workspaces scope by ownership,
     * not classification, so this must not be called for a Personal user's
     * listing query. DocumentController::index() needs a workspace-aware
     * branch here too — flagged as the next file to fix (see note above).
     */
    public static function allowedClassificationsFor(User $user): array
    {
        return array_keys(array_filter(
            self::MIN_ROLE_FOR_CLASSIFICATION,
            fn (array $roles) => in_array($user->role, $roles, true)
        ));
    }
}