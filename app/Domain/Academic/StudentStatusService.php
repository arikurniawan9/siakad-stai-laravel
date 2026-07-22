<?php

namespace App\Domain\Academic;

use App\Models\Student;
use App\Models\StudentStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudentStatusService
{
    private const TRANSITIONS = [
        'Aktif' => ['Cuti', 'Lulus', 'Nonaktif'],
        'Cuti' => ['Aktif', 'Nonaktif'],
        'Nonaktif' => ['Aktif'],
        'Lulus' => [],
    ];

    public function transition(Student $student, string $toStatus, string $reason, string $effectiveOn, ?int $termId, User $actor): StudentStatusHistory
    {
        return DB::transaction(function () use ($student, $toStatus, $reason, $effectiveOn, $termId, $actor): StudentStatusHistory {
            $locked = Student::query()->lockForUpdate()->findOrFail($student->id);
            if (! in_array($toStatus, self::TRANSITIONS[$locked->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => "Perubahan status {$locked->status} ke {$toStatus} tidak diizinkan."]);
            }

            $fromStatus = $locked->status;
            $locked->update(['status' => $toStatus]);

            return StudentStatusHistory::create([
                'student_id' => $locked->id,
                'academic_term_id' => $termId,
                'changed_by_user_id' => $actor->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'effective_on' => $effectiveOn,
                'reason' => $reason,
            ]);
        });
    }
}
