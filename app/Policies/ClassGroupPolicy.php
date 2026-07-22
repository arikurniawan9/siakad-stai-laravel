<?php

namespace App\Policies;

use App\Models\ClassGroup;
use App\Models\User;

class ClassGroupPolicy
{
    public function viewAny(User $user): bool { return $user->can('schedules.view'); }
    public function create(User $user): bool { return $user->can('schedules.create'); }
    public function update(User $user, ClassGroup $classGroup): bool { return $user->can('schedules.update'); }
    public function delete(User $user, ClassGroup $classGroup): bool { return $user->can('schedules.delete'); }
    public function restore(User $user, ClassGroup $classGroup): bool { return $user->can('schedules.update'); }
    public function viewGrades(User $user, ClassGroup $classGroup): bool
    {
        if (! $user->can('grades.view')) return false;
        if ($user->active_role === 'Dosen') return (int) $classGroup->lecturer_id === (int) $user->lecturer?->id;

        return in_array($user->active_role, ['Admin', 'Prodi'], true);
    }

    public function manageGrades(User $user, ClassGroup $classGroup): bool
    {
        return $user->can('grades.update') && $this->viewGrades($user, $classGroup);
    }

    public function finalizeGrades(User $user, ClassGroup $classGroup): bool
    {
        return $user->can('grades.view') && $user->can('grades.update') && in_array($user->active_role, ['Admin', 'Prodi'], true);
    }

    public function viewLms(User $user, ClassGroup $classGroup): bool
    {
        if (! $user->can('lms.view')) return false;
        if ($user->active_role === 'Dosen') return (int) $classGroup->lecturer_id === (int) $user->lecturer?->id;
        if ($user->active_role === 'Mahasiswa') {
            return $classGroup->enrollments()->where('status', 'enrolled')->whereHas('registration', fn ($query) => $query->where('student_id', $user->student?->id ?? 0)->where('status', 'approved'))->exists();
        }

        return in_array($user->active_role, ['Admin', 'Prodi'], true);
    }

    public function manageLms(User $user, ClassGroup $classGroup): bool
    {
        return $this->viewLms($user, $classGroup) && $user->can('lms.update') && in_array($user->active_role, ['Admin', 'Prodi', 'Dosen'], true);
    }

    public function viewAttendance(User $user, ClassGroup $classGroup): bool
    {
        if (! $user->can('attendance.view')) return false;
        if ($user->active_role === 'Dosen') return (int) $classGroup->lecturer_id === (int) $user->lecturer?->id;
        if ($user->active_role === 'Mahasiswa') return $classGroup->enrollments()->where('status', 'enrolled')->whereHas('registration', fn ($query) => $query->where('student_id', $user->student?->id ?? 0)->where('status', 'approved'))->exists();
        return in_array($user->active_role, ['Admin', 'Prodi'], true);
    }

    public function manageAttendance(User $user, ClassGroup $classGroup): bool
    {
        return $this->viewAttendance($user, $classGroup) && $user->can('attendance.update') && in_array($user->active_role, ['Admin', 'Prodi', 'Dosen'], true);
    }
}
