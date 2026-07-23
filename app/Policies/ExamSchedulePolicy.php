<?php

namespace App\Policies;

use App\Models\ExamSchedule;
use App\Models\User;

final class ExamSchedulePolicy
{
    public function viewAny(User $user): bool { return $user->can('exams.view'); }
    public function view(User $user, ExamSchedule $exam): bool
    {
        if (! $user->can('exams.view')) return false;
        if (in_array($user->active_role, ['Admin', 'Prodi', 'Staff'], true)) return true;
        if ($user->active_role === 'Pimpinan') return $exam->status === 'published';
        if ($user->active_role === 'Dosen') return (int) $exam->classGroup?->lecturer_id === (int) $user->lecturer?->id || $exam->invigilators()->where('lecturer_id', $user->lecturer?->id ?? 0)->exists();
        if ($user->active_role === 'Mahasiswa') return $exam->status === 'published' && $exam->classGroup?->enrollments()->where('status', 'enrolled')->whereHas('registration', fn ($query) => $query->where('student_id', $user->student?->id ?? 0)->where('status', 'approved'))->exists();

        return false;
    }
    public function create(User $user): bool { return $user->can('exams.create') && in_array($user->active_role, ['Admin', 'Prodi', 'Staff'], true); }
    public function update(User $user, ExamSchedule $exam): bool { return $user->can('exams.update') && in_array($user->active_role, ['Admin', 'Prodi', 'Staff'], true); }
    public function delete(User $user, ExamSchedule $exam): bool { return $user->can('exams.delete') && in_array($user->active_role, ['Admin', 'Prodi', 'Staff'], true); }
    public function assign(User $user, ExamSchedule $exam): bool { return $user->can('exams.assign') && in_array($user->active_role, ['Admin', 'Prodi', 'Staff'], true); }
    public function operate(User $user, ExamSchedule $exam): bool
    {
        if (! $user->can('exams.operate') || $exam->status === 'cancelled') return false;
        if (in_array($user->active_role, ['Admin', 'Prodi', 'Staff'], true)) return true;

        return $user->active_role === 'Dosen' && $exam->invigilators()->where('lecturer_id', $user->lecturer?->id ?? 0)->exists();
    }
}
