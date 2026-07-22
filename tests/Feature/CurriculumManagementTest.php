<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\CurriculumCourse;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CurriculumManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_permission_cannot_open_curriculum_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.curricula'))
            ->assertForbidden();
    }

    public function test_authorized_user_can_create_curriculum_and_mutation_is_audited(): void
    {
        $user = $this->userWithPermissions('curricula.create');
        $program = $this->program('TI');

        $this->actingAs($user)
            ->post(route('admin.curricula.store'), $this->curriculumPayload($program, code: 'kur-2026'))
            ->assertRedirect();

        $this->assertDatabaseHas('curricula', [
            'program_id' => $program->id,
            'code' => 'KUR-2026',
            'target_credits' => 144,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'module' => 'curricula',
            'action' => 'created',
            'record_type' => 'curriculum',
        ]);
    }

    public function test_authorized_user_can_open_curriculum_workspace(): void
    {
        $user = $this->userWithPermissions('curricula.view');
        $curriculum = $this->curriculum($this->program('TI'));

        $this->actingAs($user)
            ->get(route('admin.curricula', ['selected' => $curriculum->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Curricula')
                ->where('selectedCurriculum.id', $curriculum->id)
                ->has('curricula.data', 1)
                ->has('programOptions', 1));
    }

    public function test_only_one_curriculum_can_be_active_per_program(): void
    {
        $user = $this->userWithPermissions('curricula.create');
        $program = $this->program('TI');

        $this->actingAs($user)->post(route('admin.curricula.store'), $this->curriculumPayload($program, code: 'KUR-2024'));
        $first = Curriculum::query()->where('code', 'KUR-2024')->sole();
        $this->actingAs($user)->post(route('admin.curricula.store'), $this->curriculumPayload($program, code: 'KUR-2026'));

        $this->assertFalse($first->fresh()->is_active);
        $this->assertTrue(Curriculum::query()->where('code', 'KUR-2026')->sole()->is_active);
        $this->assertSame(1, Curriculum::query()->where('program_id', $program->id)->where('is_active', true)->count());
    }

    public function test_curriculum_code_is_unique_inside_program_but_reusable_by_another_program(): void
    {
        $user = $this->userWithPermissions('curricula.create');
        $firstProgram = $this->program('TI');
        $secondProgram = $this->program('SI');

        $this->actingAs($user)->post(route('admin.curricula.store'), $this->curriculumPayload($firstProgram));
        $this->actingAs($user)
            ->post(route('admin.curricula.store'), $this->curriculumPayload($firstProgram))
            ->assertSessionHasErrors('code');
        $this->actingAs($user)
            ->post(route('admin.curricula.store'), $this->curriculumPayload($secondProgram))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('curricula', 2);
    }

    public function test_only_courses_from_same_program_can_be_attached(): void
    {
        $user = $this->userWithPermissions('curricula.update');
        $program = $this->program('TI');
        $otherProgram = $this->program('SI');
        $curriculum = $this->curriculum($program);
        $otherCourse = $this->course($otherProgram, 'SI101');

        $this->actingAs($user)
            ->post(route('admin.curricula.courses.store', $curriculum), [
                'course_id' => $otherCourse->id,
                'semester' => 1,
                'credits' => 3,
                'is_required' => true,
            ])
            ->assertSessionHasErrors('course_id');

        $this->assertDatabaseCount('curriculum_courses', 0);
    }

    public function test_course_can_only_appear_once_in_curriculum(): void
    {
        $user = $this->userWithPermissions('curricula.update');
        $program = $this->program('TI');
        $curriculum = $this->curriculum($program);
        $course = $this->course($program, 'TI101');
        $payload = ['course_id' => $course->id, 'semester' => 1, 'credits' => 3, 'is_required' => true];

        $this->actingAs($user)->post(route('admin.curricula.courses.store', $curriculum), $payload)->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('admin.curricula.courses.store', $curriculum), $payload)->assertSessionHasErrors('course_id');

        $this->assertDatabaseCount('curriculum_courses', 1);
    }

    public function test_program_cannot_change_after_curriculum_has_courses(): void
    {
        $user = $this->userWithPermissions('curricula.update');
        $program = $this->program('TI');
        $otherProgram = $this->program('SI');
        $curriculum = $this->curriculum($program);
        $course = $this->course($program, 'TI101');
        CurriculumCourse::create(['curriculum_id' => $curriculum->id, 'course_id' => $course->id, 'semester' => 1, 'credits' => 3]);

        $this->actingAs($user)
            ->patch(route('admin.curricula.update', $curriculum), $this->curriculumPayload($otherProgram))
            ->assertSessionHasErrors('program_id');

        $this->assertSame($program->id, $curriculum->fresh()->program_id);
    }

    public function test_prerequisites_must_use_same_program_and_cannot_form_cycle(): void
    {
        $user = $this->userWithPermissions('curricula.update');
        $program = $this->program('TI');
        $otherProgram = $this->program('SI');
        $courseA = $this->course($program, 'TI201');
        $courseB = $this->course($program, 'TI101');
        $otherCourse = $this->course($otherProgram, 'SI101');

        $this->actingAs($user)
            ->post(route('admin.course-prerequisites.store'), ['course_id' => $courseA->id, 'prerequisite_course_id' => $otherCourse->id, 'minimum_grade' => 'C'])
            ->assertSessionHasErrors('prerequisite_course_id');

        $this->actingAs($user)
            ->post(route('admin.course-prerequisites.store'), ['course_id' => $courseA->id, 'prerequisite_course_id' => $courseB->id, 'minimum_grade' => 'C'])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('admin.course-prerequisites.store'), ['course_id' => $courseB->id, 'prerequisite_course_id' => $courseA->id, 'minimum_grade' => 'C'])
            ->assertSessionHasErrors('prerequisite_course_id');

        $this->assertDatabaseCount('course_prerequisites', 1);
    }

    public function test_curriculum_can_be_archived_and_restored_as_inactive(): void
    {
        $user = $this->userWithPermissions('curricula.delete', 'curricula.update');
        $curriculum = $this->curriculum($this->program('TI'));

        $this->actingAs($user)->delete(route('admin.curricula.destroy', $curriculum))->assertRedirect();
        $this->assertSoftDeleted($curriculum);

        $this->actingAs($user)->patch(route('admin.curricula.restore', $curriculum->id))->assertRedirect();

        $this->assertNotSoftDeleted($curriculum);
        $this->assertFalse($curriculum->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['record_type' => 'curriculum', 'record_id' => (string) $curriculum->id, 'action' => 'archived']);
        $this->assertDatabaseHas('audit_logs', ['record_type' => 'curriculum', 'record_id' => (string) $curriculum->id, 'action' => 'restored']);
    }

    private function userWithPermissions(string ...$permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function program(string $code): Program
    {
        return Program::create(['name' => "Program {$code}", 'code' => $code, 'degree' => 'S1', 'is_active' => true]);
    }

    private function course(Program $program, string $code): Course
    {
        return Course::create(['program_id' => $program->id, 'name' => "Mata Kuliah {$code}", 'code' => $code, 'credits' => 3, 'type' => 'Wajib', 'is_active' => true]);
    }

    private function curriculum(Program $program): Curriculum
    {
        return Curriculum::create(['program_id' => $program->id, 'name' => 'Kurikulum 2026', 'code' => 'KUR-2026', 'target_credits' => 144, 'is_active' => true]);
    }

    private function curriculumPayload(Program $program, string $code = 'KUR-2026'): array
    {
        return [
            'program_id' => $program->id,
            'effective_term_id' => null,
            'name' => 'Kurikulum 2026',
            'code' => $code,
            'target_credits' => 144,
            'description' => 'Kurikulum uji',
            'is_active' => true,
        ];
    }
}
