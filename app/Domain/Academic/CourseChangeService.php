<?php

namespace App\Domain\Academic;

use App\Models\ClassGroup;
use App\Models\CourseChangeRequest;
use App\Models\CourseEnrollment;
use App\Models\SemesterRegistration;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CourseChangeService
{
    private const POINTS = ['A' => 4, 'B+' => 3.5, 'B' => 3, 'C+' => 2.5, 'C' => 2, 'D' => 1, 'E' => 0];

    public function request(SemesterRegistration $registration, array $data): CourseChangeRequest
    {
        return DB::transaction(function () use ($registration, $data): CourseChangeRequest {
            $registration = SemesterRegistration::query()->with('student')->lockForUpdate()->findOrFail($registration->id);
            $this->ensureOpen($registration);
            if ($registration->status !== 'approved') throw ValidationException::withMessages(['change' => 'Perubahan hanya tersedia untuk KRS yang sudah disetujui.']);
            return $data['type'] === 'add' ? $this->requestAdd($registration, $data) : $this->requestDrop($registration, $data);
        }, 3);
    }

    public function cancel(SemesterRegistration $registration, CourseChangeRequest $change): void
    {
        DB::transaction(function () use ($registration, $change): void {
            $registration = SemesterRegistration::query()->lockForUpdate()->findOrFail($registration->id);
            $change = $registration->courseChangeRequests()->lockForUpdate()->findOrFail($change->id);
            if ($change->status !== 'requested') throw ValidationException::withMessages(['change' => 'Hanya pengajuan yang masih menunggu dapat dibatalkan.']);
            $change->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        }, 3);
    }

    public function review(SemesterRegistration $registration, CourseChangeRequest $change, string $decision, ?string $notes, User $actor): void
    {
        DB::transaction(function () use ($registration, $change, $decision, $notes, $actor): void {
            $registration = SemesterRegistration::query()->with('student')->lockForUpdate()->findOrFail($registration->id);
            $change = $registration->courseChangeRequests()->lockForUpdate()->findOrFail($change->id);
            if ($change->status !== 'requested') throw ValidationException::withMessages(['change' => 'Pengajuan ini sudah diproses.']);
            if ($decision === 'approved') {
                $change->type === 'add' ? $this->approveAdd($registration, $change) : $this->approveDrop($registration, $change);
            }
            $change->update(['status' => $decision, 'review_notes' => filled($notes) ? trim((string) $notes) : null, 'reviewed_by_user_id' => $actor->id, 'reviewed_at' => now()]);
        }, 3);
    }

    private function requestAdd(SemesterRegistration $registration, array $data): CourseChangeRequest
    {
        $class = ClassGroup::query()->with(['course.prerequisites'])->where('is_active', true)->lockForUpdate()->findOrFail($data['class_group_id']);
        $this->validateAdd($registration, $class, true);
        return $registration->courseChangeRequests()->create(['type' => 'add', 'class_group_id' => $class->id, 'reason' => $data['reason']]);
    }

    private function requestDrop(SemesterRegistration $registration, array $data): CourseChangeRequest
    {
        $enrollment = $registration->enrollments()->where('status', 'enrolled')->lockForUpdate()->findOrFail($data['course_enrollment_id']);
        if ($enrollment->grade_status !== 'draft') throw ValidationException::withMessages(['course_enrollment_id' => 'Mata kuliah yang nilainya sudah dipublikasikan tidak dapat dibatalkan.']);
        if ($registration->courseChangeRequests()->where('course_enrollment_id', $enrollment->id)->where('status', 'requested')->exists()) throw ValidationException::withMessages(['course_enrollment_id' => 'Perubahan untuk mata kuliah ini sedang menunggu persetujuan.']);
        return $registration->courseChangeRequests()->create(['type' => 'drop', 'class_group_id' => $enrollment->class_group_id, 'course_enrollment_id' => $enrollment->id, 'reason' => $data['reason']]);
    }

    private function approveAdd(SemesterRegistration $registration, CourseChangeRequest $change): void
    {
        $class = ClassGroup::query()->with(['course.prerequisites'])->lockForUpdate()->findOrFail($change->class_group_id);
        $this->validateAdd($registration, $class, false);
        if ($class->enrolled_count >= $class->capacity) throw ValidationException::withMessages(['change' => 'Kapasitas kelas sudah penuh.']);
        $enrollment = $registration->enrollments()->where('class_group_id', $class->id)->lockForUpdate()->first();
        if ($enrollment) $enrollment->update(['credits' => $class->course->credits, 'status' => 'enrolled', 'enrolled_at' => now(), 'dropped_at' => null]);
        else $registration->enrollments()->create(['class_group_id' => $class->id, 'credits' => $class->course->credits, 'status' => 'enrolled', 'enrolled_at' => now()]);
        $class->increment('enrolled_count');
    }

    private function approveDrop(SemesterRegistration $registration, CourseChangeRequest $change): void
    {
        $enrollment = $registration->enrollments()->where('status', 'enrolled')->lockForUpdate()->findOrFail($change->course_enrollment_id);
        if ($enrollment->grade_status !== 'draft') throw ValidationException::withMessages(['change' => 'Nilai mata kuliah sudah dipublikasikan.']);
        $class = ClassGroup::query()->lockForUpdate()->findOrFail($enrollment->class_group_id);
        $enrollment->update(['status' => 'dropped', 'dropped_at' => now()]);
        if ($class->enrolled_count > 0) $class->decrement('enrolled_count');
    }

    private function validateAdd(SemesterRegistration $registration, ClassGroup $class, bool $includePending): void
    {
        $student = $registration->student;
        if ($class->academic_term_id !== $registration->academic_term_id || $class->course?->program_id !== $student->program_id) throw ValidationException::withMessages(['class_group_id' => 'Kelas tidak sesuai semester atau program studi mahasiswa.']);
        if (! $class->day || ! $class->starts_at || ! $class->ends_at) throw ValidationException::withMessages(['class_group_id' => 'Jadwal kelas belum lengkap.']);
        if (! DB::table('curriculum_courses')->join('curricula', 'curricula.id', '=', 'curriculum_courses.curriculum_id')->where('curricula.program_id', $student->program_id)->where('curricula.is_active', true)->whereNull('curricula.deleted_at')->where('curriculum_courses.course_id', $class->course_id)->exists()) throw ValidationException::withMessages(['class_group_id' => 'Mata kuliah tidak ada pada kurikulum aktif.']);
        $classes = $registration->enrollments()->where('status', 'enrolled')->with('classGroup.course')->lockForUpdate()->get()->pluck('classGroup');
        if ($includePending) {
            $pendingIds = $registration->courseChangeRequests()->where('type', 'add')->where('status', 'requested')->pluck('class_group_id');
            $classes = $classes->merge(ClassGroup::query()->with('course')->whereIn('id', $pendingIds)->get());
        }
        if ($classes->contains(fn (ClassGroup $item) => $item->course_id === $class->course_id)) throw ValidationException::withMessages(['class_group_id' => 'Mata kuliah sudah terdaftar atau sedang diajukan.']);
        if ($classes->sum(fn (ClassGroup $item) => $item->course->credits) + $class->course->credits > $registration->max_credits) throw ValidationException::withMessages(['class_group_id' => "Total SKS melebihi batas {$registration->max_credits} SKS."]);
        if ($classes->contains(fn (ClassGroup $item) => $item->day === $class->day && $item->starts_at < $class->ends_at && $item->ends_at > $class->starts_at)) throw ValidationException::withMessages(['class_group_id' => 'Jadwal kelas bentrok.']);
        $this->ensurePrerequisites($student, $class->course->prerequisites);
    }

    private function ensureOpen(SemesterRegistration $registration): void
    {
        $period = $registration->period()->lockForUpdate()->firstOrFail();
        if (! $period->is_changes_open || ! $period->changes_starts_at || ! $period->changes_ends_at || ! now()->between($period->changes_starts_at, $period->changes_ends_at)) throw ValidationException::withMessages(['change' => 'Periode perubahan KRS sedang ditutup.']);
    }

    private function ensurePrerequisites(Student $student, Collection $prerequisites): void
    {
        foreach ($prerequisites as $prerequisite) {
            $best = CourseEnrollment::query()->whereIn('grade_status', ['published', 'finalized'])->whereHas('registration', fn ($query) => $query->where('student_id', $student->id)->where('status', 'approved'))->whereHas('classGroup', fn ($query) => $query->where('course_id', $prerequisite->prerequisite_course_id))->pluck('letter_grade')->map(fn ($grade) => self::POINTS[strtoupper((string) $grade)] ?? -1)->max();
            if ($best === null || $best < (self::POINTS[strtoupper($prerequisite->minimum_grade)] ?? 2)) throw ValidationException::withMessages(['class_group_id' => "Prasyarat minimum {$prerequisite->minimum_grade} belum terpenuhi."]);
        }
    }
}
