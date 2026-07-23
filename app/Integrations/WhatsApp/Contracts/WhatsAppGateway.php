<?php

namespace App\Integrations\WhatsApp\Contracts;

interface WhatsAppGateway
{
    public function sendTemplate(string $recipient, array $parameters): string;
}
