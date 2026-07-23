<?php

namespace Tests\Feature;

use App\Domain\Graduation\GraduationService;
use App\Models\AcademicTerm;
use App\Models\GraduationApplication;
use App\Models\GraduationPeriod;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class GraduationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('siakad.graduation.minimum_credits', 0);
        config()->set('siakad.graduation.minimum_gpa', 0);
        config()->set('siakad.graduation.require_completed_project', false);
        Storage::fake('local');
    }

    public function test_user_without_permission_cannot_open_graduation_workspace(): void
    {
        $this->actingAs(User::factory()->create(['active_role' => 'Mahasiswa']))
            ->get(route('graduation.index'))
            ->assertForbidden();
    }

    public function test_manager_can_create_period_and_student_start_is_idempotent(): void
    {
        $context = $this->context();
        $manager = $this->user('Prodi', 'graduation.view', 'graduation.create', 'graduation.update');
        $payload = $this->periodPayload($context['term']);

        $this->actingAs($manager)->post(route('graduation.periods.store'), $payload)->assertSessionHasNoErrors();
        $period = GraduationPeriod::query()->sole();
        $this->actingAs($context['user'])->post(route('graduation.start', $period))->assertSessionHasNoErrors();
        $this->actingAs($context['user'])->post(route('graduation.start', $period))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('graduation_applications', 1);
        $this->assertDatabaseHas('audit_logs', ['module' => 'graduation', 'action' => 'graduation_application_started']);
    }

    public function test_submit_requires_all_current_documents_and_keeps_upload_versions(): void
    {
        $context = $this->draftApplication();
        $this->actingAs($context['user'])->post(route('graduation.submit', $context['application']))->assertSessionHasErrors('documents');

        $this->uploadRequirements($context);
        $this->actingAs($context['user'])->post(route('graduation.documents.store', $context['application']), [
            'document_type' => 'identity',
            'document' => UploadedFile::fake()->create('identitas-v2.pdf', 50, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $versions = $context['application']->documents()->where('document_type', 'identity')->orderBy('version')->get();
        $this->assertCount(2, $versions);
        $this->assertFalse($versions[0]->is_current);
        $this->assertTrue($versions[1]->is_current);

        $this->actingAs($context['user'])->post(route('graduation.submit', $context['application']))->assertSessionHasNoErrors();
        $application = $context['application']->fresh();
        $this->assertSame('submitted', $application->status);
        $this->assertTrue($application->eligibility_snapshot['eligible']);
    }

    public function test_eligibility_rejects_inactive_student_and_outstanding_balance(): void
    {
        $context = $this->context();
        $context['student']->update(['status' => 'Cuti']);
        $context['student']->billingItems()->create(['academic_term_id' => $context['term']->id, 'invoice_number' => 'INV-GRD-001', 'description' => 'Tunggakan UKT', 'category' => 'UKT', 'amount' => 500000, 'paid_amount' => 0, 'due_on' => now(), 'status' => 'unpaid']);

        $snapshot = app(GraduationService::class)->eligibility($context['student']->fresh());

        $this->assertFalse($snapshot['eligible']);
        $this->assertContains('status mahasiswa tidak aktif', $snapshot['failures']);
        $this->assertContains('masih memiliki tunggakan', $snapshot['failures']);
        $this->assertSame(500000.0, $snapshot['outstanding']);
    }

    public function test_only_owner_can_download_private_requirement_document(): void
    {
        $context = $this->draftApplication();
        $this->uploadRequirements($context);
        $document = $context['application']->documents()->where('document_type', 'identity')->sole();

        $this->actingAs($context['user'])->get(route('graduation.documents.show', [$context['application'], $document]))->assertOk();
        $outsider = $this->context('SI02', 'MHS002');
        $this->actingAs($outsider['user'])->get(route('graduation.documents.show', [$context['application'], $document]))->assertForbidden();
    }

    public function test_review_rechecks_eligibility_and_rejection_requires_notes(): void
    {
        $context = $this->submittedApplication();
        $manager = $this->user('Prodi', 'graduation.view', 'graduation.update');

        $this->actingAs($manager)->post(route('graduation.decision', $context['application']), ['decision' => 'reject', 'notes' => 'singkat'])->assertSessionHasErrors('notes');
        $context['student']->update(['status' => 'Cuti']);
        $this->actingAs($manager)->post(route('graduation.decision', $context['application']), ['decision' => 'approve', 'notes' => 'Dokumen sudah lengkap.'])->assertSessionHasErrors('eligibility');

        $context['student']->update(['status' => 'Aktif']);
        $this->actingAs($manager)->post(route('graduation.decision', $context['application']), ['decision' => 'approve', 'notes' => 'Dokumen dan syarat akademik lengkap.'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('graduation_applications', ['id' => $context['application']->id, 'status' => 'approved', 'reviewed_by' => $manager->id]);
    }

    public function test_graduation_issues_three_verifiable_documents_and_is_idempotent(): void
    {
        $context = $this->approvedApplication();
        $manager = $context['manager'];

        $this->actingAs($manager)->post(route('graduation.graduate', $context['application']))->assertSessionHasNoErrors();
        $this->actingAs($manager)->post(route('graduation.graduate', $context['application']))->assertSessionHasNoErrors();

        $application = $context['application']->fresh(['graduateDocuments', 'alumniProfile']);
        $this->assertSame('graduated', $application->status);
        $this->assertSame('Lulus', $context['student']->fresh()->status);
        $this->assertCount(3, $application->graduateDocuments);
        $this->assertNotNull($application->alumniProfile);
        $this->assertDatabaseCount('student_status_histories', 1);
        $this->assertDatabaseCount('graduate_document_sequences', 3);

        $document = $application->graduateDocuments->first();
        $this->actingAs($context['user'])->get(route('graduation.documents.pdf', [$application, $document]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('graduation.documents.verify', $document->verification_code))->assertOk()->assertSee('DOKUMEN VALID')->assertSee($document->document_number);
    }

    public function test_alumni_can_update_profile_and_tracer_response_is_upserted_per_year(): void
    {
        $context = $this->approvedApplication();
        app(GraduationService::class)->markGraduated($context['application'], $context['manager']);
        $profile = $context['application']->fresh()->alumniProfile;

        $profilePayload = ['personal_email' => 'alumni@example.test', 'phone' => '08123456789', 'address' => 'Jakarta', 'employment_status' => 'employed', 'company_name' => 'PT Kampus Digital', 'position' => 'System Analyst', 'industry' => 'Teknologi', 'employment_started_on' => now()->subMonth()->toDateString(), 'directory_consent' => true];
        $this->actingAs($context['user'])->patch(route('graduation.alumni.update', $profile), $profilePayload)->assertSessionHasNoErrors();

        $tracer = ['survey_year' => now()->year, 'employment_status' => 'employed', 'waiting_months' => 1, 'company_name' => 'PT Kampus Digital', 'position' => 'System Analyst', 'salary_range' => '5-10 juta', 'study_relevance' => 5, 'feedback' => 'Kurikulum relevan dengan pekerjaan.'];
        $this->actingAs($context['user'])->post(route('graduation.tracer.store', $profile), $tracer)->assertSessionHasNoErrors();
        $this->actingAs($context['user'])->post(route('graduation.tracer.store', $profile), [...$tracer, 'position' => 'Senior System Analyst'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('alumni_profiles', ['id' => $profile->id, 'personal_email' => 'alumni@example.test', 'directory_consent' => true]);
        $this->assertDatabaseCount('tracer_study_responses', 1);
        $this->assertDatabaseHas('tracer_study_responses', ['alumni_profile_id' => $profile->id, 'position' => 'Senior System Analyst']);
    }

    private function approvedApplication(): array
    {
        $context = $this->submittedApplication();
        $manager = $this->user('Prodi', 'graduation.view', 'graduation.update');
        app(GraduationService::class)->decide($context['application'], 'approve', 'Semua persyaratan lengkap.', $manager);

        return [...$context, 'manager' => $manager, 'application' => $context['application']->fresh()];
    }

    private function submittedApplication(): array
    {
        $context = $this->draftApplication();
        $this->uploadRequirements($context);
        app(GraduationService::class)->submit($context['application']);

        return [...$context, 'application' => $context['application']->fresh()];
    }

    private function draftApplication(): array
    {
        $context = $this->context();
        $manager = $this->user('Prodi', 'graduation.view', 'graduation.create', 'graduation.update');
        $period = GraduationPeriod::create([...$this->periodPayload($context['term']), 'created_by' => $manager->id]);
        $application = app(GraduationService::class)->start($context['student'], $period);

        return [...$context, 'period' => $period, 'application' => $application];
    }

    private function uploadRequirements(array $context): void
    {
        foreach (['identity', 'photo', 'clearance'] as $type) {
            $this->actingAs($context['user'])->post(route('graduation.documents.store', $context['application']), [
                'document_type' => $type,
                'document' => UploadedFile::fake()->create($type.'.pdf', 50, 'application/pdf'),
            ])->assertSessionHasNoErrors();
        }
    }

    private function context(string $programCode = 'SI01', string $nim = 'MHS001'): array
    {
        $user = $this->user('Mahasiswa', 'graduation.view', 'graduation.create', 'graduation.update', 'alumni.view', 'alumni.update');
        $program = Program::create(['name' => 'Sistem Informasi '.$programCode, 'code' => $programCode, 'degree' => 'S1', 'is_active' => true]);
        $term = AcademicTerm::create(['name' => 'Ganjil 2026 '.$programCode, 'code' => '2026-G-'.$programCode, 'semester' => 'Ganjil', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonths(5)->toDateString(), 'is_active' => true]);
        $student = Student::create(['user_id' => $user->id, 'program_id' => $program->id, 'nim' => $nim, 'status' => 'Aktif', 'current_semester' => 8, 'phone' => '0812000000', 'address' => 'Jakarta']);

        return compact('user', 'program', 'term', 'student');
    }

    private function periodPayload(AcademicTerm $term): array
    {
        return ['academic_term_id' => $term->id, 'code' => 'YDS-'.$term->code, 'name' => 'Yudisium '.$term->name, 'registration_starts_at' => now()->subDay()->format('Y-m-d H:i:s'), 'registration_ends_at' => now()->addDay()->format('Y-m-d H:i:s'), 'judicium_on' => now()->addDays(7)->toDateString(), 'ceremony_on' => now()->addMonth()->toDateString(), 'quota' => 100, 'is_active' => true];
    }

    private function user(string $role, string ...$permissions): User
    {
        $user = User::factory()->create(['active_role' => $role]);
        foreach ($permissions as $permission) Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permissions);

        return $user;
    }
}
