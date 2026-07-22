<?php

namespace App\Policies;

use App\Models\PmbApplication;
use App\Models\User;

final class PmbApplicationPolicy
{
    public function view(User $user, PmbApplication $application): bool
    {
        return $application->user_id === $user->id;
    }

    public function viewVerification(User $user, PmbApplication $application): bool
    {
        return $user->can('pmb_verification.view');
    }

    public function update(User $user, PmbApplication $application): bool
    {
        return $application->user_id === $user->id && $application->status === 'draft';
    }

    public function manageDocuments(User $user, PmbApplication $application): bool
    {
        return $this->update($user, $application);
    }

    public function submit(User $user, PmbApplication $application): bool
    {
        return $this->update($user, $application);
    }

    public function viewDocument(User $user, PmbApplication $application): bool
    {
        return $application->user_id === $user->id || $user->can('pmb_verification.view');
    }

    public function review(User $user, PmbApplication $application): bool
    {
        return $user->can('pmb_verification.update') && $application->status === 'submitted';
    }
}
