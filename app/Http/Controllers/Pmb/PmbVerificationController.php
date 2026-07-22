<?php

namespace App\Http\Controllers\Pmb;

use App\Domain\Pmb\PmbApplicationWorkflowService;
use App\Domain\Pmb\PmbVerificationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pmb\ReviewPmbDocumentRequest;
use App\Models\PmbApplication;
use App\Models\PmbDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PmbVerificationController extends Controller
{
    public function show(Request $request, PmbApplication $application): Response
    {
        Gate::authorize('viewVerification', $application);
        $application->load(['program:id,name,code,degree', 'documents:id,pmb_application_id,type,original_name,mime_type,size,status,notes,uploaded_at', 'invoice:id,pmb_application_id,invoice_number,amount,paid_amount,due_at,status', 'invoice.virtualAccount:id,pmb_invoice_id,provider,va_number,status,expires_at']);

        return Inertia::render('Admin/PmbVerification', [
            'application' => $application,
            'documentRequirements' => collect(PmbApplicationWorkflowService::REQUIRED_DOCUMENTS)->mapWithKeys(fn (string $type): array => [$type => match ($type) { 'photo' => 'Pas foto', 'identity_card' => 'Kartu identitas / KTP', 'diploma' => 'Ijazah / SKL', default => 'Transkrip / rapor' }]),
            'abilities' => ['review' => $request->user()->can('review', $application), 'download' => $request->user()->can('viewDocument', $application)],
        ]);
    }

    public function download(Request $request, PmbApplication $application, int $document): StreamedResponse
    {
        Gate::authorize('viewDocument', $application);
        $model = PmbDocument::query()->where('pmb_application_id', $application->id)->findOrFail($document);
        abort_unless(Storage::disk($model->disk)->exists($model->path), 404);

        return Storage::disk($model->disk)->download($model->path, $model->original_name, ['Content-Type' => $model->mime_type]);
    }

    public function decide(ReviewPmbDocumentRequest $request, PmbApplication $application, int $document, PmbVerificationService $service): RedirectResponse
    {
        Gate::authorize('review', $application);
        $model = PmbDocument::query()->where('pmb_application_id', $application->id)->findOrFail($document);
        DB::transaction(function () use ($request, $application, $model, $service): void {
            $updated = $service->decideDocument($application, $model, $request->string('status')->toString(), $request->input('notes'));
            $this->audit($request, 'document_'.$updated->status, $application->id, ['document_id' => $updated->id, 'type' => $updated->type, 'notes' => $updated->notes]);
        }, 3);

        return back()->with('success', 'Keputusan dokumen berhasil disimpan.');
    }

    public function returnForCorrection(Request $request, PmbApplication $application, PmbVerificationService $service): RedirectResponse
    {
        Gate::authorize('review', $application);
        DB::transaction(function () use ($request, $application, $service): void {
            $service->returnForCorrection($application);
            $this->audit($request, 'returned_for_correction', $application->id, ['status' => 'draft']);
        }, 3);
        return back()->with('success', 'Aplikasi dikembalikan kepada pemohon untuk koreksi.');
    }

    public function verify(Request $request, PmbApplication $application, PmbVerificationService $service): RedirectResponse
    {
        Gate::authorize('review', $application);
        DB::transaction(function () use ($request, $application, $service): void {
            $service->verify($application);
            $this->audit($request, 'verified', $application->id, ['status' => 'verified']);
        }, 3);
        return back()->with('success', 'Aplikasi dan seluruh dokumen berhasil diverifikasi.');
    }

    private function audit(Request $request, string $action, int $id, array $data): void
    {
        DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'module' => 'pmb', 'action' => $action, 'record_type' => 'pmb_application', 'record_id' => (string) $id, 'new_data' => json_encode($data), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
