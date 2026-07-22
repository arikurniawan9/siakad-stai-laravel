<?php

namespace App\Http\Controllers\Pmb;

use App\Domain\Finance\BsiPaymentAllocationService;
use App\Http\Controllers\Controller;
use App\Models\PmbApplication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SimulatePmbPaymentController extends Controller
{
    public function __invoke(Request $request, PmbApplication $application, BsiPaymentAllocationService $processor): RedirectResponse
    {
        abort_unless(app()->environment(['local', 'testing']), 404);
        abort_unless($request->user()->can('pmb_payments.update'), 403);
        $application->load(['invoice.virtualAccount']);
        $invoice = $application->invoice;
        $virtualAccount = $invoice?->virtualAccount;
        if (! $invoice || ! $virtualAccount || $virtualAccount->status !== 'active') {
            throw ValidationException::withMessages(['payment' => 'Invoice atau VA aktif tidak tersedia.']);
        }
        $outstanding = $this->toCents($invoice->amount) - $this->toCents($invoice->paid_amount);
        if ($outstanding <= 0) throw ValidationException::withMessages(['payment' => 'Invoice PMB sudah lunas.']);

        $reference = 'LOCAL-PMB-'.Str::uuid();
        $processor->process([
            'provider' => $virtualAccount->provider,
            'event_id' => $reference,
            'va_number' => $virtualAccount->va_number,
            'external_reference' => $reference,
            'amount' => $this->toMoney($outstanding),
            'currency' => 'IDR',
            'paid_at' => now()->toIso8601String(),
        ]);

        return back()->with('success', 'Simulasi callback pembayaran berhasil. Invoice PMB telah dilunasi.');
    }

    private function toCents(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');
        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function toMoney(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
