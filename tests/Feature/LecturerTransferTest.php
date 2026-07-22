<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Building;
use App\Models\Campus;
use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\Lecturer;
use App\Models\Program;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LecturerTransferTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'nidn,name,program_code,user_email,employee_number,academic_title,employment_status,expertise,is_active';

    public function test_lecturer_transfer_requires_permissions(): void
    {
        $actor = User::factory()->create();
        $file = UploadedFile::fake()->createWithContent('lecturers.csv', self::HEADER."\n111,Dosen,TI-S1,,,Lektor,Tetap,,1\n");

        $this->actingAs($actor)->post(route('admin.lecturers.import.preview'), ['file' => $file])->assertForbidden();
        $this->get(route('admin.lecturers.export'))->assertForbidden();
    }

    public function test_lecturer_import_upserts_by_nidn_links_account_and_assigns_role(): void
    {
        $actor = $this->userWithPermissions('lecturers.create', 'lecturers.update', 'lecturers.view', 'schedules.view');
        $program = $this->program();
        $account = User::factory()->create(['email' => 'dosen@example.test', 'is_active' => true]);
        Lecturer::create(['program_id' => $program->id, 'name' => 'Nama Lama', 'nidn' => '111', 'employee_number' => 'PEG-1', 'employment_status' => 'Tetap']);
        $csv = self::HEADER."\n111,Nama Baru,TI-S1,,PEG-1,Lektor,Tetap,Software,1\n222,Dosen Dua,TI-S1,dosen@example.test,PEG-2,Asisten Ahli,Tidak Tetap,Data,1\n";

        $location = $this->preview($actor, $csv);
        $token = $this->tokenFromLocation($location);
        $this->get($location)->assertInertia(fn (Assert $page) => $page
            ->where('importPreview.error_rows', 0)
            ->where('importPreview.rows.0.action', 'update')
            ->where('importPreview.rows.1.action', 'create'));
        $this->post(route('admin.lecturers.import.confirm', $token))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('lecturers', 2);
        $this->assertDatabaseHas('lecturers', ['nidn' => '111', 'name' => 'Nama Baru']);
        $this->assertDatabaseHas('lecturers', ['nidn' => '222', 'user_id' => $account->id, 'program_id' => $program->id]);
        $this->assertTrue($account->fresh()->hasRole('Dosen'));
        $this->assertSame('Dosen', $account->fresh()->active_role);
        $this->assertDatabaseHas('audit_logs', ['module' => 'academic_schedules', 'action' => 'imported', 'record_type' => 'lecturer', 'record_id' => $token]);
    }

    public function test_preview_reports_invalid_program_account_and_duplicate_employee_number(): void
    {
        $actor = $this->userWithPermissions('lecturers.create', 'lecturers.update', 'lecturers.view', 'schedules.view');
        $program = $this->program();
        Lecturer::create(['program_id' => $program->id, 'name' => 'Dosen Lama', 'nidn' => '100', 'employee_number' => 'PEG-SAMA', 'employment_status' => 'Tetap']);
        $csv = self::HEADER."\n200,Dosen Baru,TIDAK-ADA,missing@example.test,PEG-SAMA,Lektor,Tetap,Software,1\n";

        $location = $this->preview($actor, $csv);
        $this->get($location)->assertInertia(fn (Assert $page) => $page
            ->where('importPreview.error_rows', 1)
            ->has('importPreview.rows.0.errors.program_code')
            ->has('importPreview.rows.0.errors.user_email')
            ->has('importPreview.rows.0.errors.employee_number'));
    }

    public function test_lecturer_export_contains_program_and_account_references_with_audit(): void
    {
        $actor = $this->userWithPermissions('lecturers.view');
        $program = $this->program();
        $account = User::factory()->create(['email' => 'dosen@example.test']);
        Lecturer::create(['program_id' => $program->id, 'user_id' => $account->id, 'name' => 'Dosen Satu', 'nidn' => '111', 'employee_number' => 'PEG-1', 'academic_title' => 'Lektor', 'employment_status' => 'Tetap', 'expertise' => 'Software', 'is_active' => true]);

        $content = $this->actingAs($actor)->get(route('admin.lecturers.export'))->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF".self::HEADER, $content);
        $this->assertStringContainsString('111,"Dosen Satu",TI-S1,dosen@example.test,PEG-1,Lektor,Tetap,Software,1', $content);
        $this->assertDatabaseHas('audit_logs', ['module' => 'academic_schedules', 'action' => 'exported', 'record_type' => 'lecturer']);
    }

    public function test_lecturers_can_be_bulk_archived_and_restored(): void
    {
        $actor = $this->userWithPermissions('lecturers.delete', 'lecturers.update');
        $program = $this->program();
        $lecturers = collect([1, 2])->map(fn (int $number) => Lecturer::create(['program_id' => $program->id, 'name' => "Dosen {$number}", 'nidn' => "10{$number}", 'employment_status' => 'Tetap']));
        $ids = $lecturers->pluck('id')->all();

        $this->actingAs($actor)->post(route('admin.lecturers.bulk'), ['action' => 'archive', 'ids' => $ids])->assertSessionHasNoErrors();
        foreach ($lecturers as $lecturer) $this->assertSoftDeleted($lecturer);
        $this->post(route('admin.lecturers.bulk'), ['action' => 'restore', 'ids' => $ids])->assertSessionHasNoErrors();
        foreach ($lecturers as $lecturer) $this->assertNotSoftDeleted($lecturer);
        $this->assertSame(2, \DB::table('audit_logs')->where(['module' => 'academic_schedules', 'record_type' => 'lecturer', 'action' => 'archived'])->count());
        $this->assertSame(2, \DB::table('audit_logs')->where(['module' => 'academic_schedules', 'record_type' => 'lecturer', 'action' => 'restored'])->count());
    }

    public function test_bulk_archive_is_atomic_when_one_lecturer_has_schedule(): void
    {
        $actor = $this->userWithPermissions('lecturers.delete');
        $context = $this->scheduleContext();
        $other = Lecturer::create(['program_id' => $context['program']->id, 'name' => 'Dosen Dua', 'nidn' => '222', 'employment_status' => 'Tetap']);
        ClassGroup::create(['academic_term_id' => $context['term']->id, 'course_id' => $context['course']->id, 'lecturer_id' => $context['lecturer']->id, 'room_id' => $context['room']->id, 'name' => 'A', 'capacity' => 30, 'day' => 'Senin', 'starts_at' => '08:00', 'ends_at' => '09:40']);

        $this->actingAs($actor)->post(route('admin.lecturers.bulk'), ['action' => 'archive', 'ids' => [$other->id, $context['lecturer']->id]])->assertSessionHasErrors('bulk');
        $this->assertNotSoftDeleted($other);
        $this->assertNotSoftDeleted($context['lecturer']);
    }

    public function test_restore_rejects_inactive_linked_account(): void
    {
        $actor = $this->userWithPermissions('lecturers.update');
        $program = $this->program();
        $account = User::factory()->create(['is_active' => false]);
        $lecturer = Lecturer::create(['program_id' => $program->id, 'user_id' => $account->id, 'name' => 'Dosen', 'nidn' => '111', 'employment_status' => 'Tetap']);
        $lecturer->delete();

        $this->actingAs($actor)->patch(route('admin.lecturers.restore', $lecturer->id))->assertSessionHasErrors('user');
        $this->assertSoftDeleted($lecturer);
    }

    private function preview(User $actor, string $csv): string
    {
        $response = $this->actingAs($actor)->post(route('admin.lecturers.import.preview'), [
            'file' => UploadedFile::fake()->createWithContent('lecturers.csv', $csv),
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

    private function program(): Program
    {
        return Program::firstOrCreate(['code' => 'TI-S1'], ['name' => 'Teknik Informatika', 'degree' => 'S1', 'is_active' => true]);
    }

    private function scheduleContext(): array
    {
        $program = $this->program();
        $course = Course::create(['program_id' => $program->id, 'name' => 'Algoritma', 'code' => 'IF101', 'credits' => 3, 'type' => 'Wajib']);
        $term = AcademicTerm::create(['name' => 'Ganjil', 'code' => '2026-GANJIL', 'semester' => 'Ganjil']);
        $lecturer = Lecturer::create(['program_id' => $program->id, 'name' => 'Dosen Satu', 'nidn' => '111', 'employment_status' => 'Tetap']);
        $campus = Campus::create(['name' => 'Kampus', 'code' => 'KU']);
        $building = Building::create(['campus_id' => $campus->id, 'name' => 'Gedung', 'code' => 'GD', 'floor_count' => 1]);
        $room = Room::create(['building_id' => $building->id, 'name' => 'Ruang', 'code' => 'R1', 'floor' => 1, 'type' => 'Kelas', 'capacity' => 30]);

        return compact('program', 'course', 'term', 'lecturer', 'room');
    }
}
