<?php

namespace App\Policies;

use App\Models\SemesterRegistration;
use App\Models\User;

final class SemesterRegistrationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('registration.view') || $user->can('krs.view');
    }

    public function view(User $user, SemesterRegistration $registration): bool
    {
        return $this->owns($user, $registration) || $this->canReview($user, $registration) || $this->decideDispensation($user, $registration);
    }

    public function create(User $user): bool
    {
        return $user->can('krs.create') && $user->student()->exists();
    }

    public function update(User $user, SemesterRegistration $registration): bool
    {
        return $user->can('krs.update')
            && $this->owns($user, $registration)
            && in_array($registration->status, ['draft', 'rejected'], true);
    }

    public function submit(User $user, SemesterRegistration $registration): bool
    {
        return $this->update($user, $registration);
    }

    public function requestChange(User $user, SemesterRegistration $registration): bool
    {
        return $user->can('krs.update') && $this->owns($user, $registration) && $registration->status === 'approved';
    }

    public function cancelChange(User $user, SemesterRegistration $registration): bool
    {
        return $this->requestChange($user, $registration);
    }

    public function reviewChange(User $user, SemesterRegistration $registration): bool
    {
        return $this->canReview($user, $registration);
    }

    public function review(User $user, SemesterRegistration $registration): bool
    {
        return $this->canReview($user, $registration);
    }

    public function decideDispensation(User $user, SemesterRegistration $registration): bool
    {
        return $user->can('registration.update')
            && in_array($user->active_role, ['Admin', 'Prodi', 'Staff', 'Keuangan'], true);
    }

    public function managePeriod(User $user): bool
    {
        return $user->can('registration.create')
            && in_array($user->active_role, ['Admin', 'Prodi'], true);
    }

    private function owns(User $user, SemesterRegistration $registration): bool
    {
        return (int) $registration->student?->user_id === (int) $user->id;
    }

    private function canReview(User $user, SemesterRegistration $registration): bool
    {
        if (! $user->can('registration.update')) return false;
        if ($user->active_role !== 'Dosen') return in_array($user->active_role, ['Admin', 'Prodi', 'Staff'], true);

        return (int) $registration->student?->academic_advisor_id === (int) $user->lecturer?->id;
    }
}
