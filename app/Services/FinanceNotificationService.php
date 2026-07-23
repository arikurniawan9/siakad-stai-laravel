<?php

namespace App\Services;

use App\Integrations\WhatsApp\Contracts\WhatsAppGateway;
use App\Mail\FinanceNotificationMail;
use App\Models\BillingItem;
use App\Models\OutboundNotification;
use App\Models\Payment;
use App\Models\PmbInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

final class FinanceNotificationService
{
    public function __construct(
        private readonly NotificationService $inApp,
        private readonly WhatsAppGateway $whatsApp,
    ) {}

    public function billIssued(BillingItem $bill): void
    {
        $bill->loadMissing('student.user');
        $amount = $this->rupiah($bill->amount);
        $due = $bill->due_on?->translatedFormat('d F Y') ?? 'tanpa batas waktu';
        $this->studentEvent(
            $bill->student->user,
            $bill->student->phone,
            "billing:{$bill->id}:issued",
            'billing_issued',
            'Tagihan baru diterbitkan',
            "{$bill->description} sebesar {$amount} telah diterbitkan dan jatuh tempo {$due}.",
            $bill->invoice_number,
            ['Nomor tagihan' => $bill->invoice_number, 'Jenis tagihan' => $bill->description, 'Nominal' => $amount, 'Jatuh tempo' => $due],
            '/finance',
        );
    }

    public function billPaymentUpdated(BillingItem $bill, Payment|int $payment, string|float|int|null $amount = null): void
    {
        $bill->loadMissing('student.user');
        $paymentId = $payment instanceof Payment ? $payment->id : $payment;
        $paidAmount = $payment instanceof Payment ? $payment->amount : $amount;
        $isPaid = $bill->status === 'paid';
        $title = $isPaid ? 'Tagihan telah lunas' : 'Pembayaran diterima';
        $remaining = max(0, (float) $bill->amount - (float) $bill->paid_amount);
        $message = 'Pembayaran '.$this->rupiah($paidAmount)." untuk {$bill->description} telah diterima.";
        $message .= $isPaid ? ' Tagihan ini sekarang berstatus lunas.' : ' Sisa tagihan '.$this->rupiah($remaining).'.';

        $this->studentEvent(
            $bill->student->user,
            $bill->student->phone,
            "billing:{$bill->id}:payment:{$paymentId}",
            $isPaid ? 'billing_paid' : 'billing_payment_received',
            $title,
            $message,
            $bill->invoice_number,
            ['Nomor tagihan' => $bill->invoice_number, 'Jenis tagihan' => $bill->description, 'Pembayaran' => $this->rupiah($paidAmount), 'Sisa tagihan' => $this->rupiah($remaining), 'Status' => $isPaid ? 'Lunas' : 'Sebagian'],
            '/finance',
        );
    }

    public function billWaived(BillingItem $bill): void
    {
        $bill->loadMissing('student.user');
        $this->studentEvent(
            $bill->student->user,
            $bill->student->phone,
            "billing:{$bill->id}:waived",
            'billing_waived',
            'Tagihan telah dibebaskan',
            "{$bill->description} sebesar {$this->rupiah($bill->amount)} telah dibebaskan berdasarkan persetujuan unit keuangan.",
            $bill->invoice_number,
            ['Nomor tagihan' => $bill->invoice_number, 'Jenis tagihan' => $bill->description, 'Nominal' => $this->rupiah($bill->amount), 'Status' => 'Dibebaskan'],
            '/finance',
        );
    }

    public function pmbInvoiceIssued(PmbInvoice $invoice): void
    {
        $invoice->loadMissing('application.user');
        $application = $invoice->application;
        $due = $invoice->due_at?->translatedFormat('d F Y H:i') ?? 'tanpa batas waktu';
        $this->studentEvent(
            $application->user,
            $application->phone,
            "pmb-invoice:{$invoice->id}:issued",
            'pmb_billing_issued',
            'Tagihan pendaftaran diterbitkan',
            "{$invoice->description} sebesar {$this->rupiah($invoice->amount)} telah diterbitkan dan jatuh tempo {$due}.",
            $invoice->invoice_number,
            ['Nomor tagihan' => $invoice->invoice_number, 'Jenis tagihan' => $invoice->description, 'Nominal' => $this->rupiah($invoice->amount), 'Jatuh tempo' => $due],
            '/pmb/application',
            $application->email,
        );
    }

