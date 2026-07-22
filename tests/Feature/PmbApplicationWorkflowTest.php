<?php

namespace Tests\Feature;

use App\Models\PmbApplication;
use App\Models\PmbDocument;
use App\Models\PmbFee;
use App\Models\AcademicTerm;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PmbApplicationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_creates_a_draft_application_and_redirects_to_wizard(): void
    {
        Role::findOrCreate('Calon Mahasiswa', 'web');
        $program = $this->program();
        $captcha = 'ABC234';

        $response = $this->withSession(['siakad.captcha' => [
            'hash' => hash_hmac('sha256', $captcha, config('app.key')),
            'expires_at' => now()->addMinutes(5)->timestamp,
        ]])->post(route('pmb.register'), [
            'full_name' => 'Pendaftar Baru',
            'email' => 'pendaftar@example.test',
            'phone' => '081234567890',
            'program_id' => $program->id,
            'password' => 'kata-sandi-aman-12',
            'password_confirmation' => 'kata-sandi-aman-12',
            'captcha' => $captcha,
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect(route('pmb.application'));
        $application = PmbApplication::query()->sole();
        $this->assertSame('draft', $application->status);
        $this->assertNull($application->submitted_at);
        $this->assertAuthenticatedAs($application->user);
        $this->assertTrue($application->user->hasRole('Calon Mahasiswa'));
    }

    public function test_owner_can_open_wizard_and_save_complete_profile_with_minimal_audit_data(): void
    {
        [$user, $application] = $this->draftApplication();

        $this->actingAs($user)->get(route('pmb.application'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Pmb/Application')
            ->where('application.id', $application->id)
            ->where('application.status', 'draft')
            ->has('documentRequirements', 4)
            ->where('abilities.update', true));

        $this->patch(route('pmb.application.profile'), $this->profilePayload())->assertSessionHasNoErrors();
        $application->refresh();
        $this->assertSame('3201010101010001', $application->identity_number);
        $this->assertSame('Sekolah Menengah Atas', $application->previous_school);
        $this->assertNotNull($application->profile_completed_at);
        $audit = (string) \DB::table('audit_logs')->where(['module' => 'pmb', 'action' => 'profile_updated', 'record_id' => (string) $application->id])->value('new_data');
        $this->assertStringContainsString('identity_number', $audit);
        $this->assertStringNotContainsString('3201010101010001', $audit);
    }

    public function test_applicant_without_an_application_cannot_read_or_mutate_another_application(): void
    {
        [, $application] = $this->draftApplication();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->get(route('pmb.application'))->assertNotFound();
        $this->patch(route('pmb.application.profile'), $this->profilePayload())->assertNotFound();
        $this->assertNull($application->fresh()->profile_completed_at);
    }

    public function test_documents_are_stored_privately_and_replacing_one_removes_the_old_file(): void
    {
        Storage::fake('local');
        [$user, $application] = $this->draftApplication();
        $this->actingAs($user);

        $this->post(route('pmb.application.documents.store'), [
            'type' => 'photo',
            'file' => UploadedFile::fake()->image('foto-lama.jpg', 300, 400),
        ])->assertSessionHasNoErrors();
        $document = PmbDocument::query()->sole();
        $oldPath = $document->path;
        Storage::disk('local')->assertExists($oldPath);
        $this->assertStringStartsWith("pmb-documents/{$application->id}/", $oldPath);

        $this->post(route('pmb.application.documents.store'), [
            'type' => 'photo',
            'file' => UploadedFile::fake()->image('foto-baru.png', 300, 400),
        ])->assertSessionHasNoErrors();
        $document->refresh();
        $this->assertSame('foto-baru.png', $document->original_name);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($document->path);
        $this->assertDatabaseCount('pmb_documents', 1);
        $this->assertDatabaseHas('audit_logs', ['module' => 'pmb', 'action' => 'document_replaced', 'record_id' => (string) $application->id]);
    }

    public function test_submission_requires_complete_profile_and_all_required_documents(): void
    {
        Storage::fake('local');
        [$user, $application] = $this->draftApplication();
        $this->actingAs($user)->post(route('pmb.application.submit'))->assertSessionHasErrors('application');
        $this->assertSame('draft', $application->fresh()->status);

        $this->patch(route('pmb.application.profile'), $this->profilePayload())->assertSessionHasNoErrors();
        $this->post(route('pmb.application.submit'))->assertSessionHasErrors('application');

        foreach (['photo', 'identity_card', 'diploma', 'transcript'] as $type) {
            $this->post(route('pmb.application.documents.store'), [
                'type' => $type,
                'file' => UploadedFile::fake()->create("{$type}.pdf", 100, 'application/pdf'),
            ])->assertSessionHasNoErrors();
        }
        $term = AcademicTerm::create(['name' => 'PMB 2026', 'code' => 'PMB-2026', 'semester' => 'Ganjil', 'is_active' => true]);
        PmbFee::create(['academic_term_id' => $term->id, 'name' => 'Biaya Pendaftaran', 'registration_path' => 'Semua', 'registration_type' => 'Semua', 'amount' => 250000, 'due_days' => 3, 'is_active' => true]);

        $this->post(route('pmb.application.submit'))->assertSessionHasNoErrors();
        $application->refresh();
        $this->assertSame('submitted', $application->status);
        $this->assertNotNull($application->submitted_at);
        $this->assertDatabaseHas('pmb_invoices', ['pmb_application_id' => $application->id, 'invoice_number' => 'INV-'.$application->registration_number, 'amount' => 250000, 'status' => 'unpaid']);
        $this->assertDatabaseHas('audit_logs', ['module' => 'pmb', 'action' => 'submitted', 'record_id' => (string) $application->id]);
    }

    public function test_submitted_application_is_locked_against_profile_and_document_changes(): void
    {
        Storage::fake('local');
        [$user, $application] = $this->draftApplication();
        $application->update(['status' => 'submitted', 'submitted_at' => now()]);
        $document = PmbDocument::create([
            'pmb_application_id' => $application->id,
            'type' => 'photo',
            'disk' => 'local',
            'path' => "pmb-documents/{$application->id}/locked.jpg",
            'original_name' => 'locked.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 100,
            'status' => 'pending',
            'uploaded_at' => now(),
        ]);

        $this->actingAs($user)->patch(route('pmb.application.profile'), $this->profilePayload())->assertForbidden();
        $this->post(route('pmb.application.documents.store'), ['type' => 'photo', 'file' => UploadedFile::fake()->image('new.jpg')])->assertForbidden();
        $this->delete(route('pmb.application.documents.destroy', $document->id))->assertForbidden();
        $this->post(route('pmb.application.submit'))->assertForbidden();
        $this->assertDatabaseHas('pmb_documents', ['id' => $document->id, 'original_name' => 'locked.jpg']);
    }

    public function test_profile_rejects_duplicate_identity_and_document_validation_rejects_unsafe_type(): void
    {
        [$firstUser] = $this->draftApplication(['identity_number' => '3201010101010001']);
        [$secondUser, $secondApplication] = $this->draftApplication();

        $this->actingAs($secondUser)->patch(route('pmb.application.profile'), $this->profilePayload())->assertSessionHasErrors('identity_number');
        $this->post(route('pmb.application.documents.store'), [
            'type' => 'identity_card',
            'file' => UploadedFile::fake()->create('script.php', 10, 'application/x-php'),
        ])->assertSessionHasErrors('file');
        $this->assertNull($secondApplication->fresh()->profile_completed_at);
        $this->assertNotSame($firstUser->id, $secondUser->id);
    }

    private function draftApplication(array $overrides = []): array
    {
        $user = User::factory()->create();
        $program = $this->program();
        $application = PmbApplication::create([...[
            'user_id' => $user->id,
            'program_id' => $program->id,
            'registration_number' => 'PMB-2026-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'full_name' => $user->name,
            'email' => $user->email,
            'phone' => '081234567890',
            'status' => 'draft',
        ], ...$overrides]);

        return [$user, $application];
    }

    private function program(): Program
    {
        return Program::create(['name' => 'Teknik Informatika', 'code' => 'TI-'.uniqid(), 'degree' => 'S1', 'is_active' => true]);
    }

    private function profilePayload(): array
    {
        $program = Program::query()->latest('id')->firstOrFail();

        return [
            'program_id' => $program->id,
            'registration_path' => 'Reguler',
            'registration_type' => 'Baru',
            'full_name' => 'Nama Pendaftar Lengkap',
            'phone' => '081234567890',
            'identity_number' => '3201010101010001',
            'birth_place' => 'Bandung',
            'birth_date' => '2007-01-01',
            'gender' => 'P',
            'address' => 'Jalan Pendidikan Nomor 1',
            'previous_school' => 'Sekolah Menengah Atas',
            'graduation_year' => 2026,
            'guardian_name' => 'Nama Orang Tua',
            'guardian_phone' => '081298765432',
        ];
    }
}
