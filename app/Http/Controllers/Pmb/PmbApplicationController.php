<?php

namespace App\Http\Controllers\Pmb;

use App\Domain\Pmb\PmbApplicationWorkflowService;
use App\Domain\Pmb\PmbFeeResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pmb\StorePmbDocumentRequest;
use App\Http\Requests\Pmb\UpdatePmbProfileRequest;
use App\Models\PmbApplication;
use App\Models\PmbDocument;
use App\Models\Program;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class PmbApplicationController extends Controller
{
    public function __invoke(Request $request, PmbFeeResolver $feeResolver): Response
    {
        $application = PmbApplication::query()
            ->with(['program:id,name,code,degree', 'documents:id,pmb_application_id,type,original_name,mime_type,size,status,notes,uploaded_at', 'invoice:id,pmb_application_id,invoice_number,description,amount,paid_amount,due_at,status,issued_at', 'invoice.virtualAccount:id,pmb_invoice_id,provider,va_number,status,expires_at', 'selectionResult:id,pmb_selection_id,pmb_application_id,student_id,final_score,decision,finalized_at', 'selectionResult.selection:id,name,passing_grade,starts_at,ends_at', 'student:id,pmb_application_id,nim,status'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
        Gate::authorize('view', $application);

        return Inertia::render('Pmb/Application', [
            'application' => [
                'id' => $application->id,
                'registrationNumber' => $application->registration_number,
                'fullName' => $application->full_name,
                'email' => $application->email,
                'phone' => $application->phone,
                'programId' => $application->program_id,
                'registrationPath' => $application->registration_path,
                'registrationType' => $application->registration_type,
                'registrationWave' => $application->registration_wave,
                'identityNumber' => $application->identity_number,
                'birthPlace' => $application->birth_place,
                'birthDate' => $application->birth_date?->toDateString(),
                'gender' => $application->gender,
                'address' => $application->address,
                'previousSchool' => $application->previous_school,
                'graduationYear' => $application->graduation_year,
                'guardianName' => $application->guardian_name,
                'guardianPhone' => $application->guardian_phone,
                'profileCompletedAt' => $application->profile_completed_at?->toIso8601String(),
                'status' => $application->status,
                'submittedAt' => $application->submitted_at?->toIso8601String(),
                'program' => $application->program ? [
                    'name' => $application->program->name,
                    'code' => $application->program->code,
                    'degree' => $application->program->degree,
                ] : null,
                'documents' => $application->documents->map(fn (PmbDocument $document): array => [
                    'id' => $document->id,
                    'type' => $document->type,
                    'originalName' => $document->original_name,
                    'mimeType' => $document->mime_type,
                    'size' => $document->size,
                    'status' => $document->status,
                    'notes' => $document->notes,
                    'uploadedAt' => $document->uploaded_at->toIso8601String(),
                ])->values(),
                'invoice' => $application->invoice ? [
                    'invoiceNumber' => $application->invoice->invoice_number,
                    'description' => $application->invoice->description,
                    'amount' => $application->invoice->amount,
                    'paidAmount' => $application->invoice->paid_amount,
                    'dueAt' => $application->invoice->due_at?->toIso8601String(),
                    'status' => $application->invoice->status,
                    'issuedAt' => $application->invoice->issued_at->toIso8601String(),
                    'virtualAccount' => $application->invoice->virtualAccount ? [
                        'provider' => $application->invoice->virtualAccount->provider,
                        'number' => $application->invoice->virtualAccount->va_number,
                        'status' => $application->invoice->virtualAccount->status,
                        'expiresAt' => $application->invoice->virtualAccount->expires_at?->toIso8601String(),
                    ] : null,
                ] : null,
                'selection' => $application->selectionResult ? [
                    'name' => $application->selectionResult->selection->name,
                    'finalScore' => $application->selectionResult->final_score,
                    'passingGrade' => $application->selectionResult->selection->passing_grade,
                    'decision' => $application->selectionResult->decision,
                    'finalizedAt' => $application->selectionResult->finalized_at?->toIso8601String(),
                ] : null,
                'student' => $application->student ? [
                    'nim' => $application->student->nim,
                    'status' => $application->student->status,
                ] : null,
            ],
            'resolvedFee' => ($resolvedFee = $application->status === 'draft' ? $feeResolver->resolveOrNull($application) : null) ? [
                'name' => $resolvedFee->name,
                'amount' => $resolvedFee->amount,
                'dueDays' => $resolvedFee->due_days,
            ] : null,
            'programs' => Program::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'degree']),
            'documentRequirements' => collect(PmbApplicationWorkflowService::REQUIRED_DOCUMENTS)->map(fn (string $type): array => [
                'type' => $type,
                'label' => match ($type) {
                    'photo' => 'Pas foto',
                    'identity_card' => 'Kartu identitas / KTP',
                    'diploma' => 'Ijazah / SKL',
                    default => 'Transkrip / rapor',
                },
            ]),
            'abilities' => [
                'update' => $request->user()->can('update', $application),
                'manageDocuments' => $request->user()->can('manageDocuments', $application),
                'submit' => $request->user()->can('submit', $application),
            ],
        ]);
    }

    public function updateProfile(UpdatePmbProfileRequest $request, PmbApplicationWorkflowService $service): RedirectResponse
    {
        $application = $this->applicationFor($request);
        Gate::authorize('update', $application);
        DB::transaction(function () use ($request, $application, $service): void {
            $service->updateProfile($application, $request->validated(), $request->user());
            $this->audit($request, 'profile_updated', $application->id, ['fields' => array_keys($request->validated())]);
        }, 3);

        return back()->with('success', 'Biodata pendaftaran berhasil disimpan.');
    }

    public function storeDocument(StorePmbDocumentRequest $request): RedirectResponse
    {
        $application = $this->applicationFor($request);
        Gate::authorize('manageDocuments', $application);
        $file = $request->file('file');
        $type = $request->string('type')->toString();
        $path = $file->store("pmb-documents/{$application->id}", 'local');
        if (! is_string($path)) throw ValidationException::withMessages(['file' => 'Dokumen gagal disimpan. Silakan coba lagi.']);
        $oldPath = null;

        try {
            DB::transaction(function () use ($request, $application, $file, $type, $path, &$oldPath): void {
                $locked = PmbApplication::query()->lockForUpdate()->findOrFail($application->id);
                if ($locked->user_id !== $request->user()->id || $locked->status !== 'draft') throw ValidationException::withMessages(['application' => 'Aplikasi yang sudah dikirim tidak dapat diubah.']);
                $document = PmbDocument::query()->where(['pmb_application_id' => $locked->id, 'type' => $type])->lockForUpdate()->first();
                $oldPath = $document?->path;
                $data = [
                    'pmb_application_id' => $locked->id,
                    'type' => $type,
                    'disk' => 'local',
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'size' => $file->getSize(),
                    'status' => 'pending',
                    'notes' => null,
                    'uploaded_at' => now(),
                ];
                if ($document) $document->update($data);
                else PmbDocument::create($data);
                $this->audit($request, $document ? 'document_replaced' : 'document_uploaded', $locked->id, ['type' => $type, 'mime_type' => $data['mime_type'], 'size' => $data['size']]);
            }, 3);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
        if ($oldPath && $oldPath !== $path) Storage::disk('local')->delete($oldPath);

        return back()->with('success', 'Dokumen berhasil diunggah ke penyimpanan privat.');
    }

    public function destroyDocument(Request $request, int $document): RedirectResponse
    {
        $application = $this->applicationFor($request);
        Gate::authorize('manageDocuments', $application);
        [$disk, $path] = DB::transaction(function () use ($request, $application, $document): array {
            $locked = PmbApplication::query()->lockForUpdate()->findOrFail($application->id);
            if ($locked->status !== 'draft') throw ValidationException::withMessages(['application' => 'Aplikasi yang sudah dikirim tidak dapat diubah.']);
            $model = PmbDocument::query()->where('pmb_application_id', $locked->id)->lockForUpdate()->findOrFail($document);
            $model->delete();
            $this->audit($request, 'document_deleted', $application->id, ['type' => $model->type]);
            return [$model->disk, $model->path];
        }, 3);
        Storage::disk($disk)->delete($path);

        return back()->with('success', 'Dokumen berhasil dihapus.');
    }

    public function submit(Request $request, PmbApplicationWorkflowService $service): RedirectResponse
    {
        $application = $this->applicationFor($request);
        Gate::authorize('submit', $application);
        DB::transaction(function () use ($request, $application, $service): void {
            $service->submit($application, $request->user());
            $this->audit($request, 'submitted', $application->id, ['status' => 'submitted']);
        }, 3);

        return back()->with('success', 'Aplikasi PMB berhasil dikirim dan dikunci untuk proses verifikasi.');
    }

    private function applicationFor(Request $request): PmbApplication
    {
        return PmbApplication::query()->where('user_id', $request->user()->id)->firstOrFail();
    }

    private function audit(Request $request, string $action, int $applicationId, array $data): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'module' => 'pmb',
            'action' => $action,
            'record_type' => 'pmb_application',
            'record_id' => (string) $applicationId,
            'new_data' => json_encode($data),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
