<?php

namespace Tests\Feature;

use App\Models\AcademicRegistrationPeriod;
use App\Models\AcademicTerm;
use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lecturer;
use App\Models\LmsAssignment;
use App\Models\LmsMaterial;
use App\Models\LmsSubmission;
use App\Models\Program;
use App\Models\SemesterRegistration;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class LmsManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_lecturer_can_manage_own_class_but_not_another_class(): void
    {
        $context = $this->context();
        $this->actingAs($context['lecturerUser'])->get(route('academic.lms', ['selected' => $context['class']->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Academic/Lms')->where('selectedClass.id', $context['class']->id)->where('abilities.manage', true));

        $other = $this->otherClass($context);
        $this->post(route('academic.lms.materials.store', $other), ['title' => 'Rahasia', 'is_published' => '1'])->assertForbidden();
        $this->assertDatabaseMissing('lms_materials', ['title' => 'Rahasia']);
    }

    public function test_material_attachment_is_private_and_draft_is_hidden_from_students(): void
    {
        Storage::fake('local');
        $context = $this->context();
        $this->actingAs($context['lecturerUser'])->post(route('academic.lms.materials.store', $context['class']), ['title' => 'Modul Aman', 'description' => 'Materi inti', 'is_published' => '1', 'attachment' => UploadedFile::fake()->create('modul.pdf', 100, 'application/pdf')])->assertSessionHasNoErrors();
        $this->post(route('academic.lms.materials.store', $context['class']), ['title' => 'Draf Dosen', 'is_published' => '0'])->assertSessionHasNoErrors();
        $material = LmsMaterial::query()->where('title', 'Modul Aman')->sole();
        Storage::disk('local')->assertExists($material->attachment_path);

        $this->actingAs($context['studentUsers'][0])->get(route('academic.lms', ['selected' => $context['class']->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('selectedClass.materials', 1)->where('selectedClass.materials.0.title', 'Modul Aman')->where('abilities.manage', false));
        $this->get(route('academic.lms.materials.attachment', [$context['class'], $material]))->assertDownload('modul.pdf');
    }

    public function test_student_submission_is_scoped_and_lecturer_can_grade_within_maximum(): void
    {
        $context = $this->context();
        $this->actingAs($context['lecturerUser'])->post(route('academic.lms.assignments.store', $context['class']), ['title' => 'Studi Kasus', 'due_at' => now()->addDay()->toDateTimeString(), 'max_points' => 100, 'is_published' => 1])->assertSessionHasNoErrors();
        $assignment = LmsAssignment::query()->sole();
        $this->actingAs($context['studentUsers'][0])->post(route('academic.lms.assignments.submit', [$context['class'], $assignment]), ['answer_text' => 'Analisis mahasiswa pertama'])->assertSessionHasNoErrors();
        $submission = LmsSubmission::query()->sole();
        $this->actingAs($context['lecturerUser'])->post(route('academic.lms.submissions.grade', [$context['class'], $assignment, $submission]), ['score' => 101, 'feedback' => 'Baik'])->assertSessionHasErrors('score');
        $this->post(route('academic.lms.submissions.grade', [$context['class'], $assignment, $submission]), ['score' => 92, 'feedback' => 'Analisis tajam'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('lms_submissions', ['id' => $submission->id, 'status' => 'graded', 'score' => 92, 'feedback' => 'Analisis tajam']);
        $this->actingAs($context['studentUsers'][0])->post(route('academic.lms.assignments.submit', [$context['class'], $assignment]), ['answer_text' => 'Ulang'])->assertSessionHasErrors('submission');
        $this->actingAs($context['studentUsers'][1])->get(route('academic.lms.submissions.attachment', [$context['class'], $assignment, $submission]))->assertForbidden();
    }

    public function test_forum_is_available_to_class_members_and_locked_topics_reject_comments(): void
    {
        $context = $this->context();
        $this->actingAs($context['studentUsers'][0])->post(route('academic.lms.topics.store', $context['class']), ['title' => 'Pertanyaan algoritma', 'content' => 'Bagaimana kompleksitasnya?'])->assertSessionHasNoErrors();
        $topic = $context['class']->forumTopics()->sole();
        $this->actingAs($context['lecturerUser'])->patch(route('academic.lms.topics.moderate', [$context['class'], $topic]), ['is_pinned' => true, 'is_locked' => true])->assertSessionHasNoErrors();
        $this->actingAs($context['studentUsers'][1])->post(route('academic.lms.comments.store', [$context['class'], $topic]), ['content' => 'Jawaban'])->assertStatus(422);
        $this->assertDatabaseCount('lms_forum_comments', 0);
    }

    private function context(): array
    {
        $program = Program::create(['name' => 'Teknik Informatika', 'code' => 'TI', 'degree' => 'S1', 'is_active' => true]);
        $term = AcademicTerm::create(['name' => 'Ganjil 2026', 'code' => '2026-GANJIL', 'semester' => 'Ganjil', 'is_active' => true]);
        $period = AcademicRegistrationPeriod::create(['academic_term_id' => $term->id, 'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth(), 'default_max_credits' => 24, 'is_open' => true]);
        $course = Course::create(['program_id' => $program->id, 'name' => 'Algoritma', 'code' => 'TI101', 'credits' => 3, 'type' => 'Wajib', 'is_active' => true]);
        $lecturerUser = $this->permissionUser('Dosen', 'lms.view', 'lms.create', 'lms.update', 'lms.delete');
        $lecturer = Lecturer::create(['user_id' => $lecturerUser->id, 'program_id' => $program->id, 'name' => 'Dosen Algoritma', 'nidn' => 'NIDN-001', 'employment_status' => 'Tetap']);
        $class = ClassGroup::create(['academic_term_id' => $term->id, 'course_id' => $course->id, 'lecturer_id' => $lecturer->id, 'name' => 'A', 'capacity' => 30, 'enrolled_count' => 2, 'day' => 'Senin', 'starts_at' => '08:00', 'ends_at' => '09:40', 'is_active' => true]);
        $studentUsers = []; $enrollments = [];
        foreach (['22001', '22002'] as $nim) {
            $studentUser = $this->permissionUser('Mahasiswa', 'lms.view');
            $student = Student::create(['user_id' => $studentUser->id, 'program_id' => $program->id, 'nim' => $nim, 'status' => 'Aktif', 'current_semester' => 1]);
            $registration = SemesterRegistration::create(['student_id' => $student->id, 'academic_term_id' => $term->id, 'academic_registration_period_id' => $period->id, 'max_credits' => 24, 'status' => 'approved']);
            $enrollments[] = CourseEnrollment::create(['semester_registration_id' => $registration->id, 'class_group_id' => $class->id, 'credits' => 3, 'status' => 'enrolled']);
            $studentUsers[] = $studentUser;
        }
        return compact('program', 'term', 'period', 'course', 'lecturerUser', 'lecturer', 'class', 'studentUsers', 'enrollments');
    }

    private function otherClass(array $context): ClassGroup
    {
        $user = $this->permissionUser('Dosen', 'lms.view', 'lms.update');
        $lecturer = Lecturer::create(['user_id' => $user->id, 'program_id' => $context['program']->id, 'name' => 'Dosen Lain', 'nidn' => 'NIDN-999', 'employment_status' => 'Tetap']);
        return ClassGroup::create(['academic_term_id' => $context['term']->id, 'course_id' => $context['course']->id, 'lecturer_id' => $lecturer->id, 'name' => 'B', 'capacity' => 30, 'day' => 'Selasa', 'starts_at' => '10:00', 'ends_at' => '11:40', 'is_active' => true]);
    }

    private function permissionUser(string $role, string ...$permissions): User
    {
        $user = User::factory()->create(['active_role' => $role]); foreach ($permissions as $permission) Permission::findOrCreate($permission, 'web'); $user->givePermissionTo($permissions); return $user;
    }
}
