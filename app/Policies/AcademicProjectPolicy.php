<?php

namespace App\Policies;

use App\Models\AcademicProject;
use App\Models\User;

final class AcademicProjectPolicy
{
    public function viewAny(User $user): bool { return $user->can('projects.view'); }
    public function view(User $user, AcademicProject $project): bool
    {
        if (! $user->can('projects.view')) return false;
        if ($user->active_role === 'Mahasiswa') return (int) $project->student_id === (int) $user->student?->id;
        if ($user->active_role === 'Dosen') return $project->lecturerAssignments()->where('lecturer_id', $user->lecturer?->id ?? 0)->exists();

        return in_array($user->active_role, ['Admin', 'Prodi', 'Staff', 'Pimpinan'], true);
    }
    public function create(User $user): bool { return $user->can('projects.create') && $user->active_role === 'Mahasiswa' && (bool) $user->student; }
    public function update(User $user, AcademicProject $project): bool
    {
        return $user->can('projects.update') && $user->active_role === 'Mahasiswa' && (int) $project->student_id === (int) $user->student?->id && in_array($project->status, ['draft', 'revision_required'], true);
    }
    public function review(User $user, AcademicProject $project): bool { return $user->can('projects.update') && in_array($user->active_role, ['Admin', 'Prodi', 'Staff'], true); }
    public function assign(User $user, AcademicProject $project): bool { return $user->can('projects.update') && in_array($user->active_role, ['Admin', 'Prodi'], true); }
    public function upload(User $user, AcademicProject $project): bool
    {
        return $user->can('projects.update') && $user->active_role === 'Mahasiswa' && (int) $project->student_id === (int) $user->student?->id && in_array($project->status, ['draft', 'revision_required', 'active'], true);
    }
    public function log(User $user, AcademicProject $project): bool
    {
        return $user->can('projects.update') && $project->status === 'active' && $user->active_role === 'Mahasiswa' && (int) $project->student_id === (int) $user->student?->id;
    }
    public function supervise(User $user, AcademicProject $project): bool
    {
        return $user->can('projects.update') && $project->status === 'active' && $user->active_role === 'Dosen' && $project->lecturerAssignments()->where('role', 'supervisor')->where('lecturer_id', $user->lecturer?->id ?? 0)->exists();
    }
    public function schedule(User $user, AcademicProject $project): bool { return $user->can('projects.update') && in_array($user->active_role, ['Admin', 'Prodi', 'Staff'], true) && $project->status === 'active'; }
    public function score(User $user, AcademicProject $project): bool
    {
        return $user->can('projects.update') && $project->status === 'active' && $user->active_role === 'Dosen' && $project->lecturerAssignments()->where('role', 'examiner')->where('lecturer_id', $user->lecturer?->id ?? 0)->exists();
    }
    public function publish(User $user, AcademicProject $project): bool { return $user->can('projects.update') && in_array($user->active_role, ['Admin', 'Prodi', 'Staff'], true) && $project->status === 'active'; }
}
