<?php

namespace App\Domain\Pmb;

use App\Integrations\Bsi\Contracts\VirtualAccountGateway;
use App\Models\PaymentVirtualAccount;
use App\Models\PmbApplication;
use App\Models\PmbInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use LogicException;

final class PmbVirtualAccountService
{
    public function __construct(private readonly VirtualAccountGateway $gateway) {}

    public function issue(PmbApplication $application, PmbInvoice $invoice): PaymentVirtualAccount
    {
        if (app()->environment('production') && config('bsi.driver') === 'fake') {
            throw new LogicException('Adapter VA fake tidak boleh digunakan pada production.');
        }

        return DB::transaction(function () use ($application, $invoice): PaymentVirtualAccount {
            $application = PmbApplication::query()->lockForUpdate()->findOrFail($application->id);
            $invoice = PmbInvoice::query()->where('pmb_application_id', $application->id)->lockForUpdate()->findOrFail($invoice->id);
            $existing = PaymentVirtualAccount::query()->where('pmb_application_id', $application->id)->lockForUpdate()->first();
            if ($existing) return $existing;

            $reference = 'PMB-'.$invoice->invoice_number;
            $issued = $this->gateway->create($reference, $application->full_name, $invoice->amount);
            $status = in_array($issued->status, ['pending', 'active', 'inactive', 'expired'], true) ? $issued->status : 'pending';
            $gatewayExpiry = $issued->expiresAt ? Carbon::parse($issued->expiresAt) : null;
            $expiresAt = $invoice->due_at && (! $gatewayExpiry || $invoice->due_at->lessThan($gatewayExpiry)) ? $invoice->due_at : $gatewayExpiry;
            $virtualAccount = PaymentVirtualAccount::create([
                'pmb_application_id' => $application->id,
                'pmb_invoice_id' => $invoice->id,
                'provider' => $issued->provider,
                'va_number' => $issued->vaNumber,
                'external_reference' => $issued->reference,
                'status' => $status,
                'expires_at' => $expiresAt,
                'metadata' => [...$issued->metadata, 'invoice_number' => $invoice->invoice_number, 'fixed_amount' => $invoice->amount, 'adapter' => config('bsi.driver')],
            ]);
            DB::table('audit_logs')->insert([
                'module' => 'pmb',
                'action' => 'virtual_account_issued',
                'record_type' => 'pmb_application',
                'record_id' => (string) $application->id,
                'new_data' => json_encode(['provider' => $issued->provider, 'va_id' => $virtualAccount->id, 'expires_at' => $expiresAt?->toIso8601String()]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $virtualAccount;
        }, 3);
    }
}
