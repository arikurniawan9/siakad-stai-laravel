<?php

namespace App\Domain\Academic;

use App\Models\AcademicTerm;
use App\Models\BillingItem;
use App\Models\CourseEnrollment;
use App\Models\ExamSchedule;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ExamScheduleService
{
    public function create(array $data, User $actor): ExamSchedule
    {
        return DB::transaction(function () use ($data, $actor): ExamSchedule {
            $this->assertValidClassTerm($data);
            $this->assertNoConflict($data);
            return ExamSchedule::create([...$data, 'verification_code' => (string) Str::ulid(), 'created_by' => $actor->id]);
        }, 3);
    }

    public function update(ExamSchedule $exam, array $data, User $actor): ExamSchedule
    {
        return DB::transaction(function () use ($exam, $data, $actor): ExamSchedule {
            $exam = ExamSchedule::query()->with('report')->lockForUpdate()->findOrFail($exam->id);
            if ($exam->report?->status === 'finalized') throw ValidationException::withMessages(['exam' => 'Jadwal ujian terkunci setelah berita acara difinalisasi.']);
            $this->assertValidClassTerm($data);
            $this->assertNoConflict($data, $exam->id);
            $exam->update([...$data, 'updated_by' => $actor->id]);
            return $exam->fresh();
        }, 3);
    }

    public function eligibility(ExamSchedule $exam, Student $student): array
    {
        $enrollment = CourseEnrollment::query()->where('class_group_id', $exam->class_group_id)->where('status', 'enrolled')->whereHas('registration', fn (Builder $query) => $query->where('student_id', $student->id)->where('academic_term_id', $exam->academic_term_id)->where('status', 'approved'))->with('registration')->first();
        $krsOk = (bool) $enrollment;
        $sessions = $exam->classGroup->attendanceSessions()->where('status', 'closed')->with(['records' => fn ($query) => $enrollment ? $query->where('course_enrollment_id', $enrollment->id) : $query->whereKey(0)])->get();
        $totalSessions = $sessions->count();
        $attendedSessions = $sessions->filter(fn ($session) => $session->records->contains(fn ($record) => in_array($record->status, ['present', 'late'], true)))->count();
        $attendanceThreshold = (float) config('siakad.exam.attendance_threshold', 75);
        $attendancePercentage = $totalSessions > 0 ? round($attendedSessions / $totalSessions * 100, 2) : 0;
        $attendanceOk = $krsOk && $totalSessions > 0 && $attendancePercentage >= $attendanceThreshold;
        $outstanding = (float) BillingItem::query()->where('student_id', $student->id)->where('academic_term_id', $exam->academic_term_id)->whereIn('status', ['unpaid', 'partial'])->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as outstanding')->value('outstanding');
        $financeOk = $outstanding <= 0.0001;

        return ['eligible' => $krsOk && $attendanceOk && $financeOk, 'krs' => ['ok' => $krsOk], 'attendance' => ['ok' => $attendanceOk, 'percentage' => $attendancePercentage, 'threshold' => $attendanceThreshold, 'attended' => $attendedSessions, 'total' => $totalSessions], 'finance' => ['ok' => $financeOk, 'outstanding' => $outstanding]];
    }

    private function assertValidClassTerm(array $data): void
    {
        if (! DB::table('class_groups')->where('id', $data['class_group_id'])->where('academic_term_id', $data['academic_term_id'])->exists()) throw ValidationException::withMessages(['class_group_id' => 'Kelas tidak berada pada periode akademik yang dipilih.']);
    }

    private function assertNoConflict(array $data, ?int $exceptId = null): void
    {
        $term = AcademicTerm::query()->findOrFail($data['academic_term_id']);
        $class = DB::table('class_groups')->where('id', $data['class_group_id'])->first();
        if (! $class) throw ValidationException::withMessages(['class_group_id' => 'Kelas ujian tidak ditemukan.']);
        if ($data['room_id']) {
            $roomCapacity = DB::table('rooms')->where('id', $data['room_id'])->value('capacity');
            if ($roomCapacity !== null && (int) $class->enrolled_count > (int) $roomCapacity) throw ValidationException::withMessages(['room_id' => "Kapasitas ruangan tidak cukup untuk {$class->enrolled_count} peserta terdaftar."]);
        }
        if (ExamSchedule::query()->where('class_group_id', $data['class_group_id'])->where('exam_type', $data['exam_type'])->when($exceptId, fn (Builder $query) => $query->whereKeyNot($exceptId))->exists()) throw ValidationException::withMessages(['exam_type' => 'Jenis ujian ini sudah memiliki jadwal pada kelas tersebut.']);
        $overlap = ExamSchedule::query()->where('status', '!=', 'cancelled')->where('academic_term_id', $data['academic_term_id'])->whereDate('exam_date', $data['exam_date'])->where('starts_at', '<', $data['ends_at'])->where('ends_at', '>', $data['starts_at'])->when($exceptId, fn (Builder $query) => $query->whereKeyNot($exceptId));
        $errors = [];
        if ($data['room_id'] && (clone $overlap)->where('room_id', $data['room_id'])->whereIn('delivery_mode', ['onsite', 'hybrid'])->exists()) $errors['room_id'] = 'Ruangan sudah digunakan pada waktu ujian yang beririsan.';
        $lecturerId = DB::table('class_groups')->where('id', $data['class_group_id'])->value('lecturer_id');
        if ((clone $overlap)->whereHas('classGroup', fn (Builder $query) => $query->where('lecturer_id', $lecturerId))->exists()) $errors['class_group_id'] = 'Dosen pengampu memiliki ujian lain pada waktu yang beririsan.';
        $studentIds = CourseEnrollment::query()->where('class_group_id', $data['class_group_id'])->where('status', 'enrolled')->whereHas('registration', fn (Builder $query) => $query->where('academic_term_id', $data['academic_term_id'])->where('status', 'approved'))->whereHas('registration.student')->with('registration:id,student_id')->get()->pluck('registration.student_id')->filter()->unique();
        if ($studentIds->isNotEmpty() && (clone $overlap)->whereHas('classGroup.enrollments', fn (Builder $query) => $query->where('status', 'enrolled')->whereHas('registration', fn (Builder $registration) => $registration->where('status', 'approved')->whereIn('student_id', $studentIds)))->exists()) $errors['class_group_id'] = 'Terdapat mahasiswa KRS aktif yang memiliki ujian lain pada waktu yang beririsan.';
        if ($errors !== []) throw ValidationException::withMessages($errors);
        if ($term->starts_on && $data['exam_date'] < $term->starts_on->toDateString()) throw ValidationException::withMessages(['exam_date' => 'Tanggal ujian berada sebelum periode akademik.']);
        if ($term->ends_on && $data['exam_date'] > $term->ends_on->toDateString()) throw ValidationException::withMessages(['exam_date' => 'Tanggal ujian berada setelah periode akademik.']);
    }
}