    public function pmbPaymentUpdated(PmbInvoice $invoice, int $paymentId, string|float|int $amount): void
    {
        $invoice->loadMissing('application.user');
        $application = $invoice->application;
        $isPaid = $invoice->status === 'paid';
        $remaining = max(0, (float) $invoice->amount - (float) $invoice->paid_amount);
        $title = $isPaid ? 'Tagihan pendaftaran telah lunas' : 'Pembayaran pendaftaran diterima';
        $message = 'Pembayaran '.$this->rupiah($amount)." untuk {$invoice->description} telah diterima.";
        $message .= $isPaid ? ' Tagihan pendaftaran sekarang berstatus lunas.' : ' Sisa tagihan '.$this->rupiah($remaining).'.';

        $this->studentEvent(
            $application->user,
            $application->phone,
            "pmb-invoice:{$invoice->id}:payment:{$paymentId}",
            $isPaid ? 'pmb_billing_paid' : 'pmb_billing_payment_received',
            $title,
            $message,
            $invoice->invoice_number,
            ['Nomor tagihan' => $invoice->invoice_number, 'Jenis tagihan' => $invoice->description, 'Pembayaran' => $this->rupiah($amount), 'Sisa tagihan' => $this->rupiah($remaining), 'Status' => $isPaid ? 'Lunas' : 'Sebagian'],
            '/pmb/application',
            $application->email,
        );
    }

    public function queueDueReminders(): array
    {
        $queued = 0;
        foreach (config('finance_notifications.reminder_days', []) as $daysBeforeDue) {
            $targetDate = today()->addDays((int) $daysBeforeDue)->toDateString();
            BillingItem::query()->with('student.user')->whereHas('student.user')->whereIn('status', ['unpaid', 'partial'])->whereDate('due_on', $targetDate)->chunkById(100, function ($bills) use (&$queued, $daysBeforeDue): void {
                foreach ($bills as $bill) {
                    $queued += $this->billReminder($bill, (int) $daysBeforeDue);
                }
            });
            PmbInvoice::query()->with('application.user')->whereHas('application.user')->whereIn('status', ['unpaid', 'partial'])->whereDate('due_at', $targetDate)->chunkById(100, function ($invoices) use (&$queued, $daysBeforeDue): void {
                foreach ($invoices as $invoice) {
                    $queued += $this->pmbReminder($invoice, (int) $daysBeforeDue);
                }
            });
        }

        return ['queued' => $queued];
    }

