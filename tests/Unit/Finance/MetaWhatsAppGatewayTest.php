<?php

namespace Tests\Unit\Finance;

use App\Integrations\WhatsApp\MetaWhatsAppGateway;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class MetaWhatsAppGatewayTest extends TestCase
{
    public function test_gateway_sends_approved_template_with_expected_parameters(): void
    {
        config()->set('finance_notifications.whatsapp.meta', ['base_url' => 'https://graph.facebook.com', 'graph_version' => 'v21.0', 'phone_number_id' => '123456789', 'access_token' => 'test-token', 'template_name' => 'siakad_finance_notification', 'language' => 'id']);
        Http::fake(['https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.test-001']]])]);

        $messageId = (new MetaWhatsAppGateway)->sendTemplate('6281234567890', ['Nadia', 'Tagihan baru', 'SPP diterbitkan', 'INV-001', 'https://siakad.test/finance']);

        $this->assertSame('wamid.test-001', $messageId);
        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v21.0/123456789/messages'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['type'] === 'template'
            && $request['template']['name'] === 'siakad_finance_notification'
            && $request['template']['components'][0]['parameters'][0]['text'] === 'Nadia');
    }
}
