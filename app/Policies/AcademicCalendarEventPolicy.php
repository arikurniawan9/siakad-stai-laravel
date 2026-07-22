<?php

namespace App\Policies;

use App\Models\AcademicCalendarEvent;
use App\Models\User;

final class AcademicCalendarEventPolicy
{
    public function viewAny(User $user): bool { return $user->can('calendar.view'); }
    public function create(User $user): bool { return $user->can('calendar.create') && in_array($user->active_role, ['Admin', 'Prodi', 'Staff'], true); }
    public function update(User $user, AcademicCalendarEvent $event): bool { return $user->can('calendar.update') && in_array($user->active_role, ['Admin', 'Prodi', 'Staff'], true); }
    public function delete(User $user, AcademicCalendarEvent $event): bool { return $user->can('calendar.delete') && in_array($user->active_role, ['Admin', 'Prodi', 'Staff'], true); }
}
