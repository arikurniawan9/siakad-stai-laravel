<?php

namespace App\Policies;

use App\Models\AcademicGuidanceAppointment;
use App\Models\AcademicGuidanceNote;
use App\Models\Student;
use App\Models\StudentEarlyWarning;
use App\Models\User;

final class AcademicGuidancePolicy
{
    public function viewAny(User $user): bool { return $user->can('guidance.view'); }
    public function create(User $user): bool { return $user->can('guidance.create'); }
    public function manage(User $user): bool { return $user->can('guidance.update'); }
    public function viewStudent(User $user, Student $student): bool
    {
        return $user->active_role === 'Admin' || ($user->active_role === 'Mahasiswa' && (int) $user->student?->id === (int) $student->id) || ($user->active_role === 'Dosen' && (int) $user->lecturer?->id === (int) $student->academic_advisor_id) || in_array($user->active_role, ['Prodi', 'Staff', 'Pimpinan'], true);
    }
    public function viewAppointment(User $user, AcademicGuidanceAppointment $appointment): bool { return $this->viewStudent($user, $appointment->student); }
    public function viewNote(User $user, AcademicGuidanceNote $note): bool { return $this->viewStudent($user, $note->student); }
    public function updateWarning(User $user, StudentEarlyWarning $warning): bool { return $this->viewStudent($user, $warning->student) && $user->can('guidance.update'); }
}
