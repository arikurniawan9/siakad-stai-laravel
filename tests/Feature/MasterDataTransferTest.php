<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class MasterDataTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_and_export_require_resource_permissions(): void
    {
        $actor = User::factory()->create();
        $file = UploadedFile::fake()->createWithContent('campuses.csv', "code,name,address,is_active\nKMP-1,Kampus Satu,,1\n");

        $this->actingAs($actor)->post(route('admin.master-data.import.preview', 'campuses'), ['file' => $file])->assertForbidden();
        $this->actingAs($actor)->get(route('admin.master-data.export', 'campuses'))->assertForbidden();
    }

    public function test_valid_preview_can_be_confirmed_as_idempotent_upsert_with_audit(): void
    {
        $actor = $this->userWithPermissions('campuses.create', 'campuses.update', ...$this->workspacePermissions());
        $existing = Campus::create(['name' => 'Nama Lama', 'code' => 'KMP-1', 'address' => null, 'is_active' => true]);
        $csv = "code,name,address,is_active\nKMP-1,Kampus Diperbarui,Jalan Satu,1\nKMP-2,Kampus Baru,Jalan Dua,0\n";

        $response = $this->actingAs($actor)->post(route('admin.master-data.import.preview', 'campuses'), [
            'file' => UploadedFile::fake()->createWithContent('campuses.csv', $csv),
        ])->assertSessionHasNoErrors();
        $location = (string) $response->headers->get('Location');
        $token = $this->tokenFromLocation($location);

        $this->get($location)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/MasterData')
            ->where('importPreview.total_rows', 2)
            ->where('importPreview.valid_rows', 2)
            ->where('importPreview.error_rows', 0)
            ->where('importPreview.rows.0.action', 'update')
            ->where('importPreview.rows.1.action', 'create'));

        $this->post(route('admin.master-data.import.confirm', $token))->assertSessionHasNoErrors()->assertRedirect(route('admin.master-data'));
        $this->assertDatabaseCount('campuses', 2);
        $this->assertSame('Kampus Diperbarui', $existing->fresh()->name);
        $this->assertFalse(Campus::query()->where('code', 'KMP-2')->sole()->is_active);
        $this->assertDatabaseHas('audit_logs', ['module' => 'master_data', 'action' => 'imported', 'record_type' => 'campuses', 'record_id' => $token]);
        $this->assertNull(session("master_data_imports.{$token}"));
    }

    public function test_preview_reports_reference_and_row_errors_and_blocks_confirmation(): void
    {
        $actor = $this->userWithPermissions('faculties.create', 'faculties.update', ...$this->workspacePermissions());
        $csv = "code,name,campus_code\nFTI,Fakultas Teknologi,TIDAK-ADA\n,Fakultas Tanpa Kode,\n";
        $response = $this->actingAs($actor)->post(route('admin.master-data.import.preview', 'faculties'), [
            'file' => UploadedFile::fake()->createWithContent('faculties.csv', $csv),
        ])->assertSessionHasNoErrors();
        $location = (string) $response->headers->get('Location');
        $token = $this->tokenFromLocation($location);

        $this->get($location)->assertInertia(fn (Assert $page) => $page
            ->where('importPreview.error_rows', 2)
            ->has('importPreview.rows.0.errors.campus_code')
            ->has('importPreview.rows.1.errors.code'));
        $this->post(route('admin.master-data.import.confirm', $token))->assertSessionHasErrors('import');
        $this->assertDatabaseCount('faculties', 0);
        $this->assertNotNull(session("master_data_imports.{$token}"));
    }

    public function test_duplicate_codes_and_multiple_active_terms_are_reported(): void
    {
        $actor = $this->userWithPermissions('academic_terms.create', 'academic_terms.update', ...$this->workspacePermissions());
        $csv = "code,name,semester,starts_on,ends_on,is_active\n2027-GANJIL,Ganjil A,Ganjil,2027-08-01,2028-01-31,1\n2027-GANJIL,Ganjil B,Ganjil,2027-08-01,2028-01-31,1\n";

        $response = $this->actingAs($actor)->post(route('admin.master-data.import.preview', 'academic-terms'), [
            'file' => UploadedFile::fake()->createWithContent('terms.csv', $csv),
        ])->assertSessionHasNoErrors();

        $this->get((string) $response->headers->get('Location'))->assertInertia(fn (Assert $page) => $page
            ->where('importPreview.error_rows', 2)
            ->has('importPreview.rows.0.errors.is_active')
            ->has('importPreview.rows.1.errors.code')
            ->has('importPreview.rows.1.errors.is_active'));
    }

    public function test_archived_code_cannot_be_silently_recreated(): void
    {
        $actor = $this->userWithPermissions('campuses.create', 'campuses.update', ...$this->workspacePermissions());
        $campus = Campus::create(['name' => 'Kampus Arsip', 'code' => 'ARSIP', 'is_active' => false]);
        $campus->delete();

        $response = $this->actingAs($actor)->post(route('admin.master-data.import.preview', 'campuses'), [
            'file' => UploadedFile::fake()->createWithContent('campuses.csv', "code,name,address,is_active\nARSIP,Kampus Baru,,1\n"),
        ])->assertSessionHasNoErrors();

        $this->get((string) $response->headers->get('Location'))->assertInertia(fn (Assert $page) => $page
            ->where('importPreview.error_rows', 1)
            ->has('importPreview.rows.0.errors.code'));
    }

    public function test_csv_header_and_row_limit_validation_are_enforced(): void
    {
        $actor = $this->userWithPermissions('campuses.create', 'campuses.update');
        $badHeader = UploadedFile::fake()->createWithContent('campuses.csv', "kode,nama\nKMP,Kampus\n");
        $this->actingAs($actor)->post(route('admin.master-data.import.preview', 'campuses'), ['file' => $badHeader])->assertSessionHasErrors('file');

        $rows = ["code,name,address,is_active"];
        for ($i = 1; $i <= 501; $i++) $rows[] = "KMP-{$i},Kampus {$i},,1";
        $tooMany = UploadedFile::fake()->createWithContent('campuses.csv', implode("\n", $rows));
        $this->post(route('admin.master-data.import.preview', 'campuses'), ['file' => $tooMany])->assertSessionHasErrors('file');
    }

    public function test_export_uses_reference_codes_and_is_audited(): void
    {
        $actor = $this->userWithPermissions('courses.view');
        $campus = Campus::create(['name' => 'Kampus Utama', 'code' => 'KMP-1', 'is_active' => true]);
        $faculty = Faculty::create(['campus_id' => $campus->id, 'name' => 'Teknologi', 'code' => 'FTI']);
        $program = Program::create(['faculty_id' => $faculty->id, 'name' => 'Informatika', 'code' => 'TI-S1', 'degree' => 'S1', 'is_active' => true]);
        Course::create(['program_id' => $program->id, 'name' => 'Pemrograman', 'code' => 'IF101', 'credits' => 3, 'type' => 'Wajib', 'is_active' => true]);

        $response = $this->actingAs($actor)->get(route('admin.master-data.export', 'courses'))->assertOk();
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFcode,name,credits,type,program_code,is_active", $content);
        $this->assertStringContainsString('IF101,Pemrograman,3,Wajib,TI-S1,1', $content);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $actor->id, 'module' => 'master_data', 'action' => 'exported', 'record_type' => 'courses']);
    }

    public function test_template_contains_example_and_preview_token_is_scoped_to_creator(): void
    {
        $actor = $this->userWithPermissions('campuses.view', 'campuses.create', 'campuses.update');
        $template = $this->actingAs($actor)->get(route('admin.master-data.template', 'campuses'))->assertOk()->streamedContent();
        $this->assertStringContainsString('STAI-02,"Kampus Cabang"', $template);

        $response = $this->post(route('admin.master-data.import.preview', 'campuses'), [
            'file' => UploadedFile::fake()->createWithContent('campuses.csv', "code,name,address,is_active\nKMP-9,Kampus Sembilan,,1\n"),
        ]);
        $token = $this->tokenFromLocation((string) $response->headers->get('Location'));

        $other = $this->userWithPermissions('campuses.create', 'campuses.update');
        $this->actingAs($other)->post(route('admin.master-data.import.confirm', $token))->assertNotFound();
        $this->assertDatabaseMissing('campuses', ['code' => 'KMP-9']);
    }

    private function userWithPermissions(string ...$permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function tokenFromLocation(string $location): string
    {
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('import', $query);

        return (string) $query['import'];
    }

    private function workspacePermissions(): array
    {
        return ['campuses.view', 'faculties.view', 'programs.view', 'academic_terms.view', 'courses.view'];
    }
}
