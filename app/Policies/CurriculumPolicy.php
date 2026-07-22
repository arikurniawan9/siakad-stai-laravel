<?php

namespace App\Policies;

use App\Models\Curriculum;
use App\Models\User;

class CurriculumPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('curricula.view');
    }

    public function create(User $user): bool
    {
        return $user->can('curricula.create');
    }

    public function update(User $user, Curriculum $curriculum): bool
    {
        return $user->can('curricula.update');
    }

    public function delete(User $user, Curriculum $curriculum): bool
    {
        return $user->can('curricula.delete');
    }

    public function restore(User $user, Curriculum $curriculum): bool
    {
        return $user->can('curricula.update');
    }

    public function manageCourses(User $user): bool
    {
        return $user->can('curricula.update');
    }
}
