<?php

namespace Tests\Feature;

use App\Models\AcademicRegistrationPeriod;
use App\Models\AcademicTerm;
use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\GradeComponent;
use App\Models\GradeSheet;
use App\Models\Lecturer;
use App\Models\Program;
use App\Models\SemesterRegistration;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class AcademicGradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_requires_permission_and_lecturer_only_sees_assigned_classes(): void
    {
        $context = $this->context();
        $this->actingAs(User::factory()->create())->get(route('academic.grades'))->assertForbidden();
        $otherLecturerUser = $this->permissionUser('Dosen', 'grades.view', 'grades.update');
        Lecturer::create(['user_id' => $otherLecturerUser->id, 'program_id' => $context['program']->id, 'name' => 'Dosen Lain', 'nidn' => 'NIDN-LAIN', 'employment_status' => 'Tetap']);

        $this->actingAs($otherLecturerUser)->get(route('academic.grades', ['selected' => $context['class']->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Academic/Grades')->where('classGroups.total', 0)->where('selectedClass', null));
        $this->actingAs($context['lecturerUser'])->get(route('academic.grades', ['selected' => $context['class']->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('selectedClass.id', $context['class']->id)->where('abilities.manage', true));
    }

    public function test_component_create_and_edit_enforce_total_weight_and_score_floor(): void
    {
        $context = $this->context();
        $this->actingAs($context['lecturerUser'])->post(route('academic.grades.components.store', $context['class']), $this->componentPayload('UTS', 60))->assertSessionHasNoErrors();
        $this->post(route('academic.grades.components.store', $context['class']), $this->componentPayload('UAS', 50))->assertSessionHasErrors('weight');
        $this->post(route('academic.grades.components.store', $context['class']), $this->componentPayload('UAS', 40))->assertSessionHasNoErrors();
        $uts = GradeComponent::query()->where('name', 'UTS')->sole();
        $this->put(route('academic.grades.scores.update', [$context['class'], $context['enrollments'][0]]), ['scores' => [$uts->id => 90, GradeComponent::query()->where('name', 'UAS')->value('id') => 80]])->assertSessionHasNoErrors();
        $this->patch(route('academic.grades.components.update', [$context['class'], $uts]), $this->componentPayload('UTS', 60, 80))->assertSessionHasErrors('max_score');
        $this->assertDatabaseHas('audit_logs', ['module' => 'grades', 'action' => 'grade_component_created']);
    }

    public function test_scores_are_scoped_to_class_and_bounded_by_component_maximum(): void
    {
        $context = $this->context();
        [$uts, $uas] = $this->components($context['class']);
        $this->actingAs($context['lecturerUser'])->put(route('academic.grades.scores.update', [$context['class'], $context['enrollments'][0]]), ['scores' => [$uts->id => 101, $uas->id => 80]])->assertSessionHasErrors("scores.{$uts->id}");

        $otherClass = ClassGroup::create(['academic_term_id' => $context['term']->id, 'course_id' => $context['course']->id, 'lecturer_id' => $context['lecturer']->id, 'name' => 'B', 'capacity' => 30, 'day' => 'Selasa', 'starts_at' => '08:00', 'ends_at' => '09:40', 'is_active' => true]);
        $otherEnrollment = CourseEnrollment::create(['semester_registration_id' => $context['registrations'][0]->id, 'class_group_id' => $otherClass->id, 'credits' => 3, 'status' => 'enrolled']);
        $this->put(route('academic.grades.scores.update', [$context['class'], $otherEnrollment]), ['scores' => [$uts->id => 80, $uas->id => 80]])->assertSessionHasErrors('scores');
        $this->assertDatabaseCount('student_grade_scores', 0);
    }

    public function test_publication_requires_complete_scores_and_calculates_final_grades_atomically(): void
    {
        $context = $this->context();
        [$uts, $uas] = $this->components($context['class']);
        $this->actingAs($context['lecturerUser'])->put(route('academic.grades.scores.update', [$context['class'], $context['enrollments'][0]]), ['scores' => [$uts->id => 80, $uas->id => 90]])->assertSessionHasNoErrors();
        $this->post(route('academic.grades.publish', $context['class']), ['notes' => 'Nilai semester ganjil.'])->assertSessionHasErrors('grade_sheet');
        $this->assertSame('draft', GradeSheet::query()->sole()->status);
        $this->assertNull($context['enrollments'][0]->fresh()->final_score);

        $this->put(route('academic.grades.scores.update', [$context['class'], $context['enrollments'][1]]), ['scores' => [$uts->id => 70, $uas->id => 80]])->assertSessionHasNoErrors();
        $this->post(route('academic.grades.publish', $context['class']), ['notes' => 'Nilai semester ganjil.'])->assertSessionHasNoErrors();
        $this->assertSame('published', GradeSheet::query()->sole()->status);
        $this->assertDatabaseHas('course_enrollments', ['id' => $context['enrollments'][0]->id, 'final_score' => 85, 'letter_grade' => 'A', 'grade_status' => 'published']);
        $this->assertDatabaseHas('course_enrollments', ['id' => $context['enrollments'][1]->id, 'final_score' => 75, 'letter_grade' => 'B', 'grade_status' => 'published']);
    }

    public function test_student_only_receives_own_published_khs_and_transcript_metrics(): void
    {
        $context = $this->context();
        [$uts, $uas] = $this->components($context['class']);
        foreach ($context['enrollments'] as $index => $enrollment) {
            $scores = $index === 0 ? [$uts->id => 90, $uas->id => 90] : [$uts->id => 60, $uas->id => 60];
            $this->actingAs($context['lecturerUser'])->put(route('academic.grades.scores.update', [$context['class'], $enrollment]), ['scores' => $scores])->assertSessionHasNoErrors();
        }
        $this->post(route('academic.grades.publish', $context['class']))->assertSessionHasNoErrors();

        $this->actingAs($context['studentUsers'][0])->get(route('academic.grades'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Academic/Grades')->where('mode', 'student')->has('khs', 1)->has('transcript', 1)
                ->where('transcript.0.letter_grade', 'A')->where('metrics.credits', 3)->where('metrics.gpa', 4));
        $this->actingAs($context['studentUsers'][1])->get(route('academic.grades'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('transcript', 1)->where('transcript.0.letter_grade', 'D')->where('metrics.gpa', 1));
    }

    public function test_only_prodi_or_admin_can_reopen_and_finalize_published_grades(): void
    {
        $context = $this->publishedContext();
        $this->actingAs($context['lecturerUser'])->post(route('academic.grades.finalize', $context['class']))->assertForbidden();
        $prodi = $this->permissionUser('Prodi', 'grades.view', 'grades.update');
        $this->actingAs($prodi)->post(route('academic.grades.reopen', $context['class']))->assertSessionHasNoErrors();
        $this->assertSame('draft', GradeSheet::query()->sole()->status);
        $this->assertSame('draft', $context['enrollments'][0]->fresh()->grade_status);

        $this->actingAs($context['lecturerUser'])->post(route('academic.grades.publish', $context['class']))->assertSessionHasNoErrors();
        $this->actingAs($prodi)->post(route('academic.grades.finalize', $context['class']))->assertSessionHasNoErrors();
        $this->assertSame('finalized', GradeSheet::query()->sole()->status);
        $this->assertSame('finalized', $context['enrollments'][0]->fresh()->grade_status);
        $this->actingAs($context['lecturerUser'])->patch(route('academic.grades.components.update', [$context['class'], GradeComponent::query()->first()]), $this->componentPayload('UTS', 50))->assertSessionHasErrors('grade_sheet');
    }

    public function test_draft_grades_are_hidden_from_student_portal(): void
    {
        $context = $this->context();
        [$uts, $uas] = $this->components($context['class']);
        $this->actingAs($context['lecturerUser'])->put(route('academic.grades.scores.update', [$context['class'], $context['enrollments'][0]]), ['scores' => [$uts->id => 90, $uas->id => 90]])->assertSessionHasNoErrors();
        $this->actingAs($context['studentUsers'][0])->get(route('academic.grades'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('khs', 0)->has('transcript', 0)->where('metrics.gpa', 0));
    }

    private function publishedContext(): array
    {
        $context = $this->context();
        [$uts, $uas] = $this->components($context['class']);
        foreach ($context['enrollments'] as $enrollment) {
            $this->actingAs($context['lecturerUser'])->put(route('academic.grades.scores.update', [$context['class'], $enrollment]), ['scores' => [$uts->id => 80, $uas->id => 80]])->assertSessionHasNoErrors();
        }
        $this->post(route('academic.grades.publish', $context['class']))->assertSessionHasNoErrors();

        return $context;
    }

    private function context(): array
    {
        $program = Program::create(['name' => 'Teknik Informatika', 'code' => 'TI', 'degree' => 'S1', 'is_active' => true]);
        $term = AcademicTerm::create(['name' => 'Ganjil 2026', 'code' => '2026-GANJIL', 'semester' => 'Ganjil', 'is_active' => true]);
        $period = AcademicRegistrationPeriod::create(['academic_term_id' => $term->id, 'starts_at' => now()->subMonth(), 'ends_at' => now()->subWeek(), 'default_max_credits' => 24, 'is_open' => false]);
        $course = Course::create(['program_id' => $program->id, 'name' => 'Algoritma', 'code' => 'TI101', 'credits' => 3, 'type' => 'Wajib', 'is_active' => true]);
        $lecturerUser = $this->permissionUser('Dosen', 'grades.view', 'grades.create', 'grades.update', 'grades.delete');
        $lecturer = Lecturer::create(['user_id' => $lecturerUser->id, 'program_id' => $program->id, 'name' => 'Dosen Algoritma', 'nidn' => 'NIDN-001', 'employment_status' => 'Tetap']);
        $class = ClassGroup::create(['academic_term_id' => $term->id, 'course_id' => $course->id, 'lecturer_id' => $lecturer->id, 'name' => 'A', 'capacity' => 30, 'enrolled_count' => 2, 'day' => 'Senin', 'starts_at' => '08:00', 'ends_at' => '09:40', 'is_active' => true]);
        $studentUsers = [];
        $registrations = [];
        $enrollments = [];
        foreach (['22001', '22002'] as $nim) {
            $studentUser = $this->permissionUser('Mahasiswa', 'grades.view', 'transcript.view');
            $student = Student::create(['user_id' => $studentUser->id, 'program_id' => $program->id, 'nim' => $nim, 'status' => 'Aktif', 'current_semester' => 1]);
            $registration = SemesterRegistration::create(['student_id' => $student->id, 'academic_term_id' => $term->id, 'academic_registration_period_id' => $period->id, 'max_credits' => 24, 'status' => 'approved']);
            $enrollment = CourseEnrollment::create(['semester_registration_id' => $registration->id, 'class_group_id' => $class->id, 'credits' => 3, 'status' => 'enrolled']);
            $studentUsers[] = $studentUser;
            $registrations[] = $registration;
            $enrollments[] = $enrollment;
        }

        return compact('program', 'term', 'period', 'course', 'lecturerUser', 'lecturer', 'class', 'studentUsers', 'registrations', 'enrollments');
    }

    private function components(ClassGroup $class): array
    {
        $this->actingAs($class->lecturer->user);
        $this->post(route('academic.grades.components.store', $class), $this->componentPayload('UTS', 50))->assertSessionHasNoErrors();
        $this->post(route('academic.grades.components.store', $class), $this->componentPayload('UAS', 50, 100, 2))->assertSessionHasNoErrors();

        return GradeComponent::query()->orderBy('id')->get()->all();
    }

    private function componentPayload(string $name, float $weight, float $max = 100, int $sort = 1): array
    {
        return ['name' => $name, 'weight' => $weight, 'max_score' => $max, 'sort_order' => $sort];
    }

    private function permissionUser(string $activeRole, string ...$permissions): User
    {
        $user = User::factory()->create(['active_role' => $activeRole]);
        foreach ($permissions as $permission) Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permissions);

        return $user;
    }
}
