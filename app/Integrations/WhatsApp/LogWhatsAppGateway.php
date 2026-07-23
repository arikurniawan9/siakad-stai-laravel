<?php

namespace App\Integrations\WhatsApp;

use App\Integrations\WhatsApp\Contracts\WhatsAppGateway;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;

final class LogWhatsAppGateway implements WhatsAppGateway
{
    public function sendTemplate(string $recipient, array $parameters): string
    {
        if (app()->environment('production')) {
            throw new LogicException('Driver WhatsApp log tidak boleh dipakai untuk pengiriman production.');
        }

        $messageId = 'log-'.Str::lower((string) Str::ulid());
        Log::info('WhatsApp finance notification', ['message_id' => $messageId, 'recipient' => $recipient, 'parameters' => $parameters]);

        return $messageId;
    }
}
