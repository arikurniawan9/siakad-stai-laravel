<?php

namespace App\Domain\Pmb;

use App\Models\PmbApplication;
use App\Models\PmbInvoice;
use Illuminate\Support\Facades\DB;

final class PmbInvoiceService
{
    public function __construct(
        private readonly PmbFeeResolver $resolver,
        private readonly PmbVirtualAccountService $virtualAccounts,
    ) {}

    public function issue(PmbApplication $application): PmbInvoice
    {
        return DB::transaction(function () use ($application): PmbInvoice {
            $application = PmbApplication::query()->lockForUpdate()->findOrFail($application->id);
            $existing = PmbInvoice::query()->where('pmb_application_id', $application->id)->lockForUpdate()->first();
            if ($existing) {
                $this->virtualAccounts->issue($application, $existing);
                return $existing;
            }
            $fee = $this->resolver->resolve($application);
            $invoice = PmbInvoice::create([
                'pmb_application_id' => $application->id,
                'pmb_fee_id' => $fee->id,
                'invoice_number' => 'INV-'.$application->registration_number,
                'description' => $fee->name,
                'amount' => $fee->amount,
                'paid_amount' => 0,
                'due_at' => now()->addDays($fee->due_days),
                'status' => 'unpaid',
                'issued_at' => now(),
            ]);
            $application->forceFill(['pmb_fee_id' => $fee->id])->save();
            $this->virtualAccounts->issue($application, $invoice);

            return $invoice;
        }, 3);
    }
}
