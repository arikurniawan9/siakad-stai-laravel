<?php

namespace App\Http\Controllers;

use App\Domain\MasterData\MasterDataTransferService;
use App\Http\Requests\MasterDataImportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class LecturerTransferController extends Controller
{
    public function preview(MasterDataImportRequest $request, MasterDataTransferService $service): RedirectResponse
    {
        $token = (string) Str::uuid();
        $preview = $service->preview($request->file('file'), 'lecturers');
        $request->session()->put("lecturer_imports.{$token}", [...$preview, 'user_id' => $request->user()->id]);

        return to_route('admin.academic-schedules', ['import' => $token])->with('success', 'Preview impor dosen siap diperiksa.');
    }

    public function confirm(Request $request, string $token, MasterDataTransferService $service): RedirectResponse
    {
        $preview = $this->fromSession($request, $token);
        $this->authorizeImport($request);
        if ($preview['error_rows'] > 0) throw ValidationException::withMessages(['import' => 'Perbaiki seluruh baris bermasalah sebelum mengonfirmasi impor.']);

        $result = DB::transaction(function () use ($request, $token, $preview, $service): array {
            $result = $service->commit($preview);
            DB::table('audit_logs')->insert([
                'user_id' => $request->user()->id,
                'module' => 'academic_schedules',
                'action' => 'imported',
                'record_type' => 'lecturer',
                'record_id' => $token,
                'new_data' => json_encode([...$result, 'file_name' => $preview['file_name']]),
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $result;
        }, 3);
        $request->session()->forget("lecturer_imports.{$token}");

        return to_route('admin.academic-schedules')->with('success', "Impor selesai: {$result['created']} dibuat dan {$result['updated']} diperbarui.");
    }

    public function cancel(Request $request, string $token): RedirectResponse
    {
        $this->fromSession($request, $token);
        $request->session()->forget("lecturer_imports.{$token}");

        return to_route('admin.academic-schedules')->with('success', 'Preview impor dibatalkan.');
    }

    public function template(Request $request, MasterDataTransferService $service): StreamedResponse
    {
        abort_unless($request->user()->can('lecturers.view'), 403);

        return response()->streamDownload(fn () => $service->writeTemplate(fopen('php://output', 'wb'), 'lecturers'), 'template-lecturers.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function export(Request $request, MasterDataTransferService $service): StreamedResponse
    {
        abort_unless($request->user()->can('lecturers.view'), 403);
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'module' => 'academic_schedules',
            'action' => 'exported',
            'record_type' => 'lecturer',
            'new_data' => json_encode(['format' => 'csv']),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->streamDownload(fn () => $service->writeExport(fopen('php://output', 'wb'), 'lecturers'), 'lecturers-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function fromSession(Request $request, string $token): array
    {
        $preview = $request->session()->get("lecturer_imports.{$token}");
        abort_unless(is_array($preview) && ($preview['user_id'] ?? null) === $request->user()->id, 404);
        if (now()->diffInMinutes($preview['created_at'], absolute: true) > 30) {
            $request->session()->forget("lecturer_imports.{$token}");
            throw ValidationException::withMessages(['import' => 'Preview sudah kedaluwarsa. Unggah ulang file CSV.']);
        }

        return $preview;
    }

    private function authorizeImport(Request $request): void
    {
        abort_unless($request->user()->can('lecturers.create') && $request->user()->can('lecturers.update'), 403);
    }
}
