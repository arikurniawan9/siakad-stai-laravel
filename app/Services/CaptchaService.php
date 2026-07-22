<?php

namespace App\Services;

use Illuminate\Http\Request;

final class CaptchaService
{
    private const SESSION_KEY = 'siakad.captcha';
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function issue(): array
    {
        $code = collect(range(1, 6))->map(fn (): string => self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)])->implode('');
        $expiresAt = now()->addMinutes(10);

        session()->put(self::SESSION_KEY, [
            'hash' => hash_hmac('sha256', $code, config('app.key')),
            'expires_at' => $expiresAt->timestamp,
        ]);

        return [
            'svg' => $this->renderSvg($code),
            'expiresAt' => $expiresAt->toIso8601String(),
        ];
    }

    public function verify(Request $request, string $answer): bool
    {
        $challenge = $request->session()->pull(self::SESSION_KEY);
        if (! is_array($challenge) || empty($challenge['hash']) || empty($challenge['expires_at'])) {
            return false;
        }

        if ((int) $challenge['expires_at'] < now()->timestamp) {
            return false;
        }

        $answer = strtoupper(trim($answer));
        if (! preg_match('/^[A-Z0-9]{6}$/', $answer)) {
            return false;
        }

        return hash_equals($challenge['hash'], hash_hmac('sha256', $answer, config('app.key')));
    }

    private function renderSvg(string $code): string
    {
        $colors = ['#0f766e', '#1d4ed8', '#334155', '#0f766e', '#1e40af', '#475569'];
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="82" viewBox="0 0 300 82" role="img" aria-label="Kode CAPTCHA" class="h-full w-full">';
        $svg .= '<defs><linearGradient id="captcha-bg" x1="0" x2="1"><stop stop-color="#ecfeff"/><stop offset="1" stop-color="#eff6ff"/></linearGradient></defs>';
        $svg .= '<rect width="300" height="82" rx="16" fill="url(#captcha-bg)"/>';

        for ($i = 0; $i < 7; $i++) {
            $x1 = random_int(8, 292);
            $y1 = random_int(8, 74);
            $x2 = random_int(8, 292);
            $y2 = random_int(8, 74);
            $svg .= sprintf('<path d="M%d %d L%d %d" stroke="#0f766e" stroke-opacity=".18" stroke-width="%d"/>', $x1, $y1, $x2, $y2, random_int(1, 3));
        }

        foreach (str_split($code) as $index => $character) {
            $x = 34 + ($index * 40);
            $y = random_int(52, 58);
            $rotation = random_int(-12, 12);
            $svg .= sprintf('<text x="%d" y="%d" transform="rotate(%d %d %d)" fill="%s" font-family="monospace" font-size="32" font-weight="800">%s</text>', $x, $y, $rotation, $x, $y, $colors[$index], htmlspecialchars($character, ENT_QUOTES, 'UTF-8'));
        }

        return $svg.'</svg>';
    }
}
