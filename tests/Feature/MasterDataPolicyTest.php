<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Campus;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class MasterDataPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_core_resource_policy_maps_all_abilities_to_its_permissions(): void
    {
        $actor = User::factory()->create();
        foreach ($this->resources() as $resource => [$class, $model]) {
            $gate = Gate::forUser($actor);
            $this->assertFalse($gate->allows('viewAny', $class), "{$resource}.view tidak boleh lolos tanpa permission.");
            $this->assertFalse($gate->allows('create', $class), "{$resource}.create tidak boleh lolos tanpa permission.");
            $this->assertFalse($gate->allows('update', $model), "{$resource}.update tidak boleh lolos tanpa permission.");
            $this->assertFalse($gate->allows('delete', $model), "{$resource}.delete tidak boleh lolos tanpa permission.");
            $this->assertFalse($gate->allows('restore', $model), "{$resource}.restore tidak boleh lolos tanpa permission.");

            $this->grant($actor, "{$resource}.view");
            $this->assertTrue($gate->allows('viewAny', $class));
            $this->grant($actor, "{$resource}.create");
            $this->assertTrue($gate->allows('create', $class));
            $this->grant($actor, "{$resource}.update");
            $this->assertTrue($gate->allows('update', $model));
            $this->assertTrue($gate->allows('restore', $model));
            $this->grant($actor, "{$resource}.delete");
            $this->assertTrue($gate->allows('delete', $model));
        }
    }

    public function test_combined_workspace_requires_view_permission_for_every_exposed_resource(): void
    {
        $partial = $this->userWithPermissions('courses.view');
        $this->actingAs($partial)->get(route('admin.master-data'))->assertForbidden();

        $complete = $this->userWithPermissions(...$this->workspacePermissions());
        $this->actingAs($complete)->get(route('admin.master-data'))->assertOk();
    }

    public function test_mutation_permission_cannot_be_reused_across_resources(): void
    {
        $actor = $this->userWithPermissions('campuses.create');
        $campusPayload = ['name' => 'Kampus Utama', 'code' => 'KU', 'address' => '', 'is_active' => true];
        $facultyPayload = ['campus_id' => null, 'name' => 'Fakultas Teknologi', 'code' => 'FTI'];

        $this->actingAs($actor)->post(route('admin.master-data.store', 'faculties'), $facultyPayload)->assertForbidden();
        $this->post(route('admin.master-data.store', 'campuses'), $campusPayload)->assertSessionHasNoErrors();
        $campus = Campus::query()->where('code', 'KU')->sole();

        $this->patch(route('admin.master-data.update', ['resource' => 'campuses', 'id' => $campus->id]), [...$campusPayload, 'name' => 'Tidak Boleh'])->assertForbidden();
        $this->delete(route('admin.master-data.destroy', ['resource' => 'campuses', 'id' => $campus->id]))->assertForbidden();
        $this->assertSame('Kampus Utama', $campus->fresh()->name);
        $this->assertNotSoftDeleted($campus);

        $this->grant($actor, 'campuses.update');
        $this->patch(route('admin.master-data.update', ['resource' => 'campuses', 'id' => $campus->id]), [...$campusPayload, 'name' => 'Kampus Diperbarui'])->assertSessionHasNoErrors();
        $this->assertSame('Kampus Diperbarui', $campus->fresh()->name);
        $this->grant($actor, 'campuses.delete');
        $this->delete(route('admin.master-data.destroy', ['resource' => 'campuses', 'id' => $campus->id]))->assertSessionHasNoErrors();
        $this->assertSoftDeleted($campus);
    }

    private function resources(): array
    {
        $campus = Campus::create(['name' => 'Kampus Policy', 'code' => 'KP', 'is_active' => true]);
        $faculty = Faculty::create(['campus_id' => $campus->id, 'name' => 'Fakultas Policy', 'code' => 'FP']);
        $program = Program::create(['faculty_id' => $faculty->id, 'name' => 'Program Policy', 'code' => 'PP', 'degree' => 'S1', 'is_active' => true]);
        $term = AcademicTerm::create(['name' => 'Periode Policy', 'code' => '2026-POLICY', 'semester' => 'Ganjil', 'is_active' => false]);
        $course = Course::create(['program_id' => $program->id, 'name' => 'Mata Kuliah Policy', 'code' => 'MKP', 'credits' => 3, 'type' => 'Wajib', 'is_active' => true]);

        return [
            'campuses' => [Campus::class, $campus],
            'faculties' => [Faculty::class, $faculty],
            'programs' => [Program::class, $program],
            'academic_terms' => [AcademicTerm::class, $term],
            'courses' => [Course::class, $course],
        ];
    }

    private function workspacePermissions(): array
    {
        return ['campuses.view', 'faculties.view', 'programs.view', 'academic_terms.view', 'courses.view'];
    }

    private function userWithPermissions(string ...$permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) $this->grant($user, $permission);

        return $user;
    }

    private function grant(User $user, string $permission): void
    {
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
    }
}
