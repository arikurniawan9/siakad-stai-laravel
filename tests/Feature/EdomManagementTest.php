<?php

namespace Tests\Feature;

use App\Models\AcademicRegistrationPeriod;
use App\Models\AcademicTerm;
use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\EdomQuestionnaire;
use App\Models\Lecturer;
use App\Models\Program;
use App\Models\SemesterRegistration;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class EdomManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_create_questionnaire_with_professional_default_instrument(): void
    {
        $context = $this->context(); $manager = $this->permissionUser('Prodi', 'edom.view', 'edom.update');
        $this->actingAs($manager)->post(route('academic.edom.questionnaires.store'), ['academic_term_id' => $context['term']->id, 'title' => 'EDOM Ganjil', 'description' => 'Evaluasi semester', 'starts_at' => now()->subDay()->toDateTimeString(), 'ends_at' => now()->addWeek()->toDateTimeString(), 'is_active' => 1, 'include_default_questions' => 1])->assertSessionHasNoErrors();
        $questionnaire = EdomQuestionnaire::query()->sole();
        $this->assertCount(8, $questionnaire->questions);
        $this->assertDatabaseHas('audit_logs', ['module' => 'edom', 'action' => 'questionnaire_created']);
    }

    public function test_student_can_submit_once_only_for_own_enrolled_class(): void
    {
        $context = $this->context(); $questionnaire = $this->questionnaire($context);
        $payload = ['answers' => [(string) $questionnaire->questions[0]->id => ['rating' => 5], (string) $questionnaire->questions[1]->id => ['essay_answer' => 'Penjelasan sangat jelas']], 'suggestion' => 'Pertahankan diskusi.'];
        $this->actingAs($context['studentUsers'][0])->post(route('academic.edom.submit', [$questionnaire, $context['class']]), $payload)->assertSessionHasNoErrors();
        $this->post(route('academic.edom.submit', [$questionnaire, $context['class']]), $payload)->assertSessionHasErrors('questionnaire');
        $this->assertDatabaseHas('edom_responses', ['student_id' => $context['students'][0]->id, 'class_group_id' => $context['class']->id, 'average_score' => 5]);

        $other = ClassGroup::create(['academic_term_id' => $context['term']->id, 'course_id' => $context['course']->id, 'lecturer_id' => $context['lecturer']->id, 'name' => 'Z', 'capacity' => 30, 'day' => 'Jumat', 'starts_at' => '08:00', 'ends_at' => '09:00', 'is_active' => true]);
        $this->actingAs($context['studentUsers'][1])->post(route('academic.edom.submit', [$questionnaire, $other]), $payload)->assertForbidden();
    }

    public function test_results_are_anonymous_and_comments_are_protected_until_threshold(): void
    {
        $context = $this->context(3); $questionnaire = $this->questionnaire($context);
        $ratingId = $questionnaire->questions[0]->id; $essayId = $questionnaire->questions[1]->id;
        foreach ($context['studentUsers'] as $index => $user) {
            $payload = ['answers' => [(string) $ratingId => ['rating' => 4 + ($index % 2)], (string) $essayId => ['essay_answer' => 'Respons anonim']], 'suggestion' => "Saran anonim {$index}"];
            $this->actingAs($user)->post(route('academic.edom.submit', [$questionnaire, $context['class']]), $payload)->assertSessionHasNoErrors();
            if ($index === 0) {
                $this->actingAs($context['lecturerUser'])->get(route('academic.edom', ['selected' => $questionnaire->id]))->assertInertia(fn (Assert $page) => $page->where('results.0.privacy_protected', true)->has('results.0.suggestions', 0));
            }
        }
        $this->actingAs($context['lecturerUser'])->get(route('academic.edom', ['selected' => $questionnaire->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Academic/Edom')->where('mode', 'lecturer')->where('results.0.response_count', 3)->where('results.0.privacy_protected', false)->has('results.0.suggestions', 3)->missing('results.0.student'));
    }

    private function questionnaire(array $context): EdomQuestionnaire
    {
        $questionnaire = EdomQuestionnaire::create(['academic_term_id' => $context['term']->id, 'title' => 'EDOM Aktif', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'is_active' => true]);
        $questionnaire->questions()->createMany([
            ['category' => 'Pengajaran', 'question' => 'Dosen menjelaskan dengan jelas.', 'type' => 'rating', 'sort_order' => 1, 'is_required' => true],
            ['category' => 'Refleksi', 'question' => 'Berikan refleksi.', 'type' => 'essay', 'sort_order' => 2, 'is_required' => false],
        ]);
        return $questionnaire->load('questions');
    }

    private function context(int $studentCount = 2): array
    {
        $program = Program::create(['name' => 'Teknik Informatika', 'code' => 'TI', 'degree' => 'S1', 'is_active' => true]);
        $term = AcademicTerm::create(['name' => 'Ganjil 2026', 'code' => '2026-GANJIL', 'semester' => 'Ganjil', 'is_active' => true]);
        $period = AcademicRegistrationPeriod::create(['academic_term_id' => $term->id, 'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth(), 'default_max_credits' => 24, 'is_open' => true]);
        $course = Course::create(['program_id' => $program->id, 'name' => 'Algoritma', 'code' => 'TI101', 'credits' => 3, 'type' => 'Wajib', 'is_active' => true]);
        $lecturerUser = $this->permissionUser('Dosen', 'edom.view');
        $lecturer = Lecturer::create(['user_id' => $lecturerUser->id, 'program_id' => $program->id, 'name' => 'Dosen Algoritma', 'nidn' => 'NIDN-001', 'employment_status' => 'Tetap']);
        $class = ClassGroup::create(['academic_term_id' => $term->id, 'course_id' => $course->id, 'lecturer_id' => $lecturer->id, 'name' => 'A', 'capacity' => 30, 'enrolled_count' => $studentCount, 'day' => 'Senin', 'starts_at' => '08:00', 'ends_at' => '09:40', 'is_active' => true]);
        $studentUsers = []; $students = [];
        foreach (range(1, $studentCount) as $index) {
            $user = $this->permissionUser('Mahasiswa', 'edom.view', 'edom.create');
            $student = Student::create(['user_id' => $user->id, 'program_id' => $program->id, 'nim' => '2200'.$index, 'status' => 'Aktif', 'current_semester' => 1]);
            $registration = SemesterRegistration::create(['student_id' => $student->id, 'academic_term_id' => $term->id, 'academic_registration_period_id' => $period->id, 'max_credits' => 24, 'status' => 'approved']);
            CourseEnrollment::create(['semester_registration_id' => $registration->id, 'class_group_id' => $class->id, 'credits' => 3, 'status' => 'enrolled']);
            $studentUsers[] = $user; $students[] = $student;
        }
        return compact('program', 'term', 'period', 'course', 'lecturerUser', 'lecturer', 'class', 'studentUsers', 'students');
    }

    private function permissionUser(string $role, string ...$permissions): User
    {
        $user = User::factory()->create(['active_role' => $role]); foreach ($permissions as $permission) Permission::findOrCreate($permission, 'web'); $user->givePermissionTo($permissions); return $user;
    }
}
