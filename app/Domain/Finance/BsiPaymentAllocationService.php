<?php

namespace App\Domain\Finance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

final class BsiPaymentAllocationService
{
    /** Process an authenticated bank event atomically. */
    public function process(array $payload): array
    {
        try {
            return DB::transaction(function () use ($payload): array {
            $provider = (string) ($payload['provider'] ?? 'bsi');
            $eventId = (string) $payload['event_id'];
            $amount = self::toCents((string) $payload['amount']);
            $event = DB::table('bank_webhook_events')->where(['provider' => $provider, 'event_id' => $eventId])->lockForUpdate()->first();
            if ($event) return ['duplicate' => true, 'event_id' => $eventId, 'status' => $event->status];

            $eventPk = DB::table('bank_webhook_events')->insertGetId(['provider' => $provider, 'event_id' => $eventId, 'event_type' => 'va.payment.received', 'status' => 'received', 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            if ($amount <= 0 || ($payload['currency'] ?? 'IDR') !== 'IDR') {
                DB::table('bank_webhook_events')->where('id', $eventPk)->update(['status' => 'failed', 'error_message' => 'Nominal atau currency tidak valid', 'updated_at' => now()]);
                throw new RuntimeException('Nominal pembayaran tidak valid.');
            }

            $va = DB::table('payment_virtual_accounts')->where('va_number', $payload['va_number'])->lockForUpdate()->first();
            if (! $va || $va->status !== 'active' || ($va->expires_at && Carbon::parse($va->expires_at)->isPast())) {
                DB::table('bank_webhook_events')->where('id', $eventPk)->update(['status' => 'failed', 'error_message' => 'VA tidak dikenal atau tidak aktif', 'updated_at' => now()]);
                throw new RuntimeException('Virtual Account tidak dikenal atau tidak aktif.');
            }
            if ($va->pmb_invoice_id) {
                return $this->processPmbPayment($payload, $provider, $eventId, $eventPk, $amount, $va);
            }
            if (! $va->student_id) {
                DB::table('bank_webhook_events')->where('id', $eventPk)->update(['status' => 'failed', 'error_message' => 'VA tidak memiliki pemilik yang valid', 'updated_at' => now()]);
                throw new RuntimeException('Virtual Account tidak memiliki pemilik yang valid.');
            }

            $paymentId = DB::table('payments')->insertGetId(['student_id' => $va->student_id, 'bank_webhook_event_id' => $eventPk, 'provider' => $provider, 'external_reference' => (string) ($payload['external_reference'] ?? Str::uuid()), 'amount' => self::toMoney($amount), 'currency' => 'IDR', 'paid_at' => $payload['paid_at'] ?? now(), 'status' => 'received', 'created_at' => now(), 'updated_at' => now()]);
            $remaining = $amount;
            $allocated = 0.0;
            $bills = DB::table('billing_items')->where('student_id', $va->student_id)->whereIn('status', ['unpaid', 'partial'])->orderByRaw('due_on IS NULL, due_on ASC')->orderBy('created_at')->lockForUpdate()->get();
            foreach ($bills as $bill) {
                if ($remaining <= 0) break;
                $open = max(0, self::toCents((string) $bill->amount) - self::toCents((string) $bill->paid_amount));
                $allocation = min($remaining, $open);
                if ($allocation <= 0) continue;
                DB::table('payment_allocations')->insert(['payment_id' => $paymentId, 'billing_item_id' => $bill->id, 'amount' => self::toMoney($allocation), 'created_at' => now(), 'updated_at' => now()]);
                $newPaid = self::toCents((string) $bill->paid_amount) + $allocation;
                DB::table('billing_items')->where('id', $bill->id)->update(['paid_amount' => self::toMoney($newPaid), 'status' => $newPaid >= self::toCents((string) $bill->amount) ? 'paid' : 'partial', 'updated_at' => now()]);
                $remaining -= $allocation;
                $allocated += $allocation;
            }

            if ($remaining > 0) {
                $lastBalance = self::toCents((string) (DB::table('deposit_ledger_entries')->where('student_id', $va->student_id)->latest('id')->value('balance_after') ?? '0'));
                DB::table('deposit_ledger_entries')->insert(['student_id' => $va->student_id, 'payment_id' => $paymentId, 'amount' => self::toMoney($remaining), 'balance_after' => self::toMoney($lastBalance + $remaining), 'entry_type' => 'credit', 'description' => 'Kelebihan pembayaran VA BSI', 'created_at' => now(), 'updated_at' => now()]);
            }
            $status = $allocated > 0 ? ($remaining > 0 ? 'partial' : 'allocated') : 'received';
            DB::table('payments')->where('id', $paymentId)->update(['status' => $status, 'updated_at' => now()]);
            DB::table('bank_webhook_events')->where('id', $eventPk)->update(['status' => 'processed', 'processed_at' => now(), 'updated_at' => now()]);

            return ['duplicate' => false, 'event_id' => $eventId, 'payment_id' => $paymentId, 'allocated_amount' => self::toMoney($allocated), 'deposit_amount' => self::toMoney($remaining), 'status' => 'processed'];
            });
        } catch (Throwable $exception) {
            $provider = (string) ($payload['provider'] ?? 'bsi');
            $eventId = (string) ($payload['event_id'] ?? 'unknown');
            DB::table('bank_webhook_events')->insertOrIgnore([
                'provider' => $provider,
                'event_id' => $eventId,
                'event_type' => 'va.payment.received',
                'status' => 'failed',
                'payload' => json_encode($payload),
                'error_message' => Str::limit($exception->getMessage(), 1000),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            throw $exception;
        }
    }

    private function processPmbPayment(array $payload, string $provider, string $eventId, int $eventPk, int $amount, object $va): array
    {
        $invoice = DB::table('pmb_invoices')->where('id', $va->pmb_invoice_id)->lockForUpdate()->first();
        if (! $invoice || (int) $invoice->pmb_application_id !== (int) $va->pmb_application_id) {
            throw new RuntimeException('Invoice PMB untuk Virtual Account tidak valid.');
        }

        $externalReference = (string) ($payload['external_reference'] ?? "{$provider}-{$eventId}");
        $existingPayment = DB::table('payments')->where('external_reference', $externalReference)->lockForUpdate()->first();
        if ($existingPayment) {
            DB::table('bank_webhook_events')->where('id', $eventPk)->update(['status' => 'processed', 'processed_at' => now(), 'updated_at' => now()]);
            return ['duplicate' => true, 'event_id' => $eventId, 'payment_id' => $existingPayment->id, 'status' => 'processed'];
        }

        $total = self::toCents((string) $invoice->amount);
        $paid = self::toCents((string) $invoice->paid_amount);
        $outstanding = max(0, $total - $paid);
        if ($outstanding === 0 || $amount > $outstanding) {
            throw new RuntimeException('Nominal pembayaran melebihi sisa invoice PMB atau invoice sudah lunas.');
        }

        $paymentId = DB::table('payments')->insertGetId([
            'pmb_application_id' => $invoice->pmb_application_id,
            'pmb_invoice_id' => $invoice->id,
            'bank_webhook_event_id' => $eventPk,
            'provider' => $provider,
            'external_reference' => $externalReference,
            'amount' => self::toMoney($amount),
            'currency' => 'IDR',
            'paid_at' => $payload['paid_at'] ?? now(),
            'status' => 'allocated',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $newPaid = $paid + $amount;
        $invoiceStatus = $newPaid >= $total ? 'paid' : 'partial';
        DB::table('pmb_invoices')->where('id', $invoice->id)->update(['paid_amount' => self::toMoney($newPaid), 'status' => $invoiceStatus, 'updated_at' => now()]);
        if ($invoiceStatus === 'paid') DB::table('payment_virtual_accounts')->where('id', $va->id)->update(['status' => 'inactive', 'updated_at' => now()]);
        DB::table('bank_webhook_events')->where('id', $eventPk)->update(['status' => 'processed', 'processed_at' => now(), 'updated_at' => now()]);
        DB::table('audit_logs')->insert([
            'module' => 'pmb',
            'action' => 'payment_received',
            'record_type' => 'pmb_application',
            'record_id' => (string) $invoice->pmb_application_id,
            'new_data' => json_encode(['invoice_id' => $invoice->id, 'payment_id' => $paymentId, 'amount' => self::toMoney($amount), 'invoice_status' => $invoiceStatus]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'duplicate' => false,
            'event_id' => $eventId,
            'payment_id' => $paymentId,
            'pmb_invoice_id' => $invoice->id,
            'allocated_amount' => self::toMoney($amount),
            'deposit_amount' => '0.00',
            'invoice_status' => $invoiceStatus,
            'status' => 'processed',
        ];
    }

    private static function toCents(string $value): int
    {
        $value = trim($value);
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');
        $cents = ((int) ($whole ?: '0') * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
        return $negative ? -$cents : $cents;
    }

    private static function toMoney(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
