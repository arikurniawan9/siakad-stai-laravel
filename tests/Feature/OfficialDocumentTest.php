<?php

namespace Tests\Feature;

use App\Models\AcademicRegistrationPeriod;
use App\Models\AcademicTerm;
use App\Models\BillingItem;
use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\OfficialDocument;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Program;
use App\Models\SemesterRegistration;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class OfficialDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_issue_own_krs_and_download_real_pdf_with_public_verification(): void
    {
        $context = $this->context();
        $this->actingAs($context['studentUser'])->post(route('documents.issue', ['type' => 'krs', 'sourceId' => $context['registration']->id]))
            ->assertRedirect();
        $document = OfficialDocument::query()->sole();

        $this->assertSame('krs', $document->type);
        $this->assertSame($context['student']->id, $document->student_id);
        $this->get(route('documents.show', $document))->assertOk()->assertSee($document->document_number)->assertSee('Kartu Rencana Studi');
        $pdf = $this->get(route('documents.pdf', $document))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->get(route('documents.verify', $document->verification_code))->assertOk()->assertSee('DOKUMEN VALID')->assertSee($document->document_number);
        $this->assertDatabaseHas('audit_logs', ['module' => 'documents', 'action' => 'document_issued']);
    }

    public function test_khs_requires_all_grades_and_new_snapshot_revokes_previous_version(): void
    {
        $context = $this->context();
        $this->actingAs($context['studentUser'])->post(route('documents.issue', ['type' => 'khs', 'sourceId' => $context['registration']->id]))->assertSessionHasErrors('document');
        $context['enrollment']->update(['final_score' => 88, 'letter_grade' => 'A', 'grade_status' => 'published', 'grade_published_at' => now()]);
        $this->post(route('documents.issue', ['type' => 'khs', 'sourceId' => $context['registration']->id]))->assertRedirect();
        $first = OfficialDocument::query()->sole();
        $this->post(route('documents.issue', ['type' => 'khs', 'sourceId' => $context['registration']->id]))->assertRedirect();
        $this->assertDatabaseCount('official_documents', 1);

        $context['enrollment']->update(['final_score' => 78, 'letter_grade' => 'B']);
        $this->post(route('documents.issue', ['type' => 'khs', 'sourceId' => $context['registration']->id]))->assertRedirect();
        $this->assertDatabaseCount('official_documents', 2);
        $this->assertNotNull($first->fresh()->revoked_at);
        $this->get(route('documents.verify', $first->verification_code))->assertOk()->assertSee('DOKUMEN TIDAK VALID')->assertSee('Digantikan oleh versi dokumen yang lebih baru.');
    }

    public function test_student_cannot_issue_or_view_another_students_document_and_cannot_revoke(): void
    {
        $context = $this->context();
        $otherUser = $this->permissionUser('Mahasiswa', 'documents.view', 'documents.create');
        $otherStudent = Student::create(['user_id' => $otherUser->id, 'program_id' => $context['program']->id, 'nim' => '22999', 'status' => 'Aktif', 'current_semester' => 1]);
        $this->actingAs($otherUser)->post(route('documents.issue', ['type' => 'krs', 'sourceId' => $context['registration']->id]))->assertForbidden();

        $this->actingAs($context['studentUser'])->post(route('documents.issue', ['type' => 'krs', 'sourceId' => $context['registration']->id]));
        $document = OfficialDocument::query()->sole();
        $this->actingAs($otherUser)->get(route('documents.show', $document))->assertForbidden();
        $this->actingAs($context['studentUser'])->patch(route('documents.revoke', $document), ['reason' => 'Saya ingin mencabut sendiri dokumen ini.'])->assertForbidden();
        $this->assertNull($document->fresh()->revoked_at);
        $this->assertNotNull($otherStudent->id);
    }

    public function test_academic_and_finance_officers_are_restricted_to_their_document_domains(): void
    {
        $context = $this->context();
        $bill = BillingItem::create(['student_id' => $context['student']->id, 'academic_term_id' => $context['term']->id, 'invoice_number' => 'INV-2026-001', 'description' => 'UKT Semester Ganjil', 'category' => 'UKT', 'amount' => 5000000, 'paid_amount' => 0, 'due_on' => now()->addMonth(), 'status' => 'unpaid']);
        $prodi = $this->permissionUser('Prodi', 'documents.view', 'documents.create');
        $finance = $this->permissionUser('Keuangan', 'documents.view', 'documents.create');

        $this->actingAs($prodi)->post(route('documents.issue', ['type' => 'invoice', 'sourceId' => $bill->id]))->assertForbidden();
        $this->actingAs($finance)->post(route('documents.issue', ['type' => 'krs', 'sourceId' => $context['registration']->id]))->assertForbidden();
        $this->post(route('documents.issue', ['type' => 'invoice', 'sourceId' => $bill->id]))->assertRedirect();
        $this->assertDatabaseHas('official_documents', ['type' => 'invoice', 'student_id' => $context['student']->id]);
    }

    public function test_receipt_snapshot_contains_allocations_and_revocation_changes_public_status(): void
    {
        $context = $this->context();
        $bill = BillingItem::create(['student_id' => $context['student']->id, 'academic_term_id' => $context['term']->id, 'invoice_number' => 'INV-2026-002', 'description' => 'Biaya praktikum', 'category' => 'Praktikum', 'amount' => 750000, 'paid_amount' => 750000, 'due_on' => now()->addMonth(), 'status' => 'paid']);
        $payment = Payment::create(['student_id' => $context['student']->id, 'provider' => 'manual', 'external_reference' => 'PAY-MANUAL-001', 'amount' => 750000, 'currency' => 'IDR', 'paid_at' => now(), 'status' => 'allocated']);
        PaymentAllocation::create(['payment_id' => $payment->id, 'billing_item_id' => $bill->id, 'amount' => 750000]);
        $finance = $this->permissionUser('Keuangan', 'documents.view', 'documents.create');

        $this->actingAs($finance)->post(route('documents.issue', ['type' => 'receipt', 'sourceId' => $payment->id]))->assertRedirect();
        $document = OfficialDocument::query()->sole();
        $this->assertSame('INV-2026-002', $document->snapshot['payment']['allocations'][0]['invoice_number']);
        $this->patch(route('documents.revoke', $document), ['reason' => 'Pembayaran sedang dikoreksi oleh unit keuangan.'])->assertRedirect();
        $this->get(route('documents.verify', $document->verification_code))->assertOk()->assertSee('DOKUMEN TIDAK VALID')->assertSee('Pembayaran sedang dikoreksi');
    }

    public function test_document_hub_only_returns_selected_students_registry(): void
    {
        $context = $this->context();
        $this->actingAs($context['studentUser'])->post(route('documents.issue', ['type' => 'krs', 'sourceId' => $context['registration']->id]));
        $this->get(route('documents.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Documents/Index')->where('mode', 'student')->where('selectedStudent.id', $context['student']->id)
            ->has('registrations', 1)->has('issuedDocuments', 1)->where('access.academic', true));
    }

    private function context(): array
    {
        $program = Program::create(['name' => 'Teknik Informatika', 'code' => 'TI', 'degree' => 'S1', 'is_active' => true]);
        $term = AcademicTerm::create(['name' => 'Tahun Akademik 2026/2027', 'code' => '2026-GANJIL', 'semester' => 'Ganjil', 'starts_on' => now()->subMonth(), 'ends_on' => now()->addMonths(4), 'is_active' => true]);
        $period = AcademicRegistrationPeriod::create(['academic_term_id' => $term->id, 'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth(), 'default_max_credits' => 24, 'is_open' => true]);
        $course = Course::create(['program_id' => $program->id, 'name' => 'Algoritma', 'code' => 'TI101', 'credits' => 3, 'type' => 'Wajib', 'is_active' => true]);
        $class = ClassGroup::create(['academic_term_id' => $term->id, 'course_id' => $course->id, 'name' => 'A', 'capacity' => 30, 'day' => 'Senin', 'starts_at' => '08:00', 'ends_at' => '09:40', 'is_active' => true]);
        $studentUser = $this->permissionUser('Mahasiswa', 'documents.view', 'documents.create');
        $student = Student::create(['user_id' => $studentUser->id, 'program_id' => $program->id, 'nim' => '22001', 'status' => 'Aktif', 'current_semester' => 1]);
        $registration = SemesterRegistration::create(['student_id' => $student->id, 'academic_term_id' => $term->id, 'academic_registration_period_id' => $period->id, 'max_credits' => 24, 'status' => 'approved', 'reviewed_at' => now()]);
        $enrollment = CourseEnrollment::create(['semester_registration_id' => $registration->id, 'class_group_id' => $class->id, 'credits' => 3, 'status' => 'enrolled']);

        return compact('program', 'term', 'studentUser', 'student', 'registration', 'enrollment');
    }

    private function permissionUser(string $activeRole, string ...$permissions): User
    {
        $user = User::factory()->create(['active_role' => $activeRole]);
        foreach ($permissions as $permission) Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permissions);

        return $user;
    }
}
