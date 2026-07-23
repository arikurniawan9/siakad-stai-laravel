<?php

namespace App\Integrations\WhatsApp;

use App\Integrations\WhatsApp\Contracts\WhatsAppGateway;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class MetaWhatsAppGateway implements WhatsAppGateway
{
    public function sendTemplate(string $recipient, array $parameters): string
    {
        $config = config('finance_notifications.whatsapp.meta');
        if (blank($config['phone_number_id']) || blank($config['access_token']) || blank($config['template_name'])) {
            throw new RuntimeException('Konfigurasi Meta WhatsApp Cloud API belum lengkap.');
        }

        $url = rtrim((string) $config['base_url'], '/').'/'.trim((string) $config['graph_version'], '/').'/'.$config['phone_number_id'].'/messages';
        $response = Http::acceptJson()->withToken((string) $config['access_token'])->timeout(20)->retry(2, 500)->post($url, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipient,
            'type' => 'template',
            'template' => [
                'name' => $config['template_name'],
                'language' => ['code' => $config['language']],
                'components' => [[
                    'type' => 'body',
                    'parameters' => array_map(static fn (mixed $value): array => ['type' => 'text', 'text' => (string) $value], $parameters),
                ]],
            ],
        ])->throw();

        $messageId = $response->json('messages.0.id');
        if (! is_string($messageId) || $messageId === '') throw new RuntimeException('Meta WhatsApp tidak mengembalikan message ID.');

        return $messageId;
    }
}
