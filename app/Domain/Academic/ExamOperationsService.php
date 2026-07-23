<?php

namespace App\Domain\Academic;

use App\Models\CourseEnrollment;
use App\Models\ExamParticipant;
use App\Models\ExamReport;
use App\Models\ExamSchedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ExamOperationsService
{
    public function __construct(private readonly ExamScheduleService $schedules) {}

    public function syncInvigilators(ExamSchedule $exam, array $data, User $actor): ExamSchedule
    {
        return DB::transaction(function () use ($exam, $data, $actor): ExamSchedule {
            $exam = ExamSchedule::query()->with('report')->lockForUpdate()->findOrFail($exam->id);
            $this->assertMutableExam($exam);
            if ($exam->report?->status === 'finalized') throw ValidationException::withMessages(['invigilators' => 'Pengawas tidak dapat diubah setelah berita acara difinalisasi.']);

            $lecturerIds = collect($data['lecturer_ids'])->map(fn ($id) => (int) $id)->unique()->values();
            foreach ($lecturerIds as $lecturerId) $this->assertInvigilatorAvailable($exam, $lecturerId);

            $exam->invigilators()->whereNotIn('lecturer_id', $lecturerIds)->delete();
            foreach ($lecturerIds as $lecturerId) {
                $exam->invigilators()->updateOrCreate(
                    ['lecturer_id' => $lecturerId],
                    ['role' => $lecturerId === (int) $data['coordinator_id'] ? 'coordinator' : 'member', 'assigned_by' => $actor->id],
                );
            }

            return $exam->fresh(['invigilators.lecturer.user']);
        }, 3);
    }

    public function prepareRoster(ExamSchedule $exam, User $actor): Collection
    {
        return DB::transaction(function () use ($exam, $actor): Collection {
            $exam = ExamSchedule::query()->with(['classGroup', 'participants'])->lockForUpdate()->findOrFail($exam->id);
            $this->assertMutableExam($exam);
            if ($exam->status !== 'published') throw ValidationException::withMessages(['exam' => 'Daftar hadir hanya dapat disiapkan untuk jadwal yang sudah dipublikasikan.']);

            $enrollments = CourseEnrollment::query()
                ->where('class_group_id', $exam->class_group_id)
                ->where('status', 'enrolled')
                ->whereHas('registration', fn (Builder $query) => $query->where('academic_term_id', $exam->academic_term_id)->where('status', 'approved'))
                ->with(['registration.student.user'])
                ->orderBy('id')
                ->get();

            foreach ($enrollments as $enrollment) {
                $student = $enrollment->registration->student;
                $eligibility = $this->schedules->eligibility($exam, $student);
                if (! $eligibility['eligible']) continue;

                ExamParticipant::query()->firstOrCreate(
                    ['exam_schedule_id' => $exam->id, 'course_enrollment_id' => $enrollment->id],
                    [
                        'student_id' => $student->id,
                        'participant_number' => Str::limit(strtoupper($exam->exam_type).'-'.$exam->id.'-'.$student->nim, 40, ''),
                        'student_nim' => $student->nim,
                        'student_name' => $student->user?->name ?? $student->nim,
                        'is_eligible' => true,
                        'eligibility_snapshot' => $eligibility,
                    ],
                );
            }

            $participants = $exam->participants()->orderBy('student_name')->get();
            if ($participants->isEmpty()) throw ValidationException::withMessages(['exam' => 'Belum ada mahasiswa yang memenuhi syarat ujian untuk dibuatkan daftar hadir.']);

            return $participants;
        }, 3);
    }

    public function recordAttendance(ExamSchedule $exam, array $rows, User $actor): Collection
    {
        return DB::transaction(function () use ($exam, $rows, $actor): Collection {
            $exam = ExamSchedule::query()->with('report')->lockForUpdate()->findOrFail($exam->id);
            $this->assertMutableExam($exam);
            if ($exam->report?->status === 'finalized') throw ValidationException::withMessages(['attendance' => 'Daftar hadir terkunci setelah berita acara difinalisasi.']);

            $ids = collect($rows)->pluck('id')->map(fn ($id) => (int) $id);
            $participants = $exam->participants()->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
            if ($participants->count() !== $ids->count()) throw ValidationException::withMessages(['participants' => 'Satu atau lebih peserta tidak berada pada ujian ini.']);

            foreach ($rows as $row) {
                $participants[(int) $row['id']]->update([
                    'attendance_status' => $row['attendance_status'],
                    'notes' => $row['notes'] ?? null,
                    'recorded_by' => $actor->id,
                    'recorded_at' => now(),
                ]);
            }

            return $exam->participants()->orderBy('student_name')->get();
        }, 3);
    }

    public function saveReport(ExamSchedule $exam, array $data, User $actor): ExamReport
    {
        return DB::transaction(function () use ($exam, $data, $actor): ExamReport {
            $exam = ExamSchedule::query()->with(['report', 'invigilators'])->lockForUpdate()->findOrFail($exam->id);
            $this->assertMutableExam($exam);
            if ($exam->report?->status === 'finalized') throw ValidationException::withMessages(['report' => 'Berita acara yang sudah final tidak dapat diubah.']);

            $participants = $exam->participants()->lockForUpdate()->get();
            if ($data['status'] === 'finalized') {
                if ($exam->invigilators->isEmpty()) throw ValidationException::withMessages(['invigilators' => 'Tetapkan minimal satu pengawas sebelum finalisasi berita acara.']);
                if ($participants->isEmpty()) throw ValidationException::withMessages(['participants' => 'Siapkan daftar hadir sebelum finalisasi berita acara.']);
                if ($participants->contains(fn (ExamParticipant $participant) => $participant->attendance_status === 'unmarked')) throw ValidationException::withMessages(['participants' => 'Seluruh kehadiran peserta harus dicatat sebelum finalisasi.']);
            }

            $counts = $participants->countBy('attendance_status');
            $report = ExamReport::query()->updateOrCreate(
                ['exam_schedule_id' => $exam->id],
                [
                    ...$data,
                    'participant_count' => $participants->count(),
                    'present_count' => (int) $counts->get('present', 0),
                    'absent_count' => (int) $counts->get('absent', 0),
                    'sick_count' => (int) $counts->get('sick', 0),
                    'excused_count' => (int) $counts->get('excused', 0),
                    'verification_code' => $exam->report?->verification_code ?? (string) Str::ulid(),
                    'prepared_by' => $exam->report?->prepared_by ?? $actor->id,
                    'finalized_by' => $data['status'] === 'finalized' ? $actor->id : null,
                    'finalized_at' => $data['status'] === 'finalized' ? now() : null,
                ],
            );

            return $report->fresh(['preparedBy', 'finalizedBy']);
        }, 3);
    }

    private function assertMutableExam(ExamSchedule $exam): void
    {
        if ($exam->status === 'cancelled') throw ValidationException::withMessages(['exam' => 'Operasional ujian tidak tersedia untuk jadwal yang dibatalkan.']);
    }

    private function assertInvigilatorAvailable(ExamSchedule $exam, int $lecturerId): void
    {
        $conflict = ExamSchedule::query()
            ->whereKeyNot($exam->id)
            ->where('status', '!=', 'cancelled')
            ->whereDate('exam_date', $exam->exam_date)
            ->where('starts_at', '<', $exam->ends_at)
            ->where('ends_at', '>', $exam->starts_at)
            ->where(function (Builder $query) use ($lecturerId): void {
                $query->whereHas('classGroup', fn (Builder $class) => $class->where('lecturer_id', $lecturerId))
                    ->orWhereHas('invigilators', fn (Builder $assignment) => $assignment->where('lecturer_id', $lecturerId));
            })
            ->exists();

        if ($conflict) throw ValidationException::withMessages(['lecturer_ids' => 'Salah satu dosen memiliki jadwal mengajar atau mengawas ujian yang beririsan.']);
    }
}
