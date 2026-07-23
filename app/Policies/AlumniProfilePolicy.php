<?php

namespace App\Policies;

use App\Models\AlumniProfile;
use App\Models\User;

final class AlumniProfilePolicy
{
    public function view(User $user, AlumniProfile $profile): bool
    {
        if (! $user->can('alumni.view')) return false;
        if ($user->active_role === 'Mahasiswa') return (int) $profile->student_id === (int) $user->student?->id;
        return in_array($user->active_role, ['Admin', 'Prodi', 'Staff', 'Pimpinan'], true);
    }
    public function update(User $user, AlumniProfile $profile): bool { return $user->can('alumni.update') && $user->active_role === 'Mahasiswa' && (int) $profile->student_id === (int) $user->student?->id; }
}
