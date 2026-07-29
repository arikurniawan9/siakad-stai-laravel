<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DatabaseMaintenanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

final class SuperAdminAccessController extends Controller
{
    public function entry(Request $request, DatabaseMaintenanceService $database): RedirectResponse
    {
        if (! $database->schemaReady() || ! $this->superAdminExists()) {
            return redirect()->route('superadmin.setup');
        }

        if (! $request->user()) {
            return redirect()->route('superadmin.login');
        }

        abort_unless($request->user()->hasRole($this->role()), 403);

        return redirect()->route('superadmin.portal');
    }

    public function setup(Request $request, DatabaseMaintenanceService $database): Response|RedirectResponse
    {
        if (! $database->schemaReady()) {
            $database->initializeDatabase();
        }

        if ($this->superAdminExists()) {
            return $request->user()?->hasRole($this->role())
                ? redirect()->route('superadmin.portal')
                : redirect()->route('superadmin.login');
        }

        return Inertia::render('SuperAdmin/Setup', [
            'defaults' => [
                'name' => 'Super Administrator',
                'username' => 'superadmin',
                'email' => 'superadmin@siakad.local',
            ],
            'database' => $database->status(),
            'databaseRecreated' => $request->boolean('database'),
        ]);
    }

    public function create(Request $request): RedirectResponse
    {
        abort_if($this->superAdminExists(), 409, 'Akun Super Admin sudah tersedia.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'alpha_dash', 'min:4', 'max:40', 'unique:users,username'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        $user = DB::transaction(function () use ($validated): User {
            $role = Role::findOrCreate($this->role(), 'web');
            $user = User::query()->create([
                'name' => $validated['name'],
                'username' => Str::lower($validated['username']),
                'email' => Str::lower($validated['email']),
                'password' => $validated['password'],
                'email_verified_at' => now(),
                'is_active' => true,
                'active_role' => $role->name,
            ]);
            $user->syncRoles([$role]);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put([
            'active_role' => $this->role(),
            'authenticated_at' => now()->toIso8601String(),
        ]);

        return redirect()->route('superadmin.portal')->with('success', 'Akun Super Admin berhasil dibuat.');
    }

    public function loginForm(Request $request, DatabaseMaintenanceService $database): Response|RedirectResponse
    {
        if (! $database->schemaReady() || ! $this->superAdminExists()) {
            return redirect()->route('superadmin.setup');
        }

        if ($request->user()?->hasRole($this->role())) {
            return redirect()->route('superadmin.portal');
        }

        return Inertia::render('SuperAdmin/Login');
    }

    public function login(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $identifier = Str::lower(trim($validated['identifier']));
        $key = 'superadmin-login:'.hash('sha256', $identifier).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['identifier' => 'Terlalu banyak percobaan. Coba kembali satu menit lagi.'])->onlyInput('identifier');
        }

        $user = User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($identifier): void {
                $query->whereRaw('LOWER(email) = ?', [$identifier])
                    ->orWhereRaw('LOWER(username) = ?', [$identifier]);
            })
            ->first();

        if (! $user || ! $user->hasRole($this->role()) || ! Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['identifier' => 'Kredensial Super Admin tidak valid.'])->onlyInput('identifier');
        }

        Auth::login($user, (bool) ($validated['remember'] ?? false));
        $request->session()->regenerate();
        $request->session()->put([
            'active_role' => $this->role(),
            'authenticated_at' => now()->toIso8601String(),
        ]);
        RateLimiter::clear($key);

        return redirect()->intended(route('superadmin.portal'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('superadmin.login');
    }

    private function superAdminExists(): bool
    {
        $role = Role::query()->where('name', $this->role())->where('guard_name', 'web')->first();

        return $role?->users()->where('is_active', true)->exists() ?? false;
    }

    private function role(): string
    {
        return (string) config('superadmin.role');
    }
}
