<?php

namespace App\Policies;

use App\Models\Campus;
use App\Models\User;

final class CampusPolicy
{
    public function viewAny(User $user): bool { return $user->can('campuses.view'); }
    public function create(User $user): bool { return $user->can('campuses.create'); }
    public function update(User $user, Campus $campus): bool { return $user->can('campuses.update'); }
    public function delete(User $user, Campus $campus): bool { return $user->can('campuses.delete'); }
    public function restore(User $user, Campus $campus): bool { return $user->can('campuses.update'); }
}
