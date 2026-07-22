<?php

namespace App\Http\Controllers;

use App\Domain\MasterData\MasterDataTransferService;
use App\Http\Requests\MasterDataImportRequest;
use App\Models\AcademicTerm;
use App\Models\Campus;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\Program;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MasterDataTransferController extends Controller
{
    public function preview(MasterDataImportRequest $request, string $resource, MasterDataTransferService $service): RedirectResponse
    {
        abort_unless($service->supports($resource), 404);
        $token = (string) Str::uuid();
        $preview = $service->preview($request->file('file'), $resource);
        $request->session()->put("master_data_imports.{$token}", [...$preview, 'user_id' => $request->user()->id]);

        return to_route('admin.master-data', ['import' => $token])->with('success', 'Preview impor siap diperiksa.');
    }

    public function confirm(Request $request, string $token, MasterDataTransferService $service): RedirectResponse
    {
        $preview = $this->previewFromSession($request, $token);
        $this->authorizeImport($request, $preview['resource']);
        if ($preview['error_rows'] > 0) throw ValidationException::withMessages(['import' => 'Perbaiki seluruh baris bermasalah sebelum mengonfirmasi impor.']);

        $result = DB::transaction(function () use ($request, $token, $preview, $service): array {
            $result = $service->commit($preview);
            DB::table('audit_logs')->insert([
                'user_id' => $request->user()->id,
                'module' => 'master_data',
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
        $request->session()->forget("master_data_imports.{$token}");

        return to_route('admin.master-data')->with('success', "Impor selesai: {$result['created']} dibuat dan {$result['updated']} diperbarui.");
    }

    public function cancel(Request $request, string $token): RedirectResponse
    {
        $this->previewFromSession($request, $token);
        $request->session()->forget("master_data_imports.{$token}");

        return to_route('admin.master-data')->with('success', 'Preview impor dibatalkan.');
    }

    public function template(Request $request, string $resource, MasterDataTransferService $service): StreamedResponse
    {
        abort_unless($service->supports($resource), 404);
        $this->authorizeView($request, $resource);

        return response()->streamDownload(fn () => $service->writeTemplate(fopen('php://output', 'wb'), $resource), "template-{$resource}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function export(Request $request, string $resource, MasterDataTransferService $service): StreamedResponse
    {
        abort_unless($service->supports($resource), 404);
        $this->authorizeView($request, $resource);
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'module' => 'master_data',
            'action' => 'exported',
            'record_type' => $resource,
            'record_id' => null,
            'new_data' => json_encode(['format' => 'csv']),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fileName = $resource.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(fn () => $service->writeExport(fopen('php://output', 'wb'), $resource), $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function previewFromSession(Request $request, string $token): array
    {
        $preview = $request->session()->get("master_data_imports.{$token}");
        abort_unless(is_array($preview) && ($preview['user_id'] ?? null) === $request->user()->id, 404);
        if (now()->diffInMinutes($preview['created_at'], absolute: true) > 30) {
            $request->session()->forget("master_data_imports.{$token}");
            throw ValidationException::withMessages(['import' => 'Preview sudah kedaluwarsa. Unggah ulang file CSV.']);
        }

        return $preview;
    }

    private function authorizeImport(Request $request, string $resource): void
    {
        $class = $this->modelFor($resource);
        abort_unless($class, 404);
        Gate::authorize('create', $class);
        Gate::authorize('update', new $class);
    }

    private function authorizeView(Request $request, string $resource): void
    {
        $class = $this->modelFor($resource);
        abort_unless($class, 404);
        Gate::authorize('viewAny', $class);
    }

    private function modelFor(string $resource): ?string
    {
        return match ($resource) {
            'campuses' => Campus::class,
            'faculties' => Faculty::class,
            'programs' => Program::class,
            'academic-terms' => AcademicTerm::class,
            'courses' => Course::class,
            default => null,
        };
    }
}
