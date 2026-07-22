<?php

namespace App\Http\Controllers\Pmb;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pmb\StorePmbRegistrationRequest;
use App\Models\User;
use App\Services\CaptchaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class PmbRegistrationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Pmb/Register', [
            'programs' => DB::table('programs')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'captcha' => app(CaptchaService::class)->issue(),
        ]);
    }

    public function store(StorePmbRegistrationRequest $request, CaptchaService $captcha): RedirectResponse
    {
        $key = 'pmb-register:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['email' => 'Terlalu banyak percobaan. Silakan coba lagi nanti.'])->withInput();
        }

        if (! $captcha->verify($request, $request->string('captcha')->toString())) {
            RateLimiter::hit($key, 300);
            return back()->withErrors(['captcha' => 'Kode CAPTCHA salah atau sudah kedaluwarsa. Silakan gunakan kode baru.'])->withInput();
        }

        $application = DB::transaction(function () use ($request): array {
            $registrationNumber = 'PMB-'.now()->format('Y').'-'.strtoupper(Str::random(8));
            $user = User::create([
                'name' => $request->string('full_name')->toString(),
                'username' => Str::lower($registrationNumber),
                'email' => Str::lower($request->string('email')->toString()),
                'password' => $request->string('password')->toString(),
                'is_active' => true,
                'active_role' => 'Calon Mahasiswa',
            ]);
            $user->assignRole('Calon Mahasiswa');
            $applicationId = DB::table('pmb_applications')->insertGetId([
                'user_id' => $user->id,
                'program_id' => $request->integer('program_id') ?: null,
                'registration_number' => $registrationNumber,
                'full_name' => $request->string('full_name')->toString(),
                'email' => Str::lower($request->string('email')->toString()),
                'phone' => $request->string('phone')->toString(),
                'status' => 'draft',
                'submitted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['user' => $user, 'application_id' => $applicationId, 'registration_number' => $registrationNumber];
        });

        Auth::login($application['user']);
        $request->session()->regenerate();
        $request->session()->put(['active_role' => 'Calon Mahasiswa', 'pmb_application_id' => $application['application_id'], 'authenticated_at' => now()->toIso8601String()]);
        RateLimiter::clear($key);

        return redirect()->route('pmb.application')->with('success', 'Akun pendaftaran berhasil dibuat. Lengkapi biodata dan dokumen Anda.');
    }
}