    public function dispatchPending(?int $limit = null): array
    {
        $limit ??= (int) config('finance_notifications.dispatch.batch_size', 100);
        $maxAttempts = (int) config('finance_notifications.dispatch.max_attempts', 5);
        OutboundNotification::query()->where('status', 'processing')->where('updated_at', '<', now()->subMinutes(15))->update(['status' => 'failed', 'available_at' => now()]);
        $ids = OutboundNotification::query()->whereIn('channel', ['email', 'whatsapp'])->whereIn('status', ['pending', 'failed'])->where('attempts', '<', $maxAttempts)->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))->oldest('id')->limit(max(1, $limit))->pluck('id');
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($ids as $id) {
            $claimed = OutboundNotification::query()->whereKey($id)->whereIn('status', ['pending', 'failed'])->update(['status' => 'processing', 'updated_at' => now()]);
            if (! $claimed) continue;
            $notification = OutboundNotification::findOrFail($id);
            try {
                if (blank($notification->recipient)) {
                    $notification->update(['status' => 'skipped', 'last_error' => 'Alamat penerima tidak tersedia.']);
                    $result['skipped']++;
                    continue;
                }
                $providerId = $notification->channel === 'email'
                    ? $this->sendEmail($notification)
                    : $this->whatsApp->sendTemplate($notification->recipient, $notification->payload['template_parameters'] ?? []);
                $notification->update(['status' => 'sent', 'attempts' => $notification->attempts + 1, 'sent_at' => now(), 'provider_message_id' => $providerId, 'last_error' => null]);
                $result['sent']++;
            } catch (Throwable $exception) {
                $attempts = $notification->attempts + 1;
                $notification->update(['status' => 'failed', 'attempts' => $attempts, 'available_at' => now()->addMinutes((int) config('finance_notifications.dispatch.retry_minutes', 5)), 'last_error' => Str::limit($exception->getMessage(), 5000)]);
                $result['failed']++;
            }
        }

        return $result;
    }

    private function billReminder(BillingItem $bill, int $daysBeforeDue): int
    {
        $due = $bill->due_on->translatedFormat('d F Y');
        $remaining = max(0, (float) $bill->amount - (float) $bill->paid_amount);
        $timing = $this->timing($daysBeforeDue);
        return $this->studentEvent($bill->student->user, $bill->student->phone, "billing:{$bill->id}:reminder:{$daysBeforeDue}", 'billing_due_reminder', 'Pengingat tagihan '.$timing, "{$bill->description} memiliki sisa {$this->rupiah($remaining)} dan jatuh tempo {$due}.", $bill->invoice_number, ['Nomor tagihan' => $bill->invoice_number, 'Jenis tagihan' => $bill->description, 'Sisa tagihan' => $this->rupiah($remaining), 'Jatuh tempo' => $due], '/finance');
    }

    private function pmbReminder(PmbInvoice $invoice, int $daysBeforeDue): int
    {
        $application = $invoice->application;
        $due = $invoice->due_at->translatedFormat('d F Y H:i');
        $remaining = max(0, (float) $invoice->amount - (float) $invoice->paid_amount);
        $timing = $this->timing($daysBeforeDue);
        return $this->studentEvent($application->user, $application->phone, "pmb-invoice:{$invoice->id}:reminder:{$daysBeforeDue}", 'pmb_billing_due_reminder', 'Pengingat tagihan pendaftaran '.$timing, "{$invoice->description} memiliki sisa {$this->rupiah($remaining)} dan jatuh tempo {$due}.", $invoice->invoice_number, ['Nomor tagihan' => $invoice->invoice_number, 'Jenis tagihan' => $invoice->description, 'Sisa tagihan' => $this->rupiah($remaining), 'Jatuh tempo' => $due], '/pmb/application', $application->email);
    }

    private function studentEvent(?User $user, ?string $phone, string $eventKey, string $eventType, string $title, string $message, string $reference, array $details, string $link, ?string $email = null): int
    {
        if (! $user) return 0;
        $absoluteLink = url($link);
        $payload = ['details' => [...$details, 'link' => $absoluteLink], 'link' => $link, 'template_parameters' => [$user->name, $title, $message, $reference, $absoluteLink]];
        $created = 0;

        DB::transaction(function () use ($user, $eventKey, $eventType, $title, $message, $link, $payload, &$created): void {
            $delivery = OutboundNotification::query()->firstOrCreate(['channel' => 'in_app', 'event_key' => $eventKey], ['user_id' => $user->id, 'event_type' => $eventType, 'subject' => $title, 'content' => $message, 'payload' => $payload, 'status' => 'processing', 'attempts' => 1, 'available_at' => now()]);
            if (! $delivery->wasRecentlyCreated) return;
            $this->inApp->send($user->id, 'finance', $title, $message, $link, ['event_type' => $eventType, 'event_key' => $eventKey]);
            $delivery->update(['status' => 'sent', 'sent_at' => now()]);
            $created++;
        }, 3);

        if (config('finance_notifications.email.enabled')) {
            $created += $this->queueChannel('email', $eventKey, $eventType, $user, $email ?: $user->email, $title, $message, $payload);
        }
        if (config('finance_notifications.whatsapp.enabled')) {
            $created += $this->queueChannel('whatsapp', $eventKey, $eventType, $user, $this->normalizePhone($phone), $title, $message, $payload);
        }

        return $created;
    }

    private function queueChannel(string $channel, string $eventKey, string $eventType, User $user, ?string $recipient, string $title, string $message, array $payload): int
    {
        $delivery = OutboundNotification::query()->firstOrCreate(['channel' => $channel, 'event_key' => $eventKey], ['user_id' => $user->id, 'event_type' => $eventType, 'recipient' => $recipient, 'subject' => $title, 'content' => $message, 'payload' => $payload, 'status' => $recipient ? 'pending' : 'skipped', 'available_at' => now(), 'last_error' => $recipient ? null : 'Alamat penerima tidak tersedia.']);

        return $delivery->wasRecentlyCreated ? 1 : 0;
    }

    private function sendEmail(OutboundNotification $notification): string
    {
        Mail::to($notification->recipient)->send(new FinanceNotificationMail($notification->subject ?? 'Notifikasi keuangan', $notification->content, $notification->payload['details'] ?? []));

        return 'mail-'.Str::lower((string) Str::ulid());
    }

    private function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (! $digits) return null;
        if (str_starts_with($digits, '0')) $digits = (string) config('finance_notifications.whatsapp.default_country_code', '62').substr($digits, 1);

        return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits : null;
    }

    private function rupiah(string|float|int|null $amount): string
    {
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
    }

    private function timing(int $daysBeforeDue): string
    {
        if ($daysBeforeDue > 0) return "{$daysBeforeDue} hari sebelum jatuh tempo";
        if ($daysBeforeDue === 0) return 'pada hari jatuh tempo';

        return abs($daysBeforeDue).' hari setelah jatuh tempo';
    }
}
