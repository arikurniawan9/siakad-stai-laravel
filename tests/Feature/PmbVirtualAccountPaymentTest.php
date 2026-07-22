<?php

namespace Tests\Feature;

use App\Domain\Pmb\PmbInvoiceService;
use App\Models\AcademicTerm;
use App\Models\PaymentVirtualAccount;
use App\Models\PmbApplication;
use App\Models\PmbFee;
use App\Models\PmbInvoice;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PmbVirtualAccountPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['bsi.driver' => 'fake', 'bsi.callback_secret' => 'test-callback-secret']);
    }

    public function test_invoice_automatically_issues_one_deterministic_pmb_virtual_account_and_portal_displays_it(): void
    {
        [$owner, $application] = $this->application();
        $invoice = app(PmbInvoiceService::class)->issue($application);
        $first = PaymentVirtualAccount::query()->sole();
        $secondInvoice = app(PmbInvoiceService::class)->issue($application->fresh());

        $this->assertSame($invoice->id, $secondInvoice->id);
        $this->assertSame($application->id, $first->pmb_application_id);
        $this->assertSame($invoice->id, $first->pmb_invoice_id);
        $this->assertSame('bsi-fake', $first->provider);
        $this->assertSame('active', $first->status);
        $this->assertSame('PMB-'.$invoice->invoice_number, $first->external_reference);
        $this->assertSame($invoice->due_at->toDateTimeString(), $first->expires_at->toDateTimeString());
        $this->assertDatabaseCount('payment_virtual_accounts', 1);
        $this->assertDatabaseHas('audit_logs', ['module' => 'pmb', 'action' => 'virtual_account_issued', 'record_id' => (string) $application->id]);

        $application->update(['status' => 'submitted', 'submitted_at' => now()]);
        $this->actingAs($owner)->get(route('pmb.application'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('application.invoice.invoiceNumber', $invoice->invoice_number)
            ->where('application.invoice.virtualAccount.number', $first->va_number)
            ->where('application.invoice.virtualAccount.provider', 'bsi-fake'));
    }

    public function test_authenticated_callback_allocates_payment_to_pmb_invoice_idempotently(): void
    {
        [, $application] = $this->application();
        $invoice = app(PmbInvoiceService::class)->issue($application);
        $va = $invoice->fresh()->virtualAccount;
        $payload = $this->payload($va, 'EVENT-FULL-001', 'PAYMENT-FULL-001', '250000.00');

        $this->signedCallback($payload)->assertOk()->assertJson(['duplicate' => false, 'invoice_status' => 'paid', 'allocated_amount' => '250000.00']);
        $this->assertDatabaseHas('pmb_invoices', ['id' => $invoice->id, 'paid_amount' => 250000, 'status' => 'paid']);
        $this->assertDatabaseHas('payments', ['pmb_application_id' => $application->id, 'pmb_invoice_id' => $invoice->id, 'external_reference' => 'PAYMENT-FULL-001', 'status' => 'allocated']);
        $this->assertSame('inactive', $va->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['module' => 'pmb', 'action' => 'payment_received', 'record_id' => (string) $application->id]);

        $this->signedCallback($payload)->assertOk()->assertJson(['duplicate' => true, 'status' => 'processed']);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('bank_webhook_events', 1);
    }

    public function test_partial_callbacks_accumulate_and_overpayment_is_rejected_without_mutating_invoice(): void
    {
        [, $application] = $this->application();
        $invoice = app(PmbInvoiceService::class)->issue($application);
        $va = $invoice->fresh()->virtualAccount;

        $this->signedCallback($this->payload($va, 'EVENT-PART-001', 'PAYMENT-PART-001', '100000.00'))
            ->assertOk()->assertJson(['invoice_status' => 'partial']);
        $this->assertDatabaseHas('pmb_invoices', ['id' => $invoice->id, 'paid_amount' => 100000, 'status' => 'partial']);
        $this->assertSame('active', $va->fresh()->status);

        $this->signedCallback($this->payload($va, 'EVENT-OVER-001', 'PAYMENT-OVER-001', '160000.00'))
            ->assertStatus(422)->assertJsonFragment(['message' => 'Nominal pembayaran melebihi sisa invoice PMB atau invoice sudah lunas.']);
        $this->assertDatabaseHas('pmb_invoices', ['id' => $invoice->id, 'paid_amount' => 100000, 'status' => 'partial']);
        $this->assertDatabaseHas('bank_webhook_events', ['event_id' => 'EVENT-OVER-001', 'status' => 'failed']);
        $this->assertDatabaseMissing('payments', ['external_reference' => 'PAYMENT-OVER-001']);

        $this->signedCallback($this->payload($va, 'EVENT-PART-002', 'PAYMENT-PART-002', '150000.00'))
            ->assertOk()->assertJson(['invoice_status' => 'paid']);
        $this->assertDatabaseHas('pmb_invoices', ['id' => $invoice->id, 'paid_amount' => 250000, 'status' => 'paid']);
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_callback_requires_signature_and_local_simulator_requires_dedicated_permission(): void
    {
        [, $application] = $this->application();
        $invoice = app(PmbInvoiceService::class)->issue($application);
        $va = $invoice->fresh()->virtualAccount;
        $payload = $this->payload($va, 'EVENT-AUTH-001', 'PAYMENT-AUTH-001', '250000.00');

        $this->postJson(route('api.bsi.va.callback'), $payload)->assertUnauthorized();
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->post(route('admin.pmb.applications.simulate-payment', $application))->assertForbidden();

        $finance = $this->userWithPermissions('pmb_payments.update');
        $this->actingAs($finance)->post(route('admin.pmb.applications.simulate-payment', $application))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('pmb_invoices', ['id' => $invoice->id, 'paid_amount' => 250000, 'status' => 'paid']);
        $this->post(route('admin.pmb.applications.simulate-payment', $application))->assertSessionHasErrors('payment');
    }

    public function test_backfill_command_issues_missing_virtual_account_once(): void
    {
        [, $application] = $this->application();
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

        $this->artisan('pmb:issue-missing-virtual-accounts')->expectsOutputToContain('VA diterbitkan: 1')->assertSuccessful();
        $this->assertDatabaseHas('payment_virtual_accounts', ['pmb_application_id' => $application->id, 'pmb_invoice_id' => $invoice->id, 'status' => 'active']);
        $this->artisan('pmb:issue-missing-virtual-accounts')->expectsOutputToContain('VA diterbitkan: 0')->assertSuccessful();
        $this->assertDatabaseCount('payment_virtual_accounts', 1);
    }

    private function application(): array
    {
        $owner = User::factory()->create();
        $program = Program::create(['name' => 'Teknik Informatika', 'code' => 'TI-'.uniqid(), 'degree' => 'S1', 'is_active' => true]);
        $term = AcademicTerm::create(['name' => 'PMB 2026', 'code' => 'PMB-'.uniqid(), 'semester' => 'Ganjil', 'is_active' => true]);
        PmbFee::create(['academic_term_id' => $term->id, 'name' => 'Biaya Pendaftaran', 'registration_path' => 'Semua', 'registration_type' => 'Semua', 'amount' => 250000, 'due_days' => 3, 'is_active' => true]);
        $application = PmbApplication::create([
            'user_id' => $owner->id,
            'program_id' => $program->id,
            'registration_number' => 'PMB-2026-'.$owner->id,
            'full_name' => $owner->name,
            'email' => $owner->email,
            'phone' => '081234567890',
            'registration_path' => 'Reguler',
            'registration_type' => 'Baru',
            'status' => 'draft',
        ]);

        return [$owner, $application];
    }

    private function payload(PaymentVirtualAccount $va, string $eventId, string $paymentReference, string $amount): array
    {
        return ['provider' => $va->provider, 'event_id' => $eventId, 'va_number' => $va->va_number, 'external_reference' => $paymentReference, 'amount' => $amount, 'currency' => 'IDR', 'paid_at' => now()->toIso8601String()];
    }

    private function signedCallback(array $payload): \Illuminate\Testing\TestResponse
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $json, (string) config('bsi.callback_secret'));

        return $this->call('POST', route('api.bsi.va.callback'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIAKAD_SIGNATURE' => $signature], $json);
    }

    private function userWithPermissions(string ...$permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permissions);

        return $user;
    }
}
