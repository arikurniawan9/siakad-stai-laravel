<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\CaptchaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'captcha' => app(CaptchaService::class)->issue(),
        ]);
    }

    public function store(LoginRequest $request, CaptchaService $captcha): RedirectResponse
    {
        $identifier = Str::lower(trim($request->string('identifier')->toString()));
        $key = 'login:'.hash('sha256', $identifier).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['identifier' => 'Terlalu banyak percobaan. Silakan coba lagi beberapa saat.'])->onlyInput('identifier');
        }

        if (! $captcha->verify($request, $request->string('captcha')->toString())) {
            RateLimiter::hit($key, 60);
            return back()->withErrors(['captcha' => 'Kode CAPTCHA salah atau sudah kedaluwarsa. Silakan gunakan kode baru.'])->onlyInput('identifier');
        }

        $user = User::query()
            ->where(function ($query) use ($identifier) {
                $query->whereRaw('LOWER(email) = ?', [$identifier])->orWhereRaw('LOWER(username) = ?', [$identifier]);
            })
            ->first();

        if (! $user || ! $user->is_active || ! Auth::validate(['email' => $user->email, 'password' => $request->string('password')->toString()])) {
            RateLimiter::hit($key, 60);
            return back()->withErrors(['identifier' => 'Identitas atau kata sandi tidak valid.'])->onlyInput('identifier');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $activeRole = $user->active_role ?: $user->getRoleNames()->first();
        $user->forceFill(['active_role' => $activeRole])->saveQuietly();
        $request->session()->put(['active_role' => $activeRole, 'authenticated_at' => now()->toIso8601String()]);
        RateLimiter::clear($key);

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
