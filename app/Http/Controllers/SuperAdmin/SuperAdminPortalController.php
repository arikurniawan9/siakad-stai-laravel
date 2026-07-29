<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\DatabaseMaintenanceService;
use App\Support\BsiSettingsStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class SuperAdminPortalController extends Controller
{
    public function index(DatabaseMaintenanceService $database, BsiSettingsStore $bsi): Response
    {
        $settings = $bsi->read();
        unset($settings['callback_secret']);

        return Inertia::render('SuperAdmin/Index', [
            'database' => $database->status(),
            'backups' => $database->backups(),
            'bsi' => $settings,
            'callbackUrl' => url('/api/bank/bsi/va/callback'),
            'realAdapterAvailable' => false,
            'limits' => [
                'restoreSizeKb' => (int) config('superadmin.max_restore_size_kb'),
            ],
        ]);
    }

    public function updateBsi(Request $request, BsiSettingsStore $settings): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'environment' => ['required', Rule::in(['sandbox', 'production'])],
            'base_url' => ['nullable', 'url:http,https', 'max:500'],
            'callback_secret' => ['nullable', 'string', 'min:16', 'max:500'],
            'timeout' => ['required', 'integer', 'min:1', 'max:120'],
            'signature_tolerance_seconds' => ['required', 'integer', 'min:30', 'max:3600'],
            'strategy' => ['required', Rule::in(['student', 'invoice'])],
        ]);

        if ($validated['environment'] === 'production' && $validated['enabled']) {
            return back()->withErrors([
                'enabled' => 'Adapter BSI production belum tersedia. Simpan konfigurasi dahulu tanpa mengaktifkannya.',
            ]);
        }

        $settings->write([
            ...$validated,
            'callback_secret' => $validated['callback_secret'] ?? null,
        ]);

        Log::notice('Super Admin memperbarui konfigurasi BSI VA.', [
            'user_id' => $request->user()->id,
            'environment' => $validated['environment'],
            'enabled' => $validated['enabled'],
        ]);

        return back()->with('success', 'Konfigurasi VA berhasil disimpan.');
    }

    public function createBackup(Request $request, DatabaseMaintenanceService $database): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string']]);
        $this->confirmPassword($request);

        try {
            $backup = $database->backup();
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage());
        }

        Log::notice('Super Admin membuat backup database.', [
            'user_id' => $request->user()->id,
            'filename' => $backup['filename'],
        ]);

        return back()->with('success', 'Backup '.$backup['filename'].' berhasil dibuat.');
    }

    public function downloadBackup(string $filename, DatabaseMaintenanceService $database): StreamedResponse
    {
        $path = $database->backupPath($filename);

        return Storage::disk('local')->download($path, basename($path), [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function restore(Request $request, DatabaseMaintenanceService $database): RedirectResponse
    {
        $status = $database->status();
        $validated = $request->validate([
            'backup' => [
                'required',
                'file',
                'max:'.(int) config('superadmin.max_restore_size_kb'),
                'extensions:sql,sqlite',
            ],
            'password' => ['required', 'string'],
            'confirmation' => ['required', Rule::in(['RESTORE '.$status['database']])],
        ]);
        $this->confirmPassword($request);

        try {
            $database->restore($validated['backup']);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage());
        }

        Log::warning('Super Admin memulihkan database dari file backup.', [
            'user_id' => $request->user()->id,
            'filename' => $validated['backup']->getClientOriginalName(),
        ]);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('superadmin.entry');
    }

    public function destroyDatabase(Request $request, DatabaseMaintenanceService $database): RedirectResponse
    {
        abort_if(app()->isProduction() && ! config('app.debug'), 403, 'Penghapusan database dinonaktifkan pada production.');

        $status = $database->status();
        $request->validate([
            'password' => ['required', 'string'],
            'confirmation' => ['required', Rule::in(['HAPUS '.$status['database']])],
        ]);
        $this->confirmPassword($request);
        $userId = $request->user()->id;

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        try {
            $database->dropDatabase();
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('superadmin.login')->with('error', $exception->getMessage());
        }

        Log::critical('Super Admin menghapus database aplikasi setelah backup otomatis.', [
            'user_id' => $userId,
            'database' => $status['database'],
        ]);

        return redirect()->route('superadmin.setup', ['database' => 1]);
    }

    private function confirmPassword(Request $request): void
    {
        if (! Hash::check((string) $request->input('password'), (string) $request->user()->password)) {
            throw ValidationException::withMessages([
                'password' => 'Kata sandi Super Admin tidak valid.',
            ]);
        }
    }
}
