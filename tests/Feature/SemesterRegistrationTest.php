<?php

namespace Tests\Feature;

use App\Models\AcademicRegistrationPeriod;
use App\Models\AcademicTerm;
use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CoursePrerequisite;
use App\Models\CourseChangeRequest;
use App\Models\Curriculum;
use App\Models\Lecturer;
use App\Models\Program;
use App\Models\SemesterRegistration;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class SemesterRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_requires_permission_and_student_only_sees_own_registration(): void
    {
        $context = $this->context();
        $registration = $this->registration($context);
        $this->actingAs(User::factory()->create())->get(route('academic.registration'))->assertForbidden();

        $other = $this->studentUser($context['program'], '22002');
        $this->actingAs($other)->get(route('academic.registration', ['academic_term_id' => $context['term']->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Academic/Registration')->where('ownRegistration', null)->where('registrations', null));

        $this->actingAs($context['studentUser'])->get(route('academic.registration', ['academic_term_id' => $context['term']->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('ownRegistration.id', $registration->id)->where('abilities.editOwn', true));
    }

    public function test_student_can_start_only_during_open_period_and_start_is_idempotent(): void
    {
        $context = $this->context();
        $context['period']->update(['is_open' => false]);
        $this->actingAs($context['studentUser'])->post(route('academic.registration.start'), ['period_id' => $context['period']->id])->assertSessionHasErrors('registration');
        $this->assertDatabaseCount('semester_registrations', 0);

        $context['period']->update(['is_open' => true]);
        $this->post(route('academic.registration.start'), ['period_id' => $context['period']->id])->assertSessionHasNoErrors();
        $this->post(route('academic.registration.start'), ['period_id' => $context['period']->id])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('semester_registrations', 1);
        $this->assertDatabaseHas('audit_logs', ['module' => 'registration', 'action' => 'registration_started']);
    }

    public function test_krs_rejects_duplicate_course_schedule_conflict_and_credit_overflow(): void
    {
        $context = $this->context();
        $registration = $this->registration($context, ['max_credits' => 5]);
        $first = $this->classGroup($context, $context['courses'][0], 'A', 'Senin', '08:00', '09:40');
        $sameCourse = $this->classGroup($context, $context['courses'][0], 'B', 'Selasa', '08:00', '09:40');
        $conflict = $this->classGroup($context, $context['courses'][1], 'A', 'Senin', '09:00', '10:40');
        $thirdCourse = $this->course($context['program'], 'TI103', 3);
        $this->attachCurriculum($context['curriculum'], $thirdCourse, 2);
        $overflow = $this->classGroup($context, $thirdCourse, 'A', 'Rabu', '08:00', '09:40');

        $this->actingAs($context['studentUser'])->post(route('academic.registration.courses.store', $registration), ['class_group_id' => $first->id])->assertSessionHasNoErrors();
        $this->post(route('academic.registration.courses.store', $registration), ['class_group_id' => $sameCourse->id])->assertSessionHasErrors('class_group_id');
        $this->post(route('academic.registration.courses.store', $registration), ['class_group_id' => $conflict->id])->assertSessionHasErrors('class_group_id');
        $this->post(route('academic.registration.courses.store', $registration), ['class_group_id' => $overflow->id])->assertSessionHasErrors('class_group_id');
        $this->assertDatabaseCount('course_enrollments', 1);
    }

    public function test_prerequisite_requires_a_sufficient_published_grade_from_approved_history(): void
    {
        $context = $this->context();
        $registration = $this->registration($context);
        CoursePrerequisite::create(['course_id' => $context['courses'][1]->id, 'prerequisite_course_id' => $context['courses'][0]->id, 'minimum_grade' => 'C']);
        $target = $this->classGroup($context, $context['courses'][1], 'A', 'Selasa', '08:00', '09:40');
        $this->actingAs($context['studentUser'])->post(route('academic.registration.courses.store', $registration), ['class_group_id' => $target->id])->assertSessionHasErrors('class_group_id');

        $priorTerm = AcademicTerm::create(['name' => 'Genap 2025', 'code' => '2025-GENAP', 'semester' => 'Genap']);
        $priorPeriod = AcademicRegistrationPeriod::create(['academic_term_id' => $priorTerm->id, 'starts_at' => now()->subYear(), 'ends_at' => now()->subMonths(11), 'default_max_credits' => 24, 'is_open' => false]);
        $priorRegistration = SemesterRegistration::create(['student_id' => $context['student']->id, 'academic_term_id' => $priorTerm->id, 'academic_registration_period_id' => $priorPeriod->id, 'max_credits' => 24, 'status' => 'approved']);
        $priorClass = ClassGroup::create(['academic_term_id' => $priorTerm->id, 'course_id' => $context['courses'][0]->id, 'name' => 'A', 'capacity' => 30, 'day' => 'Senin', 'starts_at' => '08:00', 'ends_at' => '09:40', 'is_active' => true]);
        CourseEnrollment::create(['semester_registration_id' => $priorRegistration->id, 'class_group_id' => $priorClass->id, 'credits' => 3, 'status' => 'enrolled', 'letter_grade' => 'C', 'grade_status' => 'published']);

        $this->post(route('academic.registration.courses.store', $registration), ['class_group_id' => $target->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('course_enrollments', ['semester_registration_id' => $registration->id, 'class_group_id' => $target->id]);
    }

    public function test_outstanding_bill_blocks_submission_until_finance_approves_dispensation(): void
    {
        $context = $this->context();
        $registration = $this->registration($context);
        $class = $this->classGroup($context, $context['courses'][0]);
        CourseEnrollment::create(['semester_registration_id' => $registration->id, 'class_group_id' => $class->id, 'credits' => 3]);
        DB::table('billing_items')->insert(['student_id' => $context['student']->id, 'academic_term_id' => $context['term']->id, 'invoice_number' => 'INV-KRS-1', 'description' => 'UKT', 'amount' => 5000000, 'paid_amount' => 0, 'status' => 'unpaid', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($context['studentUser'])->post(route('academic.registration.submit', $registration))->assertSessionHasErrors('registration');
        $this->post(route('academic.registration.dispensation.store', $registration), ['reason' => 'Pembayaran sedang menunggu pencairan beasiswa.'])->assertSessionHasNoErrors();
        $finance = $this->permissionUser('Keuangan', 'registration.view', 'registration.update');
        $this->actingAs($finance)->post(route('academic.registration.dispensation.decision', $registration), ['decision' => 'approved', 'notes' => 'Disetujui sampai akhir bulan.'])->assertSessionHasNoErrors();
        $this->actingAs($context['studentUser'])->post(route('academic.registration.submit', $registration))->assertSessionHasNoErrors();
        $this->assertSame('submitted', $registration->fresh()->status);
        $this->assertSame('approved', $registration->fresh()->dispensation_status);
    }

    public function test_only_assigned_advisor_can_review_and_approval_reserves_class_capacity(): void
    {
        $context = $this->context();
        $advisorUser = $this->permissionUser('Dosen', 'registration.view', 'registration.update', 'krs.view', 'krs.update');
        $advisor = Lecturer::create(['user_id' => $advisorUser->id, 'program_id' => $context['program']->id, 'name' => 'Dosen PA', 'nidn' => 'NIDN-PA', 'employment_status' => 'Tetap']);
        $context['student']->update(['academic_advisor_id' => $advisor->id]);
        $registration = $this->registration($context, ['status' => 'submitted']);
        $class = $this->classGroup($context, $context['courses'][0], capacity: 1);
        CourseEnrollment::create(['semester_registration_id' => $registration->id, 'class_group_id' => $class->id, 'credits' => 3]);
        $otherAdvisor = $this->permissionUser('Dosen', 'registration.view', 'registration.update', 'krs.view', 'krs.update');
        Lecturer::create(['user_id' => $otherAdvisor->id, 'program_id' => $context['program']->id, 'name' => 'Dosen Lain', 'nidn' => 'NIDN-LAIN', 'employment_status' => 'Tetap']);

        $this->actingAs($otherAdvisor)->post(route('academic.registration.review', $registration), ['decision' => 'approved', 'max_credits' => 24])->assertForbidden();
        $this->actingAs($advisorUser)->post(route('academic.registration.review', $registration), ['decision' => 'approved', 'max_credits' => 24])->assertSessionHasNoErrors();
        $this->assertSame('approved', $registration->fresh()->status);
        $this->assertSame(1, $class->fresh()->enrolled_count);
        $this->assertDatabaseHas('course_enrollments', ['semester_registration_id' => $registration->id, 'status' => 'enrolled']);
    }

    public function test_rejected_krs_can_be_edited_and_resubmitted(): void
    {
        $context = $this->context();
        $registration = $this->registration($context, ['status' => 'rejected', 'review_notes' => 'Ganti jadwal.']);
        $class = $this->classGroup($context, $context['courses'][0]);
        $this->actingAs($context['studentUser'])->post(route('academic.registration.courses.store', $registration), ['class_group_id' => $class->id])->assertSessionHasNoErrors();
        $this->assertSame('draft', $registration->fresh()->status);
        $this->assertNull($registration->fresh()->review_notes);
        $this->post(route('academic.registration.submit', $registration))->assertSessionHasNoErrors();
        $this->assertSame('submitted', $registration->fresh()->status);
    }

    public function test_new_registration_uses_previous_semester_gpa_credit_limit_snapshot(): void
    {
        $context = $this->context();
        $context['term']->update(['starts_on' => '2026-08-01']);
        $priorTerm = AcademicTerm::create(['name' => 'Genap 2025', 'code' => '2025-GENAP-LIMIT', 'semester' => 'Genap', 'starts_on' => '2026-02-01']);
        $priorPeriod = AcademicRegistrationPeriod::create(['academic_term_id' => $priorTerm->id, 'starts_at' => '2026-01-01', 'ends_at' => '2026-01-31', 'default_max_credits' => 24]);
        $prior = SemesterRegistration::create(['student_id' => $context['student']->id, 'academic_term_id' => $priorTerm->id, 'academic_registration_period_id' => $priorPeriod->id, 'max_credits' => 24, 'status' => 'approved']);
        $priorClass = ClassGroup::create(['academic_term_id' => $priorTerm->id, 'course_id' => $context['courses'][0]->id, 'name' => 'LIMIT', 'capacity' => 30, 'day' => 'Senin', 'starts_at' => '08:00', 'ends_at' => '09:40']);
        CourseEnrollment::create(['semester_registration_id' => $prior->id, 'class_group_id' => $priorClass->id, 'credits' => 3, 'status' => 'enrolled', 'letter_grade' => 'C', 'final_score' => 65, 'grade_status' => 'finalized']);

        $this->actingAs($context['studentUser'])->post(route('academic.registration.start'), ['period_id' => $context['period']->id])->assertSessionHasNoErrors();
        $registration = SemesterRegistration::query()->where('academic_term_id', $context['term']->id)->sole();
        $this->assertSame(18, $registration->max_credits);
        $this->assertSame('2.00', $registration->previous_gpa);
        $this->assertSame('previous_gpa', $registration->credit_limit_source);
    }

    public function test_approved_student_can_request_add_and_reviewer_reserves_capacity_atomically(): void
    {
        $context = $this->context();
        $context['period']->update(['is_changes_open' => true, 'changes_starts_at' => now()->subDay(), 'changes_ends_at' => now()->addDay()]);
        $registration = $this->registration($context, ['status' => 'approved']);
        $class = $this->classGroup($context, $context['courses'][1], capacity: 1);
        $this->actingAs($context['studentUser'])->post(route('academic.registration.changes.store', $registration), ['type' => 'add', 'class_group_id' => $class->id, 'reason' => 'Menambah mata kuliah sesuai rencana studi semester.'])->assertSessionHasNoErrors();
        $change = CourseChangeRequest::query()->sole();
        $reviewer = $this->permissionUser('Prodi', 'registration.view', 'registration.update');
        $this->actingAs($reviewer)->post(route('academic.registration.changes.review', [$registration, $change]), ['decision' => 'approved', 'notes' => 'Sesuai rencana studi.'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $change->fresh()->status);
        $this->assertSame(1, $class->fresh()->enrolled_count);
        $this->assertDatabaseHas('course_enrollments', ['semester_registration_id' => $registration->id, 'class_group_id' => $class->id, 'status' => 'enrolled']);
    }

    public function test_approved_student_can_request_drop_and_approval_releases_capacity(): void
    {
        $context = $this->context();
        $context['period']->update(['is_changes_open' => true, 'changes_starts_at' => now()->subDay(), 'changes_ends_at' => now()->addDay()]);
        $registration = $this->registration($context, ['status' => 'approved']);
        $class = $this->classGroup($context, $context['courses'][0]);
        $class->update(['enrolled_count' => 1]);
        $enrollment = CourseEnrollment::create(['semester_registration_id' => $registration->id, 'class_group_id' => $class->id, 'credits' => 3, 'status' => 'enrolled']);
        $this->actingAs($context['studentUser'])->post(route('academic.registration.changes.store', $registration), ['type' => 'drop', 'course_enrollment_id' => $enrollment->id, 'reason' => 'Penyesuaian beban studi setelah konsultasi akademik.'])->assertSessionHasNoErrors();
        $reviewer = $this->permissionUser('Prodi', 'registration.view', 'registration.update');
        $this->actingAs($reviewer)->post(route('academic.registration.changes.review', [$registration, CourseChangeRequest::query()->sole()]), ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame('dropped', $enrollment->fresh()->status);
        $this->assertSame(0, $class->fresh()->enrolled_count);
    }

    public function test_change_request_is_closed_safe_scoped_and_cancellable(): void
    {
        $context = $this->context();
        $registration = $this->registration($context, ['status' => 'approved']);
        $class = $this->classGroup($context, $context['courses'][1]);
        $this->actingAs($context['studentUser'])->post(route('academic.registration.changes.store', $registration), ['type' => 'add', 'class_group_id' => $class->id, 'reason' => 'Pengajuan perubahan kelas pada semester berjalan.'])->assertSessionHasErrors('change');
        $context['period']->update(['is_changes_open' => true, 'changes_starts_at' => now()->subDay(), 'changes_ends_at' => now()->addDay()]);
        $this->post(route('academic.registration.changes.store', $registration), ['type' => 'add', 'class_group_id' => $class->id, 'reason' => 'Pengajuan perubahan kelas pada semester berjalan.'])->assertSessionHasNoErrors();
        $change = CourseChangeRequest::query()->sole();
        $other = $this->studentUser($context['program'], '22999');
        $this->actingAs($other)->delete(route('academic.registration.changes.cancel', [$registration, $change]))->assertForbidden();
        $this->actingAs($context['studentUser'])->delete(route('academic.registration.changes.cancel', [$registration, $change]))->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $change->fresh()->status);
    }

    private function context(): array
    {
        $program = Program::create(['name' => 'Teknik Informatika', 'code' => 'TI', 'degree' => 'S1', 'is_active' => true]);
        $term = AcademicTerm::create(['name' => 'Ganjil 2026', 'code' => '2026-GANJIL', 'semester' => 'Ganjil', 'is_active' => true]);
        $curriculum = Curriculum::create(['program_id' => $program->id, 'name' => 'Kurikulum 2026', 'code' => 'KUR-2026', 'target_credits' => 144, 'is_active' => true]);
        $courses = [$this->course($program, 'TI101'), $this->course($program, 'TI102')];
        foreach ($courses as $index => $course) $this->attachCurriculum($curriculum, $course, $index + 1);
        $studentUser = $this->studentUser($program, '22001');
        $student = $studentUser->student;
        $period = AcademicRegistrationPeriod::create(['academic_term_id' => $term->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->addWeek(), 'default_max_credits' => 24, 'is_open' => true]);

        return compact('program', 'term', 'curriculum', 'courses', 'studentUser', 'student', 'period');
    }

    private function studentUser(Program $program, string $nim): User
    {
        $user = $this->permissionUser('Mahasiswa', 'registration.view', 'krs.view', 'krs.create', 'krs.update', 'krs.delete');
        Student::create(['user_id' => $user->id, 'program_id' => $program->id, 'nim' => $nim, 'status' => 'Aktif', 'current_semester' => 1]);

        return $user->fresh('student');
    }

    private function permissionUser(string $activeRole, string ...$permissions): User
    {
        $user = User::factory()->create(['active_role' => $activeRole]);
        foreach ($permissions as $permission) Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function registration(array $context, array $overrides = []): SemesterRegistration
    {
        return SemesterRegistration::create([...['student_id' => $context['student']->id, 'academic_term_id' => $context['term']->id, 'academic_registration_period_id' => $context['period']->id, 'max_credits' => 24, 'status' => 'draft'], ...$overrides]);
    }

    private function course(Program $program, string $code, int $credits = 3): Course
    {
        return Course::create(['program_id' => $program->id, 'name' => "Mata Kuliah {$code}", 'code' => $code, 'credits' => $credits, 'type' => 'Wajib', 'is_active' => true]);
    }

    private function attachCurriculum(Curriculum $curriculum, Course $course, int $semester): void
    {
        DB::table('curriculum_courses')->insert(['curriculum_id' => $curriculum->id, 'course_id' => $course->id, 'semester' => $semester, 'credits' => $course->credits, 'is_required' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function classGroup(array $context, Course $course, string $name = 'A', string $day = 'Senin', string $startsAt = '08:00', string $endsAt = '09:40', int $capacity = 30): ClassGroup
    {
        return ClassGroup::create(['academic_term_id' => $context['term']->id, 'course_id' => $course->id, 'name' => $name, 'capacity' => $capacity, 'enrolled_count' => 0, 'day' => $day, 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'is_active' => true]);
    }
}
