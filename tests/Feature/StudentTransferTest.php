<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Lecturer;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StudentTransferTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'nim,user_email,program_code,advisor_nidn,admission_term_code,cohort_year,registration_type,gender,birth_date,phone,address,current_semester,status';

    public function test_student_transfer_requires_permissions(): void
    {
        $actor = User::factory()->create();
        Permission::findOrCreate('students.view', 'web');
        $actor->givePermissionTo('students.view');
        $file = UploadedFile::fake()->createWithContent('students.csv', self::HEADER."\nTI001,mhs@example.test,TI-S1,,,2026,Reguler,L,2007-01-01,,,1,Aktif\n");

        $this->actingAs($actor)->post(route('admin.students.import.preview'), ['file' => $file])->assertForbidden();
        $this->get(route('admin.students.export'))->assertForbidden();
    }

    public function test_student_import_creates_profile_initial_history_and_role(): void
    {
        $actor = $this->userWithPermissions('students.create', 'students.update', 'students.view');
        $program = $this->program('TI-S1');
        $advisor = Lecturer::create(['program_id' => $program->id, 'name' => 'Dosen Wali', 'nidn' => '111', 'employment_status' => 'Tetap', 'is_active' => true]);
        AcademicTerm::create(['name' => 'Ganjil 2026', 'code' => '2026-GANJIL', 'semester' => 'Ganjil']);
        $account = User::factory()->create(['email' => 'mhs@example.test', 'is_active' => true]);
        $csv = self::HEADER."\nTI2026001,mhs@example.test,TI-S1,111,2026-GANJIL,2026,Reguler,L,2007-01-01,08123,Alamat,1,Aktif\n";

        $location = $this->preview($actor, $csv);
        $token = $this->tokenFromLocation($location);
        $this->get($location)->assertInertia(fn (Assert $page) => $page->where('importPreview.error_rows', 0)->where('importPreview.rows.0.action', 'create'));
        $this->post(route('admin.students.import.confirm', $token))->assertSessionHasNoErrors();

        $student = Student::query()->sole();
        $this->assertSame($account->id, $student->user_id);
        $this->assertSame($advisor->id, $student->academic_advisor_id);
        $this->assertSame('Aktif', $student->status);
        $this->assertTrue($account->fresh()->hasRole('Mahasiswa'));
        $this->assertSame('Mahasiswa', $account->fresh()->active_role);
        $this->assertDatabaseHas('student_status_histories', ['student_id' => $student->id, 'changed_by_user_id' => $actor->id, 'from_status' => null, 'to_status' => 'Aktif']);
        $this->assertDatabaseHas('audit_logs', ['module' => 'students', 'action' => 'imported', 'record_type' => 'student', 'record_id' => $token]);
    }

    public function test_import_can_update_profile_but_cannot_bypass_status_workflow(): void
    {
        $actor = $this->userWithPermissions('students.create', 'students.update', 'students.view');
        $program = $this->program('TI-S1');
        $account = User::factory()->create(['email' => 'mhs@example.test']);
        $student = Student::create(['user_id' => $account->id, 'program_id' => $program->id, 'nim' => 'TI001', 'cohort_year' => 2026, 'registration_type' => 'Reguler', 'status' => 'Aktif', 'current_semester' => 1]);

        $valid = $this->preview($actor, self::HEADER."\nTI001,mhs@example.test,TI-S1,,,2026,Reguler,L,2007-01-01,08999,Alamat baru,2,Aktif\n");
        $this->post(route('admin.students.import.confirm', $this->tokenFromLocation($valid)))->assertSessionHasNoErrors();
        $this->assertSame('08999', $student->fresh()->phone);
        $this->assertSame(2, $student->fresh()->current_semester);

        $invalid = $this->preview($actor, self::HEADER."\nTI001,mhs@example.test,TI-S1,,,2026,Reguler,L,2007-01-01,08999,Alamat baru,2,Lulus\n");
        $this->get($invalid)->assertInertia(fn (Assert $page) => $page->where('importPreview.error_rows', 1)->has('importPreview.rows.0.errors.status'));
        $this->assertSame('Aktif', $student->fresh()->status);
    }

    public function test_preview_reports_duplicate_account_and_advisor_from_other_program(): void
    {
        $actor = $this->userWithPermissions('students.create', 'students.update', 'students.view');
        $program = $this->program('TI-S1');
        $otherProgram = $this->program('SI-S1');
        $advisor = Lecturer::create(['program_id' => $otherProgram->id, 'name' => 'Dosen SI', 'nidn' => '999', 'employment_status' => 'Tetap', 'is_active' => true]);
        $account = User::factory()->create(['email' => 'used@example.test']);
        Student::create(['user_id' => $account->id, 'program_id' => $program->id, 'nim' => 'OLD001', 'cohort_year' => 2025, 'registration_type' => 'Reguler', 'status' => 'Aktif', 'current_semester' => 2]);

        $location = $this->preview($actor, self::HEADER."\nNEW001,used@example.test,TI-S1,{$advisor->nidn},,2026,Reguler,P,2007-01-01,,,1,Aktif\n");
        $this->get($location)->assertInertia(fn (Assert $page) => $page
            ->where('importPreview.error_rows', 1)
            ->has('importPreview.rows.0.errors.user_email')
            ->has('importPreview.rows.0.errors.advisor_nidn'));
    }

    public function test_student_export_contains_reference_codes_and_is_audited(): void
    {
        $actor = $this->userWithPermissions('students.export');
        $program = $this->program('TI-S1');
        $account = User::factory()->create(['email' => 'mhs@example.test']);
        Student::create(['user_id' => $account->id, 'program_id' => $program->id, 'nim' => 'TI001', 'cohort_year' => 2026, 'registration_type' => 'Reguler', 'gender' => 'L', 'current_semester' => 1, 'status' => 'Aktif']);

        $content = $this->actingAs($actor)->get(route('admin.students.export'))->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF".self::HEADER, $content);
        $this->assertStringContainsString('TI001,mhs@example.test,TI-S1,,,2026,Reguler,L', $content);
        $this->assertDatabaseHas('audit_logs', ['module' => 'students', 'action' => 'exported', 'record_type' => 'student']);
    }

    public function test_students_can_be_bulk_archived_and_restored(): void
    {
        $actor = $this->userWithPermissions('students.delete', 'students.update');
        $program = $this->program('TI-S1');
        $students = collect(['Lulus', 'Nonaktif'])->map(fn (string $status, int $index) => Student::create(['user_id' => User::factory()->create()->id, 'program_id' => $program->id, 'nim' => "TI00{$index}", 'cohort_year' => 2024, 'registration_type' => 'Reguler', 'status' => $status, 'current_semester' => 8]));
        $ids = $students->pluck('id')->all();

        $this->actingAs($actor)->post(route('admin.students.bulk'), ['action' => 'archive', 'ids' => $ids])->assertSessionHasNoErrors();
        foreach ($students as $student) $this->assertSoftDeleted($student);
        $this->post(route('admin.students.bulk'), ['action' => 'restore', 'ids' => $ids])->assertSessionHasNoErrors();
        foreach ($students as $student) $this->assertNotSoftDeleted($student);
        $this->assertSame(2, \DB::table('audit_logs')->where(['module' => 'students', 'action' => 'archived'])->count());
        $this->assertSame(2, \DB::table('audit_logs')->where(['module' => 'students', 'action' => 'restored'])->count());
    }

    public function test_bulk_archive_is_atomic_when_one_student_has_active_status(): void
    {
        $actor = $this->userWithPermissions('students.delete');
        $program = $this->program('TI-S1');
        $inactive = Student::create(['user_id' => User::factory()->create()->id, 'program_id' => $program->id, 'nim' => 'TI001', 'cohort_year' => 2024, 'registration_type' => 'Reguler', 'status' => 'Nonaktif', 'current_semester' => 2]);
        $active = Student::create(['user_id' => User::factory()->create()->id, 'program_id' => $program->id, 'nim' => 'TI002', 'cohort_year' => 2024, 'registration_type' => 'Reguler', 'status' => 'Aktif', 'current_semester' => 2]);

        $this->actingAs($actor)->post(route('admin.students.bulk'), ['action' => 'archive', 'ids' => [$inactive->id, $active->id]])->assertSessionHasErrors('bulk');
        $this->assertNotSoftDeleted($inactive);
        $this->assertNotSoftDeleted($active);
    }

    private function preview(User $actor, string $csv): string
    {
        $response = $this->actingAs($actor)->post(route('admin.students.import.preview'), [
            'file' => UploadedFile::fake()->createWithContent('students.csv', $csv),
        ])->assertSessionHasNoErrors();

        return (string) $response->headers->get('Location');
    }

    private function tokenFromLocation(string $location): string
    {
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('import', $query);

        return (string) $query['import'];
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
}
