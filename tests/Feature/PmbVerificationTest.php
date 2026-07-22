<?php

namespace Tests\Feature;

use App\Models\PmbApplication;
use App\Models\PmbDocument;
use App\Models\PmbInvoice;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PmbVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_workspace_and_private_downloads_have_separate_authorization(): void
    {
        Storage::fake('local');
        [$owner, $application, $documents] = $this->submittedApplication();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->get(route('admin.pmb.applications.show', $application))->assertForbidden();
        $this->get(route('admin.pmb.documents.download', [$application, $documents['photo']->id]))->assertForbidden();

        $this->actingAs($owner)->get(route('admin.pmb.applications.show', $application))->assertForbidden();
        $this->get(route('admin.pmb.documents.download', [$application, $documents['photo']->id]))->assertDownload('photo.pdf');

        $viewer = $this->userWithPermissions('pmb_verification.view');
        $this->actingAs($viewer)->get(route('admin.pmb.applications.show', $application))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/PmbVerification')
            ->where('application.id', $application->id)
            ->where('abilities.review', false)
            ->where('abilities.download', true));
        $this->get(route('admin.pmb.documents.download', [$application, $documents['photo']->id]))->assertDownload('photo.pdf');
        $this->patch(route('admin.pmb.documents.decide', [$application, $documents['photo']->id]), ['status' => 'verified'])->assertForbidden();
    }

    public function test_document_download_and_decision_are_scoped_to_the_application(): void
    {
        Storage::fake('local');
        [, $first] = $this->submittedApplication('FIRST');
        [, , $secondDocuments] = $this->submittedApplication('SECOND');
        $reviewer = $this->userWithPermissions('pmb_verification.view', 'pmb_verification.update');

        $this->actingAs($reviewer)
            ->get(route('admin.pmb.documents.download', [$first, $secondDocuments['photo']->id]))
            ->assertNotFound();
        $this->patch(route('admin.pmb.documents.decide', [$first, $secondDocuments['photo']->id]), ['status' => 'verified'])
            ->assertNotFound();
        $this->assertSame('pending', $secondDocuments['photo']->fresh()->status);
    }

    public function test_rejection_requires_notes_and_application_can_only_be_returned_after_rejection(): void
    {
        Storage::fake('local');
        [, $application, $documents] = $this->submittedApplication();
        $reviewer = $this->userWithPermissions('pmb_verification.view', 'pmb_verification.update');
        $this->actingAs($reviewer);

        $this->post(route('admin.pmb.applications.return', $application))->assertSessionHasErrors('application');
        $this->patch(route('admin.pmb.documents.decide', [$application, $documents['diploma']->id]), ['status' => 'rejected'])
            ->assertSessionHasErrors('notes');
        $this->patch(route('admin.pmb.documents.decide', [$application, $documents['diploma']->id]), [
            'status' => 'rejected',
            'notes' => 'Ijazah buram, mohon unggah ulang hasil pindai yang terbaca.',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('pmb_documents', ['id' => $documents['diploma']->id, 'status' => 'rejected']);
        $this->assertDatabaseHas('audit_logs', ['module' => 'pmb', 'action' => 'document_rejected', 'record_id' => (string) $application->id]);

        $this->post(route('admin.pmb.applications.return', $application))->assertSessionHasNoErrors();
        $this->assertSame('draft', $application->fresh()->status);
        $this->assertNull($application->fresh()->submitted_at);
        $this->assertDatabaseHas('audit_logs', ['module' => 'pmb', 'action' => 'returned_for_correction', 'record_id' => (string) $application->id]);
    }

    public function test_applicant_must_replace_rejected_document_before_resubmission_and_invoice_is_reused(): void
    {
        Storage::fake('local');
        [$owner, $application, $documents] = $this->submittedApplication();
        $invoice = PmbInvoice::create([
            'pmb_application_id' => $application->id,
            'invoice_number' => 'INV-'.$application->registration_number,
            'description' => 'Biaya pendaftaran PMB',
            'amount' => 250000,
            'paid_amount' => 0,
            'due_at' => now()->addDays(3),
            'status' => 'unpaid',
            'issued_at' => now(),
        ]);
        $reviewer = $this->userWithPermissions('pmb_verification.view', 'pmb_verification.update');

        $this->actingAs($reviewer)->patch(route('admin.pmb.documents.decide', [$application, $documents['photo']->id]), [
            'status' => 'rejected',
            'notes' => 'Pas foto tidak sesuai ketentuan.',
        ])->assertSessionHasNoErrors();
        $this->post(route('admin.pmb.applications.return', $application))->assertSessionHasNoErrors();

        $this->actingAs($owner)->post(route('pmb.application.submit'))->assertSessionHasErrors('application');
        $this->post(route('pmb.application.documents.store'), [
            'type' => 'photo',
            'file' => UploadedFile::fake()->image('photo-correction.jpg', 300, 400),
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('pmb_documents', ['id' => $documents['photo']->id, 'status' => 'pending', 'notes' => null, 'original_name' => 'photo-correction.jpg']);

        $this->post(route('pmb.application.submit'))->assertSessionHasNoErrors();
        $this->assertSame('submitted', $application->fresh()->status);
        $this->assertSame($invoice->id, $application->fresh()->invoice->id);
        $this->assertDatabaseCount('pmb_invoices', 1);
    }

    public function test_final_verification_requires_all_four_documents_to_be_verified(): void
    {
        Storage::fake('local');
        [, $application, $documents] = $this->submittedApplication();
        $reviewer = $this->userWithPermissions('pmb_verification.view', 'pmb_verification.update');
        $this->actingAs($reviewer);

        $this->post(route('admin.pmb.applications.verify', $application))->assertSessionHasErrors('application');
        foreach ($documents as $document) {
            $this->patch(route('admin.pmb.documents.decide', [$application, $document->id]), ['status' => 'verified'])
                ->assertSessionHasNoErrors();
        }

        $this->post(route('admin.pmb.applications.verify', $application))->assertSessionHasNoErrors();
        $this->assertSame('verified', $application->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['module' => 'pmb', 'action' => 'verified', 'record_id' => (string) $application->id]);
        $this->patch(route('admin.pmb.documents.decide', [$application, $documents['photo']->id]), ['status' => 'rejected', 'notes' => 'Tidak berlaku lagi.'])
            ->assertForbidden();
    }

    private function submittedApplication(string $suffix = 'MAIN'): array
    {
        $owner = User::factory()->create();
        $program = Program::create(['name' => 'Teknik Informatika', 'code' => 'TI-'.$suffix.'-'.uniqid(), 'degree' => 'S1', 'is_active' => true]);
        $application = PmbApplication::create([
            'user_id' => $owner->id,
            'program_id' => $program->id,
            'registration_path' => 'Reguler',
            'registration_type' => 'Baru',
            'registration_number' => 'PMB-2026-'.$suffix.'-'.$owner->id,
            'full_name' => $owner->name,
            'email' => $owner->email,
            'phone' => '081234567890',
            'identity_number' => str_pad((string) $owner->id, 16, '3', STR_PAD_LEFT),
            'birth_place' => 'Bandung',
            'birth_date' => '2007-01-01',
            'gender' => 'P',
            'address' => 'Jalan Pendidikan Nomor 1',
            'previous_school' => 'Sekolah Menengah Atas',
            'graduation_year' => 2026,
            'guardian_name' => 'Orang Tua Pemohon',
            'guardian_phone' => '081298765432',
            'status' => 'submitted',
            'submitted_at' => now(),
            'profile_completed_at' => now(),
        ]);
        $documents = collect(['photo', 'identity_card', 'diploma', 'transcript'])->mapWithKeys(function (string $type) use ($application): array {
            $path = "pmb-documents/{$application->id}/{$type}.pdf";
            Storage::disk('local')->put($path, "private {$type} content");
            $document = PmbDocument::create([
                'pmb_application_id' => $application->id,
                'type' => $type,
                'disk' => 'local',
                'path' => $path,
                'original_name' => "{$type}.pdf",
                'mime_type' => 'application/pdf',
                'size' => Storage::disk('local')->size($path),
                'status' => 'pending',
                'uploaded_at' => now(),
            ]);

            return [$type => $document];
        })->all();

        return [$owner, $application, $documents];
    }

    private function userWithPermissions(string ...$permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permissions);

        return $user;
    }
}
