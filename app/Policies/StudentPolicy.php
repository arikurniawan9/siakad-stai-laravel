<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;

class StudentPolicy
{
    public function viewAny(User $user): bool { return $user->can('students.view'); }
    public function create(User $user): bool { return $user->can('students.create'); }
    public function update(User $user, Student $student): bool { return $user->can('students.update'); }
    public function delete(User $user, Student $student): bool { return $user->can('students.delete'); }
    public function restore(User $user, Student $student): bool { return $user->can('students.update'); }
}
