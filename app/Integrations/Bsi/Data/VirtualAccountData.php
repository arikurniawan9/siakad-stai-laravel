<?php

namespace App\Integrations\Bsi\Data;

final readonly class VirtualAccountData
{
    public function __construct(
        public string $provider,
        public string $vaNumber,
        public string $reference,
        public string $status,
        public ?string $expiresAt = null,
        public array $metadata = [],
    ) {}
}
