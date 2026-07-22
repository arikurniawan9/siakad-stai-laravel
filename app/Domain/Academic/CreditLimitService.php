<?php

namespace App\Domain\Academic;

use App\Models\AcademicTerm;
use App\Models\CourseEnrollment;
use App\Models\SemesterRegistration;
use App\Models\Student;

final class CreditLimitService
{
    public function resolve(Student $student, AcademicTerm $currentTerm, int $defaultLimit): array
    {
        if (! $currentTerm->starts_on) return ['limit' => $defaultLimit, 'gpa' => null, 'source' => 'default_period'];
        $previous = SemesterRegistration::query()
            ->with('academicTerm:id,starts_on')
            ->where('student_id', $student->id)->where('status', 'approved')
            ->whereHas('academicTerm', fn ($query) => $query->where('starts_on', '<', $currentTerm->starts_on))
            ->whereHas('enrollments', fn ($query) => $query->where('status', 'enrolled')->whereIn('grade_status', ['published', 'finalized'])->whereNotNull('letter_grade'))
            ->get()->sortByDesc(fn (SemesterRegistration $item) => $item->academicTerm->starts_on?->timestamp ?? 0)->first();
        if (! $previous) return ['limit' => $defaultLimit, 'gpa' => null, 'source' => 'default_period'];
        $items = CourseEnrollment::query()->where('semester_registration_id', $previous->id)->where('status', 'enrolled')->whereIn('grade_status', ['published', 'finalized'])->whereNotNull('letter_grade')->get();
        $credits = (int) $items->sum('credits');
        if (! $credits) return ['limit' => $defaultLimit, 'gpa' => null, 'source' => 'default_period'];
        $points = collect(config('siakad.grade_scale'))->pluck('points', 'letter');
        $gpa = round((float) $items->sum(fn (CourseEnrollment $item) => ((float) ($points[$item->letter_grade] ?? 0)) * $item->credits) / $credits, 2);
        $rule = collect(config('siakad.credit_limits'))->sortByDesc('minimum_gpa')->first(fn (array $item): bool => $gpa >= (float) $item['minimum_gpa']);

        return ['limit' => min($defaultLimit, (int) ($rule['credits'] ?? $defaultLimit)), 'gpa' => $gpa, 'source' => 'previous_gpa'];
    }
}
