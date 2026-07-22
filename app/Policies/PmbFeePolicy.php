<?php

namespace App\Policies;

use App\Models\PmbFee;
use App\Models\User;

final class PmbFeePolicy
{
    public function viewAny(User $user): bool { return $user->can('pmb_fees.view'); }
    public function create(User $user): bool { return $user->can('pmb_fees.create'); }
    public function update(User $user, PmbFee $fee): bool { return $user->can('pmb_fees.update'); }
    public function delete(User $user, PmbFee $fee): bool { return $user->can('pmb_fees.delete'); }
    public function restore(User $user, PmbFee $fee): bool { return $user->can('pmb_fees.update'); }
}
