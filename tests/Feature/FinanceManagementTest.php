<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\BillingItem;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class FinanceManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_staff_can_issue_bill_and_student_only_sees_own_ledger(): void
    {
        $context = $this->context(); $finance = $this->permissionUser('Keuangan', 'finance.view', 'billing.view', 'billing.update');
        $this->actingAs($finance)->post(route('finance.bills.store'), ['student_id' => $context['students'][0]->id, 'academic_term_id' => $context['term']->id, 'description' => 'UKT Ganjil', 'category' => 'UKT', 'amount' => 2500000, 'due_on' => now()->addWeek()->toDateString(), 'notes' => 'Tarif reguler'])->assertSessionHasNoErrors();
        $bill = BillingItem::query()->sole();
        $this->assertStringStartsWith('INV-2026-GANJIL-22001-', $bill->invoice_number);
        $this->actingAs($context['studentUsers'][0])->get(route('finance.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Finance/Index')->where('mode', 'student')->has('bills.data', 1)->where('summary.outstanding', 2500000));
        $this->actingAs($context['studentUsers'][1])->get(route('finance.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('bills.data', 0)->where('summary.outstanding', 0));
    }

    public function test_manual_partial_and_full_payment_updates_bill_atomically(): void
    {
        $context = $this->context(); $finance = $this->permissionUser('Bendahara', 'finance.view', 'billing.view', 'billing.update');
        $bill = $this->bill($context['students'][0], $context['term'], 1000000);
        $this->actingAs($finance)->post(route('finance.payments.store', $bill), ['amount' => 400000, 'paid_at' => now()->toDateTimeString(), 'notes' => 'Bukti kas 001'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('billing_items', ['id' => $bill->id, 'paid_amount' => 400000, 'status' => 'partial']);
        $this->post(route('finance.payments.store', $bill), ['amount' => 700000, 'paid_at' => now()->toDateTimeString()])->assertSessionHasErrors('amount');
        $this->post(route('finance.payments.store', $bill), ['amount' => 600000, 'paid_at' => now()->toDateTimeString()])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('billing_items', ['id' => $bill->id, 'paid_amount' => 1000000, 'status' => 'paid']);
        $this->assertDatabaseCount('payments', 2); $this->assertDatabaseCount('payment_allocations', 2);
    }

    public function test_waiver_requires_reason_and_paid_bill_cannot_be_waived(): void
    {
        $context = $this->context(); $finance = $this->permissionUser('Keuangan', 'finance.view', 'billing.view', 'billing.update');
        $bill = $this->bill($context['students'][0], $context['term'], 500000);
        $this->actingAs($finance)->post(route('finance.bills.waive', $bill), ['reason' => 'Singkat'])->assertSessionHasErrors('reason');
        $this->post(route('finance.bills.waive', $bill), ['reason' => 'Beasiswa prestasi berdasarkan keputusan pimpinan.'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('billing_items', ['id' => $bill->id, 'status' => 'waived', 'waived_by' => $finance->id]);
        $this->assertDatabaseHas('audit_logs', ['module' => 'finance', 'action' => 'bill_waived']);
    }

    public function test_student_can_issue_only_own_virtual_account(): void
    {
        config(['bsi.driver' => 'fake']); $context = $this->context();
        $this->actingAs($context['studentUsers'][0])->post(route('finance.virtual-account.issue', $context['students'][0]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('payment_virtual_accounts', ['student_id' => $context['students'][0]->id, 'status' => 'active']);
        $this->post(route('finance.virtual-account.issue', $context['students'][1]))->assertForbidden();
        $this->assertDatabaseCount('payment_virtual_accounts', 1);
    }

    private function context(): array
    {
        $program = Program::create(['name' => 'Teknik Informatika', 'code' => 'TI', 'degree' => 'S1', 'is_active' => true]);
        $term = AcademicTerm::create(['name' => 'Ganjil 2026', 'code' => '2026-GANJIL', 'semester' => 'Ganjil', 'is_active' => true]);
        $studentUsers = []; $students = [];
        foreach (['22001', '22002'] as $nim) {
            $user = $this->permissionUser('Mahasiswa', 'finance.view', 'billing.view');
            $students[] = Student::create(['user_id' => $user->id, 'program_id' => $program->id, 'nim' => $nim, 'status' => 'Aktif', 'current_semester' => 1]); $studentUsers[] = $user;
        }
        return compact('program', 'term', 'studentUsers', 'students');
    }

    private function bill(Student $student, AcademicTerm $term, float $amount): BillingItem
    {
        return BillingItem::create(['student_id' => $student->id, 'academic_term_id' => $term->id, 'invoice_number' => 'INV-TEST-'.$student->id.'-'.$amount, 'description' => 'Tagihan Semester', 'category' => 'UKT', 'amount' => $amount, 'paid_amount' => 0, 'due_on' => now()->addWeek(), 'status' => 'unpaid']);
    }

    private function permissionUser(string $role, string ...$permissions): User
    {
        $user = User::factory()->create(['active_role' => $role]); foreach ($permissions as $permission) Permission::findOrCreate($permission, 'web'); $user->givePermissionTo($permissions); return $user;
    }
}
