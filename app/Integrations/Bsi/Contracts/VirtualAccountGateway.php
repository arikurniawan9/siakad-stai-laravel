<?php

namespace App\Integrations\Bsi\Contracts;

use App\Integrations\Bsi\Data\VirtualAccountData;

interface VirtualAccountGateway
{
    public function create(string $reference, string $customerName, ?string $amount = null): VirtualAccountData;
    public function inquire(string $vaNumber): VirtualAccountData;
    public function deactivate(string $vaNumber): bool;
}
