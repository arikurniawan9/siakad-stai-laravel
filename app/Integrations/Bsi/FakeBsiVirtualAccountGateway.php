<?php

namespace App\Integrations\Bsi;

use App\Integrations\Bsi\Contracts\VirtualAccountGateway;
use App\Integrations\Bsi\Data\VirtualAccountData;
use Illuminate\Support\Str;

/** Deterministic local adapter; never select this in production. */
final class FakeBsiVirtualAccountGateway implements VirtualAccountGateway
{
    public function create(string $reference, string $customerName, ?string $amount = null): VirtualAccountData
    {
        return new VirtualAccountData('bsi-fake', '900'.str_pad((string) abs(crc32($reference)), 12, '0', STR_PAD_LEFT), $reference, 'active', now()->addDays(30)->toIso8601String(), ['customer' => Str::limit($customerName, 80), 'amount' => $amount]);
    }

    public function inquire(string $vaNumber): VirtualAccountData
    {
        return new VirtualAccountData('bsi-fake', $vaNumber, 'inquiry-'.$vaNumber, 'active');
    }

    public function deactivate(string $vaNumber): bool
    {
        return $vaNumber !== '';
    }
}
