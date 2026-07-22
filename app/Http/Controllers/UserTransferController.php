<?php

namespace App\Http\Controllers;

use App\Domain\Security\UserTransferService;
use App\Http\Requests\UserImportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class UserTransferController extends Controller
{
    public function preview(UserImportRequest $request, UserTransferService $service): RedirectResponse
    {
        $token = (string) Str::uuid();
        $preview = $service->preview($request->file('file'), $request->user());
        $request->session()->put("user_imports.{$token}", [...$preview, 'user_id' => $request->user()->id]);

        return to_route('admin.users', ['import' => $token])->with('success', 'Preview sinkronisasi pengguna siap diperiksa.');
    }

    public function confirm(Request $request, string $token, UserTransferService $service): RedirectResponse
    {
        abort_unless($request->user()->can('users.update'), 403);
        $preview = $this->fromSession($request, $token);
        if ($preview['error_rows'] > 0) throw ValidationException::withMessages(['import' => 'Perbaiki seluruh baris bermasalah sebelum mengonfirmasi sinkronisasi.']);

        $result = DB::transaction(function () use ($request, $token, $preview, $service): array {
            $result = $service->commit($preview, $request->user());
            DB::table('audit_logs')->insert([
                'user_id' => $request->user()->id,
                'module' => 'users',
                'action' => 'imported',
                'record_type' => 'user',
                'record_id' => $token,
                'new_data' => json_encode([...$result, 'file_name' => $preview['file_name']]),
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $result;
        }, 3);
        $request->session()->forget("user_imports.{$token}");

        return to_route('admin.users')->with('success', "Sinkronisasi selesai: {$result['updated']} akun diperbarui tanpa mengubah kata sandi.");
    }

    public function cancel(Request $request, string $token): RedirectResponse
    {
        $this->fromSession($request, $token);
        $request->session()->forget("user_imports.{$token}");

        return to_route('admin.users')->with('success', 'Preview sinkronisasi dibatalkan.');
    }

    public function template(Request $request, UserTransferService $service): StreamedResponse
    {
        abort_unless($request->user()->can('users.view'), 403);

        return response()->streamDownload(fn () => $service->writeTemplate(fopen('php://output', 'wb')), 'template-users-safe-sync.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function export(Request $request, UserTransferService $service): StreamedResponse
    {
        abort_unless($request->user()->can('users.export'), 403);
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'module' => 'users',
            'action' => 'exported',
            'record_type' => 'user',
            'new_data' => json_encode(['format' => 'csv']),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->streamDownload(fn () => $service->writeExport(fopen('php://output', 'wb')), 'users-safe-sync-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function fromSession(Request $request, string $token): array
    {
        $preview = $request->session()->get("user_imports.{$token}");
        abort_unless(is_array($preview) && ($preview['user_id'] ?? null) === $request->user()->id, 404);
        if (now()->diffInMinutes($preview['created_at'], absolute: true) > 30) {
            $request->session()->forget("user_imports.{$token}");
            throw ValidationException::withMessages(['import' => 'Preview sudah kedaluwarsa. Unggah ulang file CSV.']);
        }

        return $preview;
    }
}
