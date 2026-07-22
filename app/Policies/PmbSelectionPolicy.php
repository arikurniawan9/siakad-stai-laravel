<?php

namespace App\Policies;

use App\Models\PmbSelection;
use App\Models\User;

final class PmbSelectionPolicy
{
    public function viewAny(User $user): bool { return $user->can('pmb_selection.view'); }
    public function view(User $user, PmbSelection $selection): bool { return $user->can('pmb_selection.view'); }
    public function create(User $user): bool { return $user->can('pmb_selection.create'); }
    public function update(User $user, PmbSelection $selection): bool { return $user->can('pmb_selection.update'); }
    public function delete(User $user, PmbSelection $selection): bool { return $user->can('pmb_selection.delete'); }
    public function convert(User $user, PmbSelection $selection): bool { return $user->can('pmb_selection.update') && $user->can('students.create'); }
}
