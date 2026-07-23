<?php

namespace App\Domain\Finance;

use App\Integrations\Bsi\Contracts\VirtualAccountGateway;
use App\Models\AcademicTerm;
use App\Models\BillingItem;
use App\Models\Payment;
use App\Models\PaymentVirtualAccount;
use App\Models\Student;
use App\Models\User;
use App\Services\FinanceNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

final class StudentFinanceService
{
    public function __construct(
        private readonly VirtualAccountGateway $gateway,
        private readonly FinanceNotificationService $notifications,
    ) {}

    public function createBill(Student $student, AcademicTerm $term, array $data, User $actor): BillingItem
    {
        $reference = 'INV-'.$term->code.'-'.($student->nim ?: $student->id).'-'.strtoupper(Str::random(6));
        return DB::transaction(function () use ($student, $term, $data, $actor, $reference): BillingItem {
            $bill = BillingItem::create([...$data, 'student_id' => $student->id, 'academic_term_id' => $term->id, 'invoice_number' => $reference, 'paid_amount' => 0, 'status' => 'unpaid', 'created_by' => $actor->id]);
            $this->notifications->billIssued($bill);

            return $bill;
        }, 3);
    }

    public function waive(BillingItem $bill, string $reason, User $actor): BillingItem
    {
        if ($bill->status === 'paid') throw ValidationException::withMessages(['bill' => 'Tagihan yang sudah lunas tidak dapat dibebaskan.']);
        return DB::transaction(function () use ($bill, $reason, $actor): BillingItem {
            $bill = BillingItem::query()->lockForUpdate()->findOrFail($bill->id);
            if ($bill->status === 'paid') throw ValidationException::withMessages(['bill' => 'Tagihan yang sudah lunas tidak dapat dibebaskan.']);
            $bill->update(['status' => 'waived', 'waived_by' => $actor->id, 'waiver_reason' => $reason, 'waived_at' => now()]);
            $bill = $bill->fresh();
            $this->notifications->billWaived($bill);

            return $bill;
        }, 3);
    }

    public function recordManualPayment(BillingItem $bill, array $data, User $actor): Payment
    {
        return DB::transaction(function () use ($bill, $data, $actor): Payment {
            $bill = BillingItem::query()->lockForUpdate()->findOrFail($bill->id);
            if (! in_array($bill->status, ['unpaid', 'partial'], true)) throw ValidationException::withMessages(['bill' => 'Tagihan ini tidak lagi menerima pembayaran.']);
            $outstanding = round((float) $bill->amount - (float) $bill->paid_amount, 2);
            $amount = round((float) $data['amount'], 2);
            if ($amount > $outstanding) throw ValidationException::withMessages(['amount' => 'Nominal melebihi sisa tagihan '.number_format($outstanding, 0, ',', '.').'.']);
            $payment = Payment::create(['student_id' => $bill->student_id, 'provider' => 'manual', 'external_reference' => 'MANUAL-'.strtoupper(Str::uuid()), 'amount' => $amount, 'currency' => 'IDR', 'paid_at' => $data['paid_at'], 'status' => 'allocated', 'recorded_by' => $actor->id, 'notes' => $data['notes'] ?? null]);
            $payment->allocations()->create(['billing_item_id' => $bill->id, 'amount' => $amount]);
            $newPaid = round((float) $bill->paid_amount + $amount, 2);
            $bill->update(['paid_amount' => $newPaid, 'status' => $newPaid >= (float) $bill->amount ? 'paid' : 'partial']);
            $this->notifications->billPaymentUpdated($bill->fresh(), $payment);
            return $payment;
        }, 3);
    }

    public function issueVirtualAccount(Student $student): PaymentVirtualAccount
    {
        if (app()->environment('production') && config('bsi.driver') === 'fake') throw new LogicException('Adapter VA fake tidak boleh digunakan pada production.');
        return DB::transaction(function () use ($student): PaymentVirtualAccount {
            $student = Student::query()->with('user')->lockForUpdate()->findOrFail($student->id);
            $existing = PaymentVirtualAccount::query()->where('student_id', $student->id)->whereIn('status', ['pending', 'active'])->lockForUpdate()->first();
            if ($existing) return $existing;
            $issued = $this->gateway->create('MHS-'.($student->nim ?: $student->id), $student->user->name);
            return PaymentVirtualAccount::create(['student_id' => $student->id, 'provider' => $issued->provider, 'va_number' => $issued->vaNumber, 'external_reference' => $issued->reference, 'status' => in_array($issued->status, ['pending', 'active', 'inactive', 'expired'], true) ? $issued->status : 'pending', 'expires_at' => $issued->expiresAt, 'metadata' => [...$issued->metadata, 'open_amount' => true, 'adapter' => config('bsi.driver')]]);
        }, 3);
    }
}
