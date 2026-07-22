<?php

namespace App\Policies;

use App\Models\Faculty;
use App\Models\User;

final class FacultyPolicy
{
    public function viewAny(User $user): bool { return $user->can('faculties.view'); }
    public function create(User $user): bool { return $user->can('faculties.create'); }
    public function update(User $user, Faculty $faculty): bool { return $user->can('faculties.update'); }
    public function delete(User $user, Faculty $faculty): bool { return $user->can('faculties.delete'); }
    public function restore(User $user, Faculty $faculty): bool { return $user->can('faculties.update'); }
}
