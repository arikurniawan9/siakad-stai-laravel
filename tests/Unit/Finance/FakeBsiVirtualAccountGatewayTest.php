<?php

namespace Tests\Unit\Finance;

use App\Integrations\Bsi\FakeBsiVirtualAccountGateway;
use PHPUnit\Framework\TestCase;

final class FakeBsiVirtualAccountGatewayTest extends TestCase
{
    public function test_fake_gateway_is_deterministic_and_never_uses_a_real_bank_contract(): void
    {
        $gateway = new FakeBsiVirtualAccountGateway();
        $first = $gateway->create('invoice-001', 'Nadia Putri');
        $second = $gateway->create('invoice-001', 'Nadia Putri');

        self::assertSame($first->vaNumber, $second->vaNumber);
        self::assertSame('bsi-fake', $first->provider);
        self::assertSame('active', $first->status);
    }
}
