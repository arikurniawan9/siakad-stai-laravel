<?php

namespace App\Domain\Security;

use App\Models\User;
use Illuminate\Validation\ValidationException;

final class UserAdministrationService
{
    public function update(User $user, array $data, User $actor): User
    {
        $user = User::withTrashed()->lockForUpdate()->findOrFail($user->id);
        $roles = array_values($data['roles']);
        $willBeActive = (bool) $data['is_active'];

        if ($user->is($actor) && ! $willBeActive) {
            throw ValidationException::withMessages(['is_active' => 'Akun yang sedang digunakan tidak dapat dinonaktifkan.']);
        }

        $this->ensureAdminContinuity($user, $roles, $willBeActive);
        unset($data['roles'], $data['password_confirmation']);
        if (blank($data['password'] ?? null)) unset($data['password']);

        $user->update($data);
        $user->syncRoles($roles);

        return $user->fresh(['roles']);
    }

    public function archive(User $user, User $actor): User
    {
        $user = User::query()->lockForUpdate()->findOrFail($user->id);
        if ($user->is($actor)) {
            throw ValidationException::withMessages(['user' => 'Akun yang sedang digunakan tidak dapat diarsipkan.']);
        }

        $this->ensureAdminContinuity($user, $user->getRoleNames()->all(), false);
        $user->forceFill(['is_active' => false])->save();
        $user->delete();

        return $user;
    }

    private function ensureAdminContinuity(User $user, array $nextRoles, bool $willBeActive): void
    {
        if (! $user->hasRole('Admin') || ($willBeActive && in_array('Admin', $nextRoles, true))) return;

        $otherAdminIds = User::query()
            ->whereKeyNot($user->id)
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('name', 'Admin'))
            ->lockForUpdate()
            ->pluck('users.id');

        if ($otherAdminIds->isEmpty()) {
            throw ValidationException::withMessages(['roles' => 'Admin aktif terakhir tidak dapat dinonaktifkan atau kehilangan role Admin.']);
        }
    }
}
