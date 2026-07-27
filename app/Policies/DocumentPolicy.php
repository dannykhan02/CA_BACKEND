<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    /**
     * Classification read-access matrix — business decision, confirmed
     * with Dan 2026-07-24. See docs/DECISIONS.md.
     */
    private const MIN_ROLE_FOR_CLASSIFICATION = [
        'Public' => ['Viewer', 'Analyst', 'Reviewer', 'Administrator'],
        'Internal' => ['Viewer', 'Analyst', 'Reviewer', 'Administrator'],
        'Confidential' => ['Analyst', 'Reviewer', 'Administrator'],
        'Restricted' => ['Reviewer', 'Administrator'],
    ];

    public function view(User $user, Document $document): bool
    {
        $allowedRoles = self::MIN_ROLE_FOR_CLASSIFICATION[$document->classification] ?? [];
        return in_array($user->role, $allowedRoles, true);
    }

    public function approve(User $user, Document $document): bool
    {
        return $this->view($user, $document)
            && in_array($user->role, ['Administrator', 'Reviewer'], true);
    }

    public function reject(User $user, Document $document): bool
    {
        return $this->approve($user, $document);
    }

    /**
     * Which classifications this user's role is permitted to see at all —
     * used to constrain the index() listing query, not just single-document
     * access. Without this, a Viewer could never open a Restricted document
     * directly, but could still see it exists in the list and read its name,
     * size, and metadata — a real information leak for a regulator's system.
     */
    public static function allowedClassificationsFor(User $user): array
    {
        return array_keys(array_filter(
            self::MIN_ROLE_FOR_CLASSIFICATION,
            fn (array $roles) => in_array($user->role, $roles, true)
        ));
    }
}