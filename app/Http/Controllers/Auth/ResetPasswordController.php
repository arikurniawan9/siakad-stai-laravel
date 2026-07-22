<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class ResetPasswordController extends Controller
{
    public function create(Request $request, string $token): Response { return Inertia::render('Auth/ResetPassword', ['token' => $token, 'email' => (string) $request->query('email', '')]); }
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['token' => ['required', 'string'], 'email' => ['required', 'email'], 'password' => ['required', 'confirmed', Rules\Password::defaults()]]);
        $status = Password::reset($data, function (User $user, string $password): void { $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save(); event(new PasswordReset($user)); });
        if ($status !== Password::PASSWORD_RESET) throw ValidationException::withMessages(['email' => __($status)]);
        return to_route('login')->with('success', 'Password berhasil diperbarui. Silakan masuk kembali.');
    }
}
