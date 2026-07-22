<?php

namespace App\Policies;

use App\Models\Lecturer;
use App\Models\User;

class LecturerPolicy
{
    public function viewAny(User $user): bool { return $user->can('lecturers.view'); }
    public function create(User $user): bool { return $user->can('lecturers.create'); }
    public function update(User $user, Lecturer $lecturer): bool { return $user->can('lecturers.update'); }
    public function delete(User $user, Lecturer $lecturer): bool { return $user->can('lecturers.delete'); }
    public function restore(User $user, Lecturer $lecturer): bool { return $user->can('lecturers.update'); }
}
