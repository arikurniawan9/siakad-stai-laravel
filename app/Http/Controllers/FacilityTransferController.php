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

final class FacilityTransferController extends Controller
{
    public function preview(MasterDataImportRequest $request, string $resource, MasterDataTransferService $service): RedirectResponse
    {
        $this->ensureResource($resource);
        $token = (string) Str::uuid();
        $preview = $service->preview($request->file('file'), $resource);
        $request->session()->put("facility_imports.{$token}", [...$preview, 'user_id' => $request->user()->id]);

        return to_route('admin.facilities', ['import' => $token])->with('success', 'Preview impor sarana siap diperiksa.');
    }

    public function confirm(Request $request, string $token, MasterDataTransferService $service): RedirectResponse
    {
        $preview = $this->fromSession($request, $token);
        $this->authorizeImport($request, $preview['resource']);
        if ($preview['error_rows'] > 0) throw ValidationException::withMessages(['import' => 'Perbaiki seluruh baris bermasalah sebelum mengonfirmasi impor.']);

        $result = DB::transaction(function () use ($request, $token, $preview, $service): array {
            $result = $service->commit($preview);
            DB::table('audit_logs')->insert([
                'user_id' => $request->user()->id,
                'module' => 'facilities',
                'action' => 'imported',
                'record_type' => $preview['resource'],
                'record_id' => $token,
                'new_data' => json_encode([...$result, 'file_name' => $preview['file_name']]),
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $result;
        }, 3);
        $request->session()->forget("facility_imports.{$token}");

        return to_route('admin.facilities')->with('success', "Impor selesai: {$result['created']} dibuat dan {$result['updated']} diperbarui.");
    }

    public function cancel(Request $request, string $token): RedirectResponse
    {
        $this->fromSession($request, $token);
        $request->session()->forget("facility_imports.{$token}");

        return to_route('admin.facilities')->with('success', 'Preview impor dibatalkan.');
    }

    public function template(Request $request, string $resource, MasterDataTransferService $service): StreamedResponse
    {
        $this->ensureResource($resource);
        abort_unless($request->user()->can($resource.'.view'), 403);

        return response()->streamDownload(fn () => $service->writeTemplate(fopen('php://output', 'wb'), $resource), "template-{$resource}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function export(Request $request, string $resource, MasterDataTransferService $service): StreamedResponse
    {
        $this->ensureResource($resource);
        abort_unless($request->user()->can($resource.'.view'), 403);
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'module' => 'facilities',
            'action' => 'exported',
            'record_type' => $resource,
            'new_data' => json_encode(['format' => 'csv']),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->streamDownload(fn () => $service->writeExport(fopen('php://output', 'wb'), $resource), $resource.'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function fromSession(Request $request, string $token): array
    {
        $preview = $request->session()->get("facility_imports.{$token}");
        abort_unless(is_array($preview) && ($preview['user_id'] ?? null) === $request->user()->id, 404);
        if (now()->diffInMinutes($preview['created_at'], absolute: true) > 30) {
            $request->session()->forget("facility_imports.{$token}");
            throw ValidationException::withMessages(['import' => 'Preview sudah kedaluwarsa. Unggah ulang file CSV.']);
        }

        return $preview;
    }

    private function authorizeImport(Request $request, string $resource): void
    {
        abort_unless($request->user()->can($resource.'.create') && $request->user()->can($resource.'.update'), 403);
    }

    private function ensureResource(string $resource): void
    {
        abort_unless(in_array($resource, ['buildings', 'rooms'], true), 404);
    }
}
