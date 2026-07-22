<?php

namespace App\Policies;

use App\Models\StudentServiceRequest;
use App\Models\User;

final class StudentServiceRequestPolicy
{
    public function viewAny(User $user): bool { return $user->can('service_requests.view'); }

    public function create(User $user): bool
    {
        return $user->active_role === 'Mahasiswa' && $user->can('service_requests.create') && (bool) $user->student;
    }

    public function view(User $user, StudentServiceRequest $request): bool
    {
        if (! $user->can('service_requests.view')) return false;
        if ($user->active_role === 'Mahasiswa') return (int) $request->student_id === (int) $user->student?->id;
        if ($user->active_role === 'Admin') return true;
        if ($user->active_role === 'Dosen') return $request->steps()->where('stage', 'advisor')->exists() && (int) $request->student->academic_advisor_id === (int) $user->lecturer?->id;
        if ($user->active_role === 'Prodi') return $request->steps()->where('stage', 'program')->exists();
        if (in_array($user->active_role, ['Keuangan', 'Bendahara'], true)) return $request->steps()->where('stage', 'finance')->exists();
        if ($user->active_role === 'Staff') return $request->steps()->where('stage', 'academic')->exists();

        return false;
    }

    public function cancel(User $user, StudentServiceRequest $request): bool
    {
        return $user->active_role === 'Mahasiswa' && $user->can('service_requests.update') && (int) $request->student_id === (int) $user->student?->id;
    }

    public function review(User $user, StudentServiceRequest $request): bool
    {
        return $user->can('service_requests.update') && $this->view($user, $request) && $user->active_role !== 'Mahasiswa';
    }
}
