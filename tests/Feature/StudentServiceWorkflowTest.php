<?php

namespace Tests\Feature;

use App\Models\Lecturer;
use App\Models\BillingItem;
use App\Models\Program;
use App\Models\ServiceRequestType;
use App\Models\Student;
use App\Models\StudentServiceDocument;
use App\Models\StudentServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class StudentServiceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_submits_own_request_with_private_attachment_and_timeline(): void
    {
        Storage::fake('local'); $context = $this->context(requiresAttachment: true);
        $file = UploadedFile::fake()->create('surat-pendukung.pdf', 120, 'application/pdf');
        $response = $this->actingAs($context['studentUser'])->post(route('services.store'), $this->requestPayload($context['type'], ['attachment' => $file]));
        $response->assertRedirect(); $request = StudentServiceRequest::query()->sole();

        $this->assertSame('in_review', $request->status); $this->assertSame('advisor', $request->current_stage); $this->assertCount(3, $request->steps);
        $this->assertSame('pending', $request->steps()->orderBy('sequence')->first()->status); Storage::disk('local')->assertExists($request->attachment_path);
        $this->assertDatabaseHas('student_service_request_events', ['student_service_request_id' => $request->id, 'action' => 'submitted']);
        $this->assertDatabaseHas('audit_logs', ['module' => 'services', 'action' => 'service_request_submitted']);
        $this->assertDatabaseHas('system_notifications', ['user_id' => $context['advisorUser']->id, 'type' => 'service']);
    }

    public function test_required_attachment_and_active_student_are_enforced(): void
    {
        $context = $this->context(requiresAttachment: true);
        $this->actingAs($context['studentUser'])->post(route('services.store'), $this->requestPayload($context['type']))->assertSessionHasErrors('attachment');
        $context['student']->update(['status' => 'Cuti']);
        $this->post(route('services.store'), $this->requestPayload($context['type'], ['attachment' => UploadedFile::fake()->create('support.pdf', 20, 'application/pdf')]))->assertSessionHasErrors('service_request_type_id');
        $this->assertDatabaseCount('student_service_requests', 0);
    }

    public function test_each_role_only_reviews_its_current_stage_and_completion_issues_document(): void
    {
        $context = $this->context(); $request = $this->submit($context);
        $this->actingAs($context['prodi'])->post(route('services.decision', $request), ['decision' => 'approve', 'notes' => 'Premature'])->assertForbidden();
        $this->actingAs($context['advisorUser'])->post(route('services.decision', $request), ['decision' => 'approve', 'notes' => 'Disetujui dosen PA'])->assertSessionHasNoErrors();
        $this->assertSame('program', $request->fresh()->current_stage);
        $this->actingAs($context['staff'])->post(route('services.decision', $request), ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($context['prodi'])->post(route('services.decision', $request), ['decision' => 'approve', 'notes' => 'Disetujui prodi'])->assertSessionHasNoErrors();
        $this->assertSame('academic', $request->fresh()->current_stage);
        $this->actingAs($context['staff'])->post(route('services.decision', $request), ['decision' => 'approve', 'notes' => 'Administrasi lengkap'])->assertSessionHasNoErrors();

        $request->refresh(); $this->assertSame('completed', $request->status); $this->assertNull($request->current_stage); $this->assertNotNull($request->completed_at);
        $this->assertDatabaseCount('student_service_documents', 1); $this->assertDatabaseHas('system_notifications', ['user_id' => $context['studentUser']->id, 'title' => 'Surat layanan telah terbit']);
        $this->assertSame(['approved', 'approved', 'approved'], $request->steps()->orderBy('sequence')->pluck('status')->all());
    }

    public function test_revision_resubmits_to_same_stage_without_resetting_prior_approval(): void
    {
        $context = $this->context(); $request = $this->submit($context);
        $this->actingAs($context['advisorUser'])->post(route('services.decision', $request), ['decision' => 'approve']);
        $this->actingAs($context['prodi'])->post(route('services.decision', $request), ['decision' => 'revision', 'notes' => 'Tujuan surat harus dibuat lebih spesifik.'])->assertSessionHasNoErrors();
        $this->assertSame('revision_required', $request->fresh()->status);

        $this->actingAs($context['studentUser'])->post(route('services.resubmit', $request), ['resubmit' => true, 'subject' => 'Surat aktif kuliah revisi', 'purpose' => 'Persyaratan pengajuan beasiswa tingkat provinsi.', 'additional_information' => 'Sudah dilengkapi.'])->assertSessionHasNoErrors();
        $request->refresh(); $this->assertSame('in_review', $request->status); $this->assertSame(1, $request->revision_number); $this->assertSame('program', $request->current_stage);
        $this->assertSame('approved', $request->steps()->where('stage', 'advisor')->value('status')); $this->assertSame('pending', $request->steps()->where('stage', 'program')->value('status'));
        $this->assertDatabaseHas('student_service_request_events', ['student_service_request_id' => $request->id, 'action' => 'resubmitted']);
        $this->assertDatabaseHas('system_notifications', ['user_id' => $context['prodi']->id, 'title' => 'Pengajuan layanan menunggu pemeriksaan']);
    }

    public function test_rejection_and_cancellation_lock_follow_up_actions(): void
    {
        $context = $this->context(); $rejected = $this->submit($context);
        $this->actingAs($context['advisorUser'])->post(route('services.decision', $rejected), ['decision' => 'reject', 'notes' => 'Alasan pengajuan tidak sesuai kebijakan.'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $rejected->fresh()->status);
        $this->actingAs($context['studentUser'])->post(route('services.cancel', $rejected))->assertSessionHasErrors('request');

        $cancelled = $this->submit($context); $this->post(route('services.cancel', $cancelled))->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $cancelled->fresh()->status);
        $this->actingAs($context['advisorUser'])->post(route('services.decision', $cancelled), ['decision' => 'approve'])->assertSessionHasErrors('decision');
    }

    public function test_workspace_scopes_students_and_advisors_to_owned_requests(): void
    {
        $context = $this->context(); $request = $this->submit($context);
        $otherStudentUser = $this->roleUser('Mahasiswa', 'service_requests.view', 'service_requests.create', 'service_requests.update');
        Student::create(['user_id' => $otherStudentUser->id, 'program_id' => $context['program']->id, 'nim' => '22999', 'status' => 'Aktif', 'current_semester' => 1]);
        $this->actingAs($otherStudentUser)->get(route('services.index', ['selected' => $request->id]))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Services/Index')->where('requests.total', 0)->where('selectedRequest', null));

        $otherAdvisorUser = $this->roleUser('Dosen', 'service_requests.view', 'service_requests.update');
        Lecturer::create(['user_id' => $otherAdvisorUser->id, 'program_id' => $context['program']->id, 'name' => 'Dosen Lain', 'nidn' => 'NIDN-999', 'employment_status' => 'Tetap']);
        $this->actingAs($otherAdvisorUser)->get(route('services.index', ['selected' => $request->id]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('requests.total', 0)->where('selectedRequest', null));
        $this->actingAs($context['advisorUser'])->get(route('services.index', ['selected' => $request->id]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('selectedRequest.id', $request->id)->where('abilities.review', true));
    }

    public function test_service_letter_is_real_pdf_publicly_verifiable_and_admin_can_revoke(): void
    {
        $context = $this->context(); $request = $this->submit($context); $this->complete($context, $request); $document = StudentServiceDocument::query()->sole();
        $this->actingAs($context['studentUser'])->get(route('services.documents.show', $document))->assertOk()->assertSee($document->document_number)->assertSee('Surat Keterangan Aktif Kuliah');
        $pdf = $this->get(route('services.documents.pdf', $document))->assertOk()->assertHeader('Content-Type', 'application/pdf'); $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->get(route('services.documents.verify', $document->verification_code))->assertOk()->assertSee('SURAT VALID')->assertSee($context['student']->nim);

        $this->actingAs($context['admin'])->patch(route('services.documents.revoke', $document), ['reason' => 'Surat dicabut karena koreksi data administratif.'])->assertSessionHasNoErrors();
        $this->get(route('services.documents.verify', $document->verification_code))->assertOk()->assertSee('SURAT TIDAK VALID')->assertSee('koreksi data administratif');
        $this->actingAs($context['studentUser'])->patch(route('services.documents.revoke', $document), ['reason' => 'Mahasiswa tidak boleh mencabut surat sendiri.'])->assertForbidden();
    }

    public function test_only_admin_manages_types_and_used_types_cannot_be_archived(): void
    {
        $context = $this->context(); $payload = $this->typePayload(['code' => 'KUSTOM', 'name' => 'Layanan Kustom']);
        $this->actingAs($context['prodi'])->post(route('services.types.store'), $payload)->assertForbidden();
        $this->actingAs($context['admin'])->post(route('services.types.store'), $payload)->assertSessionHasNoErrors();
        $custom = ServiceRequestType::query()->where('code', 'KUSTOM')->sole();
        $this->patch(route('services.types.update', $custom), $this->typePayload(['code' => 'KUSTOM', 'name' => 'Layanan Kustom Diperbarui', 'workflow' => ['program', 'academic']]))->assertSessionHasNoErrors();
        $this->assertSame(['program', 'academic'], $custom->fresh()->workflow);
        $request = $this->submit($context); $this->actingAs($context['admin'])->delete(route('services.types.destroy', $context['type']))->assertSessionHasErrors('type');
        $this->assertNotNull($request->id); $this->delete(route('services.types.destroy', $custom))->assertSessionHasNoErrors(); $this->assertSoftDeleted('service_request_types', ['id' => $custom->id]);
    }

    public function test_overdue_filter_and_metrics_use_role_scoped_queue(): void
    {
        $context = $this->context(); $request = $this->submit($context); $request->update(['due_at' => now()->subDay()]);
        $this->actingAs($context['advisorUser'])->get(route('services.index', ['status' => 'overdue']))->assertOk()->assertInertia(fn (Assert $page) => $page->where('requests.total', 1)->where('stats.overdue', 1)->where('filters.status', 'overdue'));
    }

    public function test_financial_clearance_blocks_approval_while_student_has_outstanding_bill(): void
    {
        $context = $this->context();
        $context['type']->update(['workflow' => ['finance'], 'requires_financial_clearance' => true]);
        $bill = BillingItem::create(['student_id' => $context['student']->id, 'invoice_number' => 'INV-SRV-001', 'description' => 'Tagihan administrasi', 'category' => 'Administrasi', 'amount' => 1000000, 'paid_amount' => 250000, 'due_on' => now()->addWeek(), 'status' => 'partial']);
        $request = $this->submit($context);
        $this->actingAs($context['finance'])->post(route('services.decision', $request), ['decision' => 'approve'])->assertSessionHasErrors('decision');
        $this->assertSame('in_review', $request->fresh()->status);

        $bill->update(['paid_amount' => 1000000, 'status' => 'paid']);
        $this->post(route('services.decision', $request), ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame('completed', $request->fresh()->status); $this->assertDatabaseCount('student_service_documents', 1);
    }

    private function context(bool $requiresAttachment = false): array
    {
        $program = Program::create(['name' => 'Teknik Informatika', 'code' => 'TI', 'degree' => 'S1', 'is_active' => true]);
        $advisorUser = $this->roleUser('Dosen', 'service_requests.view', 'service_requests.update');
        $advisor = Lecturer::create(['user_id' => $advisorUser->id, 'program_id' => $program->id, 'name' => 'Dosen Pembimbing', 'nidn' => 'NIDN-001', 'employment_status' => 'Tetap']);
        $studentUser = $this->roleUser('Mahasiswa', 'service_requests.view', 'service_requests.create', 'service_requests.update');
        $student = Student::create(['user_id' => $studentUser->id, 'program_id' => $program->id, 'academic_advisor_id' => $advisor->id, 'nim' => '22001', 'status' => 'Aktif', 'current_semester' => 3]);
        $prodi = $this->roleUser('Prodi', 'service_requests.view', 'service_requests.update');
        $staff = $this->roleUser('Staff', 'service_requests.view', 'service_requests.update');
        $finance = $this->roleUser('Keuangan', 'service_requests.view', 'service_requests.update');
        $admin = $this->roleUser('Admin', 'service_requests.view', 'service_requests.create', 'service_requests.update', 'service_requests.delete', 'service_types.view', 'service_types.create', 'service_types.update', 'service_types.delete');
        $type = ServiceRequestType::create($this->typePayload(['requires_attachment' => $requiresAttachment, 'created_by' => $admin->id]));
        return compact('program', 'advisorUser', 'advisor', 'studentUser', 'student', 'prodi', 'staff', 'finance', 'admin', 'type');
    }

    private function submit(array $context): StudentServiceRequest
    {
        $this->actingAs($context['studentUser'])->post(route('services.store'), $this->requestPayload($context['type']))->assertSessionHasNoErrors();
        return StudentServiceRequest::query()->latest('id')->firstOrFail();
    }

    private function complete(array $context, StudentServiceRequest $request): void
    {
        $this->actingAs($context['advisorUser'])->post(route('services.decision', $request), ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->actingAs($context['prodi'])->post(route('services.decision', $request), ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->actingAs($context['staff'])->post(route('services.decision', $request), ['decision' => 'approve'])->assertSessionHasNoErrors();
    }

    private function requestPayload(ServiceRequestType $type, array $overrides = []): array
    {
        return [...['service_request_type_id' => $type->id, 'subject' => 'Surat aktif kuliah untuk beasiswa', 'purpose' => 'Persyaratan pengajuan beasiswa tingkat nasional.', 'additional_information' => 'Ditujukan kepada panitia seleksi.'], ...$overrides];
    }

    private function typePayload(array $overrides = []): array
    {
        return [...['code' => 'AKTIF-KULIAH', 'name' => 'Surat Keterangan Aktif Kuliah', 'category' => 'academic', 'description' => 'Surat status mahasiswa aktif.', 'workflow' => ['advisor', 'program', 'academic'], 'requirements_text' => 'Pastikan data benar.', 'template_subject' => 'Surat Keterangan Aktif Kuliah', 'template_body' => 'Menerangkan bahwa {NAMA}, NIM {NIM}, mahasiswa {PROGRAM}, mengajukan surat untuk {TUJUAN}.', 'sla_business_days' => 3, 'requires_attachment' => false, 'requires_financial_clearance' => false, 'is_active' => true], ...$overrides];
    }

    private function roleUser(string $activeRole, string ...$permissions): User
    {
        $role = Role::findOrCreate($activeRole, 'web'); $user = User::factory()->create(['active_role' => $activeRole]); $user->assignRole($role);
        foreach ($permissions as $permission) Permission::findOrCreate($permission, 'web'); $user->givePermissionTo($permissions);
        return $user;
    }
}
