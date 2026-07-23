<?php

namespace Tests\Feature;

use App\Domain\Finance\StudentFinanceService;
use App\Domain\Finance\BsiPaymentAllocationService;
use App\Domain\Pmb\PmbInvoiceService;
use App\Mail\FinanceNotificationMail;
use App\Models\AcademicTerm;
use App\Models\BillingItem;
use App\Models\OutboundNotification;
use App\Models\PmbApplication;
use App\Models\PmbFee;
use App\Models\PaymentVirtualAccount;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use App\Services\FinanceNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class FinanceOutboundNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('finance_notifications.email.enabled', true);
        config()->set('finance_notifications.whatsapp.enabled', true);
        config()->set('finance_notifications.whatsapp.driver', 'log');
        Mail::fake();
    }

    public function test_bill_issue_queues_idempotent_in_app_email_and_whatsapp_messages(): void
    {
        $context = $this->studentContext();
        $bill = $this->issueBill($context);

        app(FinanceNotificationService::class)->billIssued($bill);

        $this->assertDatabaseCount('outbound_notifications', 3);
        $this->assertDatabaseCount('system_notifications', 1);
        $this->assertDatabaseHas('outbound_notifications', ['channel' => 'in_app', 'event_type' => 'billing_issued', 'status' => 'sent']);
        $this->assertDatabaseHas('outbound_notifications', ['channel' => 'email', 'recipient' => $context['user']->email, 'status' => 'pending']);
        $this->assertDatabaseHas('outbound_notifications', ['channel' => 'whatsapp', 'recipient' => '6281234567890', 'status' => 'pending']);
    }

    public function test_dispatch_command_sends_email_and_safe_local_whatsapp_log(): void
    {
        $context = $this->studentContext();
        $this->issueBill($context);

        $this->artisan('finance:dispatch-notifications')->assertSuccessful();

        $this->assertSame(2, OutboundNotification::query()->whereIn('channel', ['email', 'whatsapp'])->where('status', 'sent')->count());
        $this->assertDatabaseMissing('outbound_notifications', ['channel' => 'whatsapp', 'provider_message_id' => null]);
        Mail::assertSent(FinanceNotificationMail::class, fn (FinanceNotificationMail $mail) => $mail->hasTo($context['user']->email) && $mail->mailSubject === 'Tagihan baru diterbitkan');
    }

    public function test_partial_and_full_payments_create_distinct_status_notifications(): void
    {
        $context = $this->studentContext();
        $bill = $this->issueBill($context);
        $service = app(StudentFinanceService::class);

        $partial = $service->recordManualPayment($bill, ['amount' => 400000, 'paid_at' => now(), 'notes' => null], $context['actor']);
        $full = $service->recordManualPayment($bill->fresh(), ['amount' => 600000, 'paid_at' => now(), 'notes' => null], $context['actor']);

        $this->assertDatabaseHas('outbound_notifications', ['event_key' => "billing:{$bill->id}:payment:{$partial->id}", 'event_type' => 'billing_payment_received', 'channel' => 'email']);
        $this->assertDatabaseHas('outbound_notifications', ['event_key' => "billing:{$bill->id}:payment:{$full->id}", 'event_type' => 'billing_paid', 'channel' => 'whatsapp']);
        $this->assertDatabaseHas('system_notifications', ['user_id' => $context['user']->id, 'title' => 'Tagihan telah lunas']);
        $this->assertDatabaseCount('outbound_notifications', 9);
    }

    public function test_due_reminders_are_scheduled_once_for_each_configured_offset(): void
    {
        $context = $this->studentContext();
        $bill = BillingItem::create(['student_id' => $context['student']->id, 'academic_term_id' => $context['term']->id, 'invoice_number' => 'INV-REMINDER-001', 'description' => 'SPP Semester', 'category' => 'SPP', 'amount' => 750000, 'paid_amount' => 0, 'due_on' => today()->addDays(7), 'status' => 'unpaid']);

        $first = app(FinanceNotificationService::class)->queueDueReminders();
        $second = app(FinanceNotificationService::class)->queueDueReminders();

        $this->assertSame(3, $first['queued']);
        $this->assertSame(0, $second['queued']);
        $this->assertDatabaseCount('outbound_notifications', 3);
        $this->assertDatabaseHas('outbound_notifications', ['event_key' => "billing:{$bill->id}:reminder:7", 'event_type' => 'billing_due_reminder', 'channel' => 'email']);
    }

    public function test_missing_phone_is_skipped_without_blocking_email(): void
    {
        $context = $this->studentContext(phone: null);
        $this->issueBill($context);

        $this->assertDatabaseHas('outbound_notifications', ['channel' => 'whatsapp', 'recipient' => null, 'status' => 'skipped']);
        $this->assertDatabaseHas('outbound_notifications', ['channel' => 'email', 'recipient' => $context['user']->email, 'status' => 'pending']);
    }

    public function test_bank_callback_that_settles_bill_queues_paid_notification(): void
    {
        $context = $this->studentContext();
        $bill = BillingItem::create(['student_id' => $context['student']->id, 'academic_term_id' => $context['term']->id, 'invoice_number' => 'INV-VA-NOTIFY-001', 'description' => 'SPP Semester', 'category' => 'SPP', 'amount' => 900000, 'paid_amount' => 0, 'due_on' => today()->addWeek(), 'status' => 'unpaid']);
        PaymentVirtualAccount::create(['student_id' => $context['student']->id, 'provider' => 'bsi-fake', 'va_number' => '88000000000001', 'external_reference' => 'VA-NOTIFY-001', 'status' => 'active', 'expires_at' => now()->addWeek()]);

        $result = app(BsiPaymentAllocationService::class)->process(['provider' => 'bsi-fake', 'event_id' => 'EVENT-NOTIFY-001', 'va_number' => '88000000000001', 'external_reference' => 'PAY-NOTIFY-001', 'amount' => '900000.00', 'currency' => 'IDR', 'paid_at' => now()->toIso8601String()]);

        $this->assertFalse($result['duplicate']);
        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertDatabaseHas('outbound_notifications', ['event_key' => "billing:{$bill->id}:payment:{$result['payment_id']}", 'event_type' => 'billing_paid', 'channel' => 'email']);
        $this->assertDatabaseHas('system_notifications', ['user_id' => $context['user']->id, 'title' => 'Tagihan telah lunas']);
    }

    public function test_pmb_invoice_notification_is_queued_only_once(): void
    {
        $term = AcademicTerm::create(['name' => 'PMB 2026', 'code' => 'PMB-2026', 'semester' => 'Ganjil', 'is_active' => true]);
        $program = Program::create(['name' => 'Teknik Informatika', 'code' => 'TI-PMB', 'degree' => 'S1', 'is_active' => true]);
        $user = User::factory()->create();
        $application = PmbApplication::create(['user_id' => $user->id, 'program_id' => $program->id, 'registration_number' => 'PMB-NOTIF-001', 'full_name' => $user->name, 'email' => $user->email, 'phone' => '081298765432', 'registration_path' => 'Reguler', 'registration_type' => 'Baru', 'status' => 'draft']);
        PmbFee::create(['academic_term_id' => $term->id, 'name' => 'Biaya Pendaftaran', 'registration_path' => 'Semua', 'registration_type' => 'Semua', 'amount' => 300000, 'due_days' => 3, 'is_active' => true]);

        $service = app(PmbInvoiceService::class);
        $invoice = $service->issue($application);
        $service->issue($application->fresh());

        $this->assertDatabaseCount('outbound_notifications', 3);
        $this->assertDatabaseHas('outbound_notifications', ['event_key' => "pmb-invoice:{$invoice->id}:issued", 'event_type' => 'pmb_billing_issued', 'channel' => 'whatsapp', 'recipient' => '6281298765432']);
    }

    private function issueBill(array $context): BillingItem
    {
        return app(StudentFinanceService::class)->createBill($context['student'], $context['term'], ['description' => 'SPP Semester Ganjil', 'category' => 'SPP', 'amount' => 1000000, 'due_on' => today()->addDays(7), 'notes' => null], $context['actor']);
    }

    private function studentContext(?string $phone = '081234567890'): array
    {
        $user = User::factory()->create(['active_role' => 'Mahasiswa']);
        $actor = User::factory()->create(['active_role' => 'Keuangan']);
        $program = Program::create(['name' => 'Sistem Informasi', 'code' => 'SI-NOTIF-'.uniqid(), 'degree' => 'S1', 'is_active' => true]);
        $term = AcademicTerm::create(['name' => 'Ganjil 2026', 'code' => '2026-NOTIF-'.uniqid(), 'semester' => 'Ganjil', 'is_active' => true]);
        $student = Student::create(['user_id' => $user->id, 'program_id' => $program->id, 'nim' => 'NIM-'.uniqid(), 'status' => 'Aktif', 'current_semester' => 1, 'phone' => $phone]);

        return compact('user', 'actor', 'program', 'term', 'student');
    }
}
