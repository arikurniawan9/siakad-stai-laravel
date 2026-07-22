<?php

namespace App\Domain\Academic;

use App\Models\AcademicRegistrationPeriod;
use App\Models\ClassGroup;
use App\Models\CourseEnrollment;
use App\Models\CoursePrerequisite;
use App\Models\SemesterRegistration;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SemesterRegistrationService
{
    private const GRADE_POINTS = ['A' => 4, 'AB' => 3.5, 'B+' => 3.5, 'B' => 3, 'BC' => 2.5, 'C+' => 2.5, 'C' => 2, 'D' => 1, 'E' => 0];

    public function createDraft(AcademicRegistrationPeriod $period, Student $student, CreditLimitService $creditLimits): SemesterRegistration
    {
        return DB::transaction(function () use ($period, $student, $creditLimits): SemesterRegistration {
            $period = AcademicRegistrationPeriod::query()->lockForUpdate()->findOrFail($period->id);
            $student = Student::query()->lockForUpdate()->findOrFail($student->id);
            $this->ensureOpen($period);
            if ($student->status !== 'Aktif') throw ValidationException::withMessages(['registration' => 'Hanya mahasiswa berstatus Aktif yang dapat melakukan registrasi semester.']);

            $resolved = $creditLimits->resolve($student, $period->academicTerm()->firstOrFail(), $period->default_max_credits);
            return SemesterRegistration::query()->firstOrCreate(
                ['student_id' => $student->id, 'academic_term_id' => $period->academic_term_id],
                ['academic_registration_period_id' => $period->id, 'max_credits' => $resolved['limit'], 'previous_gpa' => $resolved['gpa'], 'credit_limit_source' => $resolved['source']]
            );
        }, 3);
    }

    public function addCourse(SemesterRegistration $registration, ClassGroup $classGroup): CourseEnrollment
    {
        return DB::transaction(function () use ($registration, $classGroup): CourseEnrollment {
            $registration = $this->lockedEditable($registration);
            $student = Student::query()->lockForUpdate()->findOrFail($registration->student_id);
            $classGroup = ClassGroup::query()->with(['course.prerequisites'])->where('is_active', true)->lockForUpdate()->findOrFail($classGroup->id);
            if ($student->status !== 'Aktif') throw ValidationException::withMessages(['class_group_id' => 'Status mahasiswa harus Aktif.']);
            if ($classGroup->academic_term_id !== $registration->academic_term_id) throw ValidationException::withMessages(['class_group_id' => 'Kelas tidak berasal dari periode registrasi ini.']);
            if ($classGroup->course?->program_id !== $student->program_id) throw ValidationException::withMessages(['class_group_id' => 'Mata kuliah tidak berasal dari program studi mahasiswa.']);
            if (! $classGroup->day || ! $classGroup->starts_at || ! $classGroup->ends_at) throw ValidationException::withMessages(['class_group_id' => 'Jadwal kelas belum lengkap.']);
            if (! DB::table('curriculum_courses')->join('curricula', 'curricula.id', '=', 'curriculum_courses.curriculum_id')->where('curricula.program_id', $student->program_id)->where('curricula.is_active', true)->whereNull('curricula.deleted_at')->where('curriculum_courses.course_id', $classGroup->course_id)->exists()) {
                throw ValidationException::withMessages(['class_group_id' => 'Mata kuliah belum terdaftar pada kurikulum aktif mahasiswa.']);
            }

            $items = $registration->enrollments()->with('classGroup.course')->lockForUpdate()->get();
            if ($items->contains(fn (CourseEnrollment $item): bool => $item->classGroup->course_id === $classGroup->course_id)) throw ValidationException::withMessages(['class_group_id' => 'Mata kuliah sudah dipilih pada kelas lain.']);
            if ($items->sum('credits') + $classGroup->course->credits > $registration->max_credits) throw ValidationException::withMessages(['class_group_id' => "Total SKS melebihi batas {$registration->max_credits} SKS."]);
            if ($items->contains(fn (CourseEnrollment $item): bool => $this->overlaps($item->classGroup, $classGroup))) throw ValidationException::withMessages(['class_group_id' => 'Jadwal kelas bentrok dengan mata kuliah yang sudah dipilih.']);
            $this->ensurePrerequisites($student, $classGroup->course->prerequisites);

            if ($registration->status === 'rejected') $registration->update(['status' => 'draft', 'review_notes' => null, 'reviewed_by_user_id' => null, 'reviewed_at' => null]);

            return $registration->enrollments()->create(['class_group_id' => $classGroup->id, 'credits' => $classGroup->course->credits, 'status' => 'planned']);
        }, 3);
    }

    public function removeCourse(SemesterRegistration $registration, CourseEnrollment $enrollment): void
    {
        DB::transaction(function () use ($registration, $enrollment): void {
            $registration = $this->lockedEditable($registration);
            $registration->enrollments()->lockForUpdate()->findOrFail($enrollment->id)->delete();
            if ($registration->status === 'rejected') $registration->update(['status' => 'draft', 'review_notes' => null, 'reviewed_by_user_id' => null, 'reviewed_at' => null]);
        }, 3);
    }

    public function submit(SemesterRegistration $registration): SemesterRegistration
    {
        return DB::transaction(function () use ($registration): SemesterRegistration {
            $registration = $this->lockedEditable($registration);
            if (! $registration->enrollments()->lockForUpdate()->exists()) throw ValidationException::withMessages(['registration' => 'Pilih minimal satu kelas sebelum mengajukan KRS.']);
            if ($this->hasOutstandingBills($registration) && $registration->dispensation_status !== 'approved') throw ValidationException::withMessages(['registration' => 'Masih ada tagihan periode ini. Lunasi tagihan atau ajukan dispensasi terlebih dahulu.']);
            $registration->update(['status' => 'submitted', 'submitted_at' => now(), 'review_notes' => null, 'reviewed_by_user_id' => null, 'reviewed_at' => null]);

            return $registration->fresh(['enrollments']);
        }, 3);
    }

    public function requestDispensation(SemesterRegistration $registration, string $reason): SemesterRegistration
    {
        return DB::transaction(function () use ($registration, $reason): SemesterRegistration {
            $registration = SemesterRegistration::query()->lockForUpdate()->findOrFail($registration->id);
            $this->ensureOpen($registration->period()->lockForUpdate()->firstOrFail());
            if (! $this->hasOutstandingBills($registration)) throw ValidationException::withMessages(['dispensation_reason' => 'Tidak ada tagihan tertunggak yang memerlukan dispensasi.']);
            if ($registration->status === 'approved') throw ValidationException::withMessages(['dispensation_reason' => 'Registrasi yang sudah disetujui tidak dapat mengajukan dispensasi.']);
            $registration->update(['dispensation_status' => 'requested', 'dispensation_reason' => trim($reason), 'dispensation_notes' => null, 'dispensation_decided_by_user_id' => null, 'dispensation_decided_at' => null]);

            return $registration->fresh();
        }, 3);
    }

    public function decideDispensation(SemesterRegistration $registration, string $decision, string $notes, User $actor): SemesterRegistration
    {
        return DB::transaction(function () use ($registration, $decision, $notes, $actor): SemesterRegistration {
            $registration = SemesterRegistration::query()->lockForUpdate()->findOrFail($registration->id);
            if ($registration->dispensation_status !== 'requested') throw ValidationException::withMessages(['dispensation' => 'Tidak ada pengajuan dispensasi yang menunggu keputusan.']);
            $registration->update(['dispensation_status' => $decision, 'dispensation_notes' => trim($notes), 'dispensation_decided_by_user_id' => $actor->id, 'dispensation_decided_at' => now()]);

            return $registration->fresh();
        }, 3);
    }

    public function approve(SemesterRegistration $registration, User $actor, ?int $maxCredits = null): SemesterRegistration
    {
        return DB::transaction(function () use ($registration, $actor, $maxCredits): SemesterRegistration {
            $registration = SemesterRegistration::query()->lockForUpdate()->findOrFail($registration->id);
            if ($registration->status !== 'submitted') throw ValidationException::withMessages(['registration' => 'Hanya KRS berstatus submitted yang dapat disetujui.']);
            if ($maxCredits !== null && ($maxCredits < 1 || $maxCredits > 30)) throw ValidationException::withMessages(['max_credits' => 'Batas SKS persetujuan harus antara 1 dan 30.']);
            $items = $registration->enrollments()->lockForUpdate()->get();
            if ($items->isEmpty()) throw ValidationException::withMessages(['registration' => 'KRS tidak memiliki mata kuliah.']);
            $limit = $maxCredits ?? $registration->max_credits;
            if ($items->sum('credits') > $limit) throw ValidationException::withMessages(['max_credits' => "Total KRS melebihi batas persetujuan {$limit} SKS."]);

            $classes = ClassGroup::query()->whereIn('id', $items->pluck('class_group_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($items as $item) {
                $class = $classes->get($item->class_group_id);
                if (! $class || ! $class->is_active || $class->trashed()) throw ValidationException::withMessages(['registration' => 'Salah satu kelas sudah tidak aktif.']);
                if ($class->enrolled_count >= $class->capacity) throw ValidationException::withMessages(['registration' => "Kelas {$class->name} sudah penuh."]);
            }
            foreach ($items as $item) {
                $class = $classes->get($item->class_group_id);
                $class->increment('enrolled_count');
                $item->update(['status' => 'enrolled', 'enrolled_at' => now()]);
            }
            $registration->update(['max_credits' => $limit, 'credit_limit_source' => $maxCredits !== null && $maxCredits !== $registration->max_credits ? 'reviewer' : $registration->credit_limit_source, 'status' => 'approved', 'review_notes' => null, 'reviewed_by_user_id' => $actor->id, 'reviewed_at' => now()]);

            return $registration->fresh(['enrollments']);
        }, 3);
    }

    public function reject(SemesterRegistration $registration, User $actor, string $notes): SemesterRegistration
    {
        return DB::transaction(function () use ($registration, $actor, $notes): SemesterRegistration {
            $registration = SemesterRegistration::query()->lockForUpdate()->findOrFail($registration->id);
            if ($registration->status !== 'submitted') throw ValidationException::withMessages(['registration' => 'Hanya KRS berstatus submitted yang dapat ditolak.']);
            $registration->update(['status' => 'rejected', 'review_notes' => trim($notes), 'reviewed_by_user_id' => $actor->id, 'reviewed_at' => now()]);

            return $registration->fresh();
        }, 3);
    }

    private function lockedEditable(SemesterRegistration $registration): SemesterRegistration
    {
        $registration = SemesterRegistration::query()->lockForUpdate()->findOrFail($registration->id);
        if (! in_array($registration->status, ['draft', 'rejected'], true)) throw ValidationException::withMessages(['registration' => 'KRS yang sedang diperiksa atau sudah disetujui tidak dapat diubah.']);
        $this->ensureOpen($registration->period()->lockForUpdate()->firstOrFail());

        return $registration;
    }

    private function ensureOpen(AcademicRegistrationPeriod $period): void
    {
        if (! $period->is_open || now()->lt($period->starts_at) || now()->gt($period->ends_at)) throw ValidationException::withMessages(['registration' => 'Periode registrasi semester sedang ditutup.']);
    }

    private function hasOutstandingBills(SemesterRegistration $registration): bool
    {
        return DB::table('billing_items')->where('student_id', $registration->student_id)->where('academic_term_id', $registration->academic_term_id)->whereIn('status', ['unpaid', 'partial'])->exists();
    }

    private function overlaps(ClassGroup $first, ClassGroup $second): bool
    {
        return $first->day === $second->day && $first->starts_at < $second->ends_at && $first->ends_at > $second->starts_at;
    }

    private function ensurePrerequisites(Student $student, Collection $prerequisites): void
    {
        foreach ($prerequisites as $prerequisite) {
            $bestGrade = CourseEnrollment::query()
                ->whereIn('grade_status', ['published', 'finalized'])
                ->whereNotNull('letter_grade')
                ->whereHas('registration', fn ($query) => $query->where('student_id', $student->id)->where('status', 'approved'))
                ->whereHas('classGroup', fn ($query) => $query->where('course_id', $prerequisite->prerequisite_course_id))
                ->pluck('letter_grade')
                ->map(fn (string $grade): float => self::GRADE_POINTS[strtoupper($grade)] ?? -1)
                ->max();
            $minimum = self::GRADE_POINTS[strtoupper($prerequisite->minimum_grade)] ?? 2;
            if ($bestGrade === null || $bestGrade < $minimum) throw ValidationException::withMessages(['class_group_id' => "Prasyarat mata kuliah belum terpenuhi dengan nilai minimum {$prerequisite->minimum_grade}."]);
        }
    }
}
