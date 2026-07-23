<?php

namespace App\Policies;

use App\Models\GraduationApplication;
use App\Models\User;

final class GraduationApplicationPolicy
{
    public function viewAny(User $user): bool { return $user->can('graduation.view'); }
    public function view(User $user, GraduationApplication $application): bool
    {
        if (! $user->can('graduation.view')) return false;
        if ($user->active_role === 'Mahasiswa') return (int) $application->student_id === (int) $user->student?->id;
        return in_array($user->active_role, ['Admin', 'Prodi', 'Staff', 'Pimpinan'], true);
    }
    public function create(User $user): bool { return $user->can('graduation.create') && $user->active_role === 'Mahasiswa' && (bool) $user->student; }
    public function update(User $user, GraduationApplication $application): bool { return $user->can('graduation.update') && $user->active_role === 'Mahasiswa' && (int) $application->student_id === (int) $user->student?->id && $application->status === 'draft'; }
    public function review(User $user, GraduationApplication $application): bool { return $user->can('graduation.update') && in_array($user->active_role, ['Admin', 'Prodi', 'Staff'], true); }
    public function graduate(User $user, GraduationApplication $application): bool { return $user->can('graduation.update') && in_array($user->active_role, ['Admin', 'Prodi'], true); }
}
