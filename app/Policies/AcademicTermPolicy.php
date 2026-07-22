<?php

namespace App\Policies;

use App\Models\AcademicTerm;
use App\Models\User;

final class AcademicTermPolicy
{
    public function viewAny(User $user): bool { return $user->can('academic_terms.view'); }
    public function create(User $user): bool { return $user->can('academic_terms.create'); }
    public function update(User $user, AcademicTerm $academicTerm): bool { return $user->can('academic_terms.update'); }
    public function delete(User $user, AcademicTerm $academicTerm): bool { return $user->can('academic_terms.delete'); }
    public function restore(User $user, AcademicTerm $academicTerm): bool { return $user->can('academic_terms.update'); }
}
