<?php

namespace App\Policies;

use App\Models\Building;
use App\Models\User;

class BuildingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('buildings.view');
    }

    public function create(User $user): bool
    {
        return $user->can('buildings.create');
    }

    public function update(User $user, Building $building): bool
    {
        return $user->can('buildings.update');
    }

    public function delete(User $user, Building $building): bool
    {
        return $user->can('buildings.delete');
    }

    public function restore(User $user, Building $building): bool
    {
        return $user->can('buildings.update');
    }
}
