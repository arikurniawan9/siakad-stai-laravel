<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class BsiSettingsStore
{
    public function apply(): void
    {
        $settings = $this->read();

        config([
            'bsi.enabled' => $settings['enabled'],
            'bsi.environment' => $settings['environment'],
            'bsi.base_url' => $settings['base_url'],
            'bsi.callback_secret' => $settings['callback_secret'],
            'bsi.timeout' => $settings['timeout'],
            'bsi.signature_tolerance_seconds' => $settings['signature_tolerance_seconds'],
            'bsi.strategy' => $settings['strategy'],
        ]);
    }

    /**
     * @return array{
     *   driver: string,
     *   enabled: bool,
     *   environment: string,
     *   base_url: ?string,
     *   callback_secret: ?string,
     *   callback_secret_configured: bool,
     *   timeout: int,
     *   signature_tolerance_seconds: int,
     *   strategy: string
     * }
     */
    public function read(): array
    {
        $stored = $this->storedPayload();
        $encryptedSecret = $stored['callback_secret'] ?? null;
        $secret = null;

        if (is_string($encryptedSecret) && $encryptedSecret !== '') {
            try {
                $secret = Crypt::decryptString($encryptedSecret);
            } catch (DecryptException) {
                $secret = null;
            }
        }

        return [
            'driver' => 'fake',
            'enabled' => (bool) ($stored['enabled'] ?? config('bsi.enabled', false)),
            'environment' => (string) ($stored['environment'] ?? config('bsi.environment', 'sandbox')),
            'base_url' => $this->nullableString($stored['base_url'] ?? config('bsi.base_url')),
            'callback_secret' => $secret ?? $this->nullableString(config('bsi.callback_secret')),
            'callback_secret_configured' => filled($secret ?? config('bsi.callback_secret')),
            'timeout' => (int) ($stored['timeout'] ?? config('bsi.timeout', 10)),
            'signature_tolerance_seconds' => (int) ($stored['signature_tolerance_seconds'] ?? config('bsi.signature_tolerance_seconds', 300)),
            'strategy' => (string) ($stored['strategy'] ?? config('bsi.strategy', 'student')),
        ];
    }

    /**
     * @param  array{
     *   enabled: bool,
     *   environment: string,
     *   base_url: ?string,
     *   callback_secret?: ?string,
     *   timeout: int,
     *   signature_tolerance_seconds: int,
     *   strategy: string
     * }  $settings
     */
    public function write(array $settings): void
    {
        $stored = $this->storedPayload();
        $newSecret = $this->nullableString($settings['callback_secret'] ?? null);

        if ($newSecret !== null) {
            $stored['callback_secret'] = Crypt::encryptString($newSecret);
        }

        $payload = [
            'enabled' => $settings['enabled'],
            'environment' => $settings['environment'],
            'base_url' => $this->nullableString($settings['base_url']),
            'callback_secret' => $stored['callback_secret'] ?? null,
            'timeout' => $settings['timeout'],
            'signature_tolerance_seconds' => $settings['signature_tolerance_seconds'],
            'strategy' => $settings['strategy'],
            'updated_at' => now()->toIso8601String(),
        ];

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (! Storage::disk('local')->put((string) config('superadmin.settings_file'), $encoded)) {
            throw new RuntimeException('Konfigurasi BSI tidak dapat disimpan.');
        }

        $this->apply();
    }

    /** @return array<string, mixed> */
    private function storedPayload(): array
    {
        $path = (string) config('superadmin.settings_file');

        if (! Storage::disk('local')->exists($path)) {
            return [];
        }

        $decoded = json_decode((string) Storage::disk('local')->get($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
