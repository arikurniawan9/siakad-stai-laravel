<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Lecturer;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StudentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_permission_cannot_open_student_workspace(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.students'))->assertForbidden();
    }

    public function test_authorized_user_can_open_student_workspace(): void
    {
        $actor = $this->userWithPermissions('students.view');
        $student = $this->student();

        $this->actingAs($actor)->get(route('admin.students', ['selected' => $student->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Students')->where('selectedStudent.id', $student->id)->has('students.data', 1));
    }

    public function test_authorized_user_can_create_student_with_initial_history_role_and_audit(): void
    {
        $actor = $this->userWithPermissions('students.create');
        $program = $this->program('TI');
        $account = User::factory()->create();

        $this->actingAs($actor)->post(route('admin.students.store'), $this->payload($account, $program))->assertSessionHasNoErrors();

        $student = Student::query()->sole();
        $this->assertSame('TI2026001', $student->nim);
        $this->assertSame('Aktif', $student->status);
        $this->assertTrue($account->fresh()->hasRole('Mahasiswa'));
        $this->assertDatabaseHas('student_status_histories', ['student_id' => $student->id, 'from_status' => null, 'to_status' => 'Aktif']);
        $this->assertDatabaseHas('audit_logs', ['module' => 'students', 'action' => 'created', 'record_id' => (string) $student->id]);
    }

    public function test_nim_and_user_account_must_be_unique(): void
    {
        $actor = $this->userWithPermissions('students.create');
        $program = $this->program('TI');
        $account = User::factory()->create();
        $this->actingAs($actor)->post(route('admin.students.store'), $this->payload($account, $program));

        $this->actingAs($actor)->post(route('admin.students.store'), $this->payload($account, $program))->assertSessionHasErrors(['nim', 'user_id']);
        $this->assertDatabaseCount('students', 1);
    }

    public function test_academic_advisor_must_come_from_same_program(): void
    {
        $actor = $this->userWithPermissions('students.create');
        $program = $this->program('TI');
        $otherProgram = $this->program('SI');
        $advisor = Lecturer::create(['program_id' => $otherProgram->id, 'name' => 'Dosen SI', 'nidn' => '9988', 'employment_status' => 'Tetap']);

        $this->actingAs($actor)->post(route('admin.students.store'), $this->payload(User::factory()->create(), $program, ['academic_advisor_id' => $advisor->id]))->assertSessionHasErrors('academic_advisor_id');
        $this->assertDatabaseCount('students', 0);
    }

    public function test_valid_status_transition_is_recorded_and_audited(): void
    {
        $actor = $this->userWithPermissions('students.update');
        $student = $this->student();
        $term = AcademicTerm::create(['name' => 'Ganjil 2026', 'code' => '2026-GANJIL', 'semester' => 'Ganjil']);

        $this->actingAs($actor)->post(route('admin.students.status', $student), ['status' => 'Cuti', 'academic_term_id' => $term->id, 'effective_on' => '2026-09-01', 'reason' => 'Pengajuan cuti disetujui'])->assertSessionHasNoErrors();

        $this->assertSame('Cuti', $student->fresh()->status);
        $this->assertDatabaseHas('student_status_histories', ['student_id' => $student->id, 'from_status' => 'Aktif', 'to_status' => 'Cuti', 'academic_term_id' => $term->id]);
        $this->assertDatabaseHas('audit_logs', ['module' => 'students', 'action' => 'status_changed']);
    }

    public function test_invalid_status_transition_is_rejected(): void
    {
        $actor = $this->userWithPermissions('students.update');
        $student = $this->student();

        $this->actingAs($actor)->post(route('admin.students.status', $student), ['status' => 'Aktif', 'effective_on' => '2026-09-01', 'reason' => 'Tidak ada perubahan status'])->assertSessionHasErrors('status');
        $this->assertSame('Aktif', $student->fresh()->status);
        $this->assertDatabaseCount('student_status_histories', 0);
    }

    public function test_graduated_status_is_terminal(): void
    {
        $actor = $this->userWithPermissions('students.update');
        $student = $this->student(status: 'Lulus');

        $this->actingAs($actor)->post(route('admin.students.status', $student), ['status' => 'Aktif', 'effective_on' => '2026-09-01', 'reason' => 'Mencoba mengaktifkan kembali'])->assertSessionHasErrors('status');
        $this->assertSame('Lulus', $student->fresh()->status);
    }

    public function test_active_or_leave_student_cannot_be_archived(): void
    {
        $actor = $this->userWithPermissions('students.delete');
        $active = $this->student();

        $this->actingAs($actor)->delete(route('admin.students.destroy', $active))->assertSessionHasErrors('student');
        $this->assertNotSoftDeleted($active);
    }

    public function test_nonactive_student_can_be_archived_and_restored(): void
    {
        $actor = $this->userWithPermissions('students.delete', 'students.update');
        $student = $this->student(status: 'Nonaktif');

        $this->actingAs($actor)->delete(route('admin.students.destroy', $student))->assertRedirect();
        $this->assertSoftDeleted($student);
        $this->actingAs($actor)->patch(route('admin.students.restore', $student->id))->assertRedirect();
        $this->assertNotSoftDeleted($student);
        $this->assertDatabaseHas('audit_logs', ['module' => 'students', 'action' => 'archived']);
        $this->assertDatabaseHas('audit_logs', ['module' => 'students', 'action' => 'restored']);
    }

    private function userWithPermissions(string ...$permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permissions);
        return $user;
    }

    private function program(string $code): Program
    {
        return Program::create(['name' => "Program {$code}", 'code' => $code, 'degree' => 'S1', 'is_active' => true]);
    }

    private function student(string $status = 'Aktif'): Student
    {
        return Student::create(['user_id' => User::factory()->create()->id, 'program_id' => $this->program('TI')->id, 'nim' => 'TI2026001', 'cohort_year' => 2026, 'registration_type' => 'Reguler', 'status' => $status, 'current_semester' => 1]);
    }

    private function payload(User $account, Program $program, array $overrides = []): array
    {
        return [...['user_id' => $account->id, 'program_id' => $program->id, 'academic_advisor_id' => null, 'admission_term_id' => null, 'nim' => 'ti2026001', 'cohort_year' => 2026, 'registration_type' => 'Reguler', 'gender' => 'L', 'birth_date' => '2007-01-01', 'phone' => '08123456789', 'address' => 'Alamat mahasiswa', 'current_semester' => 1], ...$overrides];
    }
}
