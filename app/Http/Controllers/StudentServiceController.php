<?php

namespace App\Http\Controllers;

use App\Domain\Services\StudentServiceDocumentService;
use App\Domain\Services\StudentServiceWorkflow;
use App\Http\Requests\ServiceRequestTypeRequest;
use App\Http\Requests\StudentServiceDecisionRequest;
use App\Http\Requests\StudentServiceRequestFormRequest;
use App\Models\ServiceRequestType;
use App\Models\StudentServiceDocument;
use App\Models\StudentServiceRequest;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class StudentServiceController extends Controller
{
    public function index(Request $request, StudentServiceWorkflow $workflow): Response
    {
        Gate::authorize('viewAny', StudentServiceRequest::class);
        $filters = $request->validate(['status' => ['nullable', Rule::in(['in_review', 'revision_required', 'rejected', 'completed', 'cancelled', 'overdue'])], 'type_id' => ['nullable', 'integer', 'exists:service_request_types,id'], 'q' => ['nullable', 'string', 'max:100'], 'selected' => ['nullable', 'integer', 'exists:student_service_requests,id']]);
        $user = $request->user(); abort_unless(in_array($user->active_role, ['Admin', 'Prodi', 'Dosen', 'Mahasiswa', 'Staff', 'Keuangan', 'Bendahara'], true), 403);
        $search = trim((string) ($filters['q'] ?? ''));
        $scoped = StudentServiceRequest::query()->with(['type:id,code,name,category,sla_business_days,requires_attachment', 'student.user:id,name,email', 'student.program:id,code,name', 'steps.decider:id,name', 'document:id,student_service_request_id,document_number,verification_code,issued_at,revoked_at,revocation_reason'])
            ->when($user->active_role === 'Mahasiswa', fn (Builder $query) => $query->where('student_id', $user->student?->id ?? 0))
            ->when($user->active_role === 'Dosen', fn (Builder $query) => $query->whereHas('student', fn (Builder $query) => $query->where('academic_advisor_id', $user->lecturer?->id ?? 0))->whereHas('steps', fn (Builder $query) => $query->where('stage', 'advisor')))
            ->when($user->active_role === 'Prodi', fn (Builder $query) => $query->whereHas('steps', fn (Builder $query) => $query->where('stage', 'program')))
            ->when(in_array($user->active_role, ['Keuangan', 'Bendahara'], true), fn (Builder $query) => $query->whereHas('steps', fn (Builder $query) => $query->where('stage', 'finance')))
            ->when($user->active_role === 'Staff', fn (Builder $query) => $query->whereHas('steps', fn (Builder $query) => $query->where('stage', 'academic')));
        $base = (clone $scoped)
            ->when(($filters['status'] ?? null) === 'overdue', fn (Builder $query) => $query->whereIn('status', ['in_review', 'revision_required'])->where('due_at', '<', now()), fn (Builder $query) => $query->when(isset($filters['status']), fn (Builder $query) => $query->where('status', $filters['status'])))
            ->when(isset($filters['type_id']), fn (Builder $query) => $query->where('service_request_type_id', $filters['type_id']))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('request_number', 'like', "%{$search}%")->orWhere('subject', 'like', "%{$search}%")->orWhereHas('student', fn (Builder $query) => $query->where('nim', 'like', "%{$search}%")->orWhereHas('user', fn (Builder $query) => $query->where('name', 'like', "%{$search}%")))));
        $selectedId = isset($filters['selected']) ? (clone $base)->whereKey($filters['selected'])->value('id') : (clone $base)->latest('id')->value('id');
        $selected = $selectedId ? (clone $base)->with(['type', 'student.user:id,name,email', 'student.program:id,code,name,degree', 'student.academicAdvisor:id,name,nidn', 'steps.decider:id,name', 'events.actor:id,name', 'document.issuer:id,name', 'document.revoker:id,name'])->find($selectedId) : null;
        if ($selected) Gate::authorize('view', $selected);
        $scope = clone $scoped;
        $stats = ['total' => (clone $scope)->count(), 'pending' => (clone $scope)->where('status', 'in_review')->count(), 'revision' => (clone $scope)->where('status', 'revision_required')->count(), 'completed' => (clone $scope)->where('status', 'completed')->count(), 'overdue' => (clone $scope)->whereIn('status', ['in_review', 'revision_required'])->where('due_at', '<', now())->count()];
        $manageTypes = $user->active_role === 'Admin' && $user->can('service_types.update');

        return Inertia::render('Services/Index', [
            'mode' => $user->active_role === 'Mahasiswa' ? 'student' : 'manager', 'filters' => ['status' => $filters['status'] ?? '', 'type_id' => (string) ($filters['type_id'] ?? ''), 'q' => $search, 'selected' => $selectedId],
            'requests' => $base->latest('id')->paginate(15)->withQueryString(), 'selectedRequest' => $selected, 'stats' => $stats,
            'typeOptions' => ServiceRequestType::query()->where('is_active', true)->orderBy('name')->get(),
            'managedTypes' => $manageTypes ? ServiceRequestType::withTrashed()->withCount('requests')->orderBy('name')->get() : [],
            'abilities' => ['create' => $user->can('create', StudentServiceRequest::class), 'review' => $selected ? $workflow->canReview($user, $selected) && $selected->status === 'in_review' : false, 'cancel' => $selected ? $user->can('cancel', $selected) && in_array($selected->status, ['in_review', 'revision_required'], true) : false, 'manageTypes' => $manageTypes, 'revokeDocument' => $user->active_role === 'Admin' && $user->can('service_requests.delete')],
            'stageLabels' => ['advisor' => 'Dosen Pembimbing Akademik', 'program' => 'Program Studi', 'finance' => 'Unit Keuangan', 'academic' => 'Administrasi Akademik'],
        ]);
    }

    public function store(StudentServiceRequestFormRequest $request, StudentServiceWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('create', StudentServiceRequest::class); $student = $request->user()->student()->firstOrFail(); $type = ServiceRequestType::query()->findOrFail($request->validated('service_request_type_id'));
        $attachment = $this->storeAttachment($request, 'service-requests/'.$student->id); $path = $attachment['attachment_path'] ?? null;
        try { $model = $workflow->submit($student, $type, $request->validated(), $attachment, $request->user()); }
        catch (Throwable $exception) { if ($path) Storage::disk('local')->delete($path); throw $exception; }
        $this->audit($request, 'service_request_submitted', $model, ['type_id' => $type->id]);
        return redirect()->route('services.index', ['selected' => $model->id])->with('success', 'Pengajuan layanan berhasil dikirim dan masuk antrean pemeriksaan.');
    }

    public function resubmit(StudentServiceRequestFormRequest $request, StudentServiceRequest $serviceRequest, StudentServiceWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('cancel', $serviceRequest); $attachment = $this->storeAttachment($request, 'service-requests/'.$serviceRequest->student_id); $newPath = $attachment['attachment_path'] ?? null; $oldPath = $serviceRequest->attachment_path;
        try { $updated = $workflow->resubmit($serviceRequest, $request->validated(), $attachment, $request->user()); }
        catch (Throwable $exception) { if ($newPath) Storage::disk('local')->delete($newPath); throw $exception; }
        if ($newPath && $oldPath && $oldPath !== $newPath) Storage::disk('local')->delete($oldPath);
        $this->audit($request, 'service_request_resubmitted', $updated, ['revision_number' => $updated->revision_number]);
        return back()->with('success', 'Revisi pengajuan berhasil dikirim kembali kepada pemeriksa.');
    }

    public function cancel(Request $request, StudentServiceRequest $serviceRequest, StudentServiceWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('cancel', $serviceRequest); $updated = $workflow->cancel($serviceRequest, $request->user()); $this->audit($request, 'service_request_cancelled', $updated, []);
        return back()->with('success', 'Pengajuan layanan berhasil dibatalkan.');
    }

    public function decide(StudentServiceDecisionRequest $request, StudentServiceRequest $serviceRequest, StudentServiceWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('review', $serviceRequest); $updated = $workflow->decide($serviceRequest, $request->validated('decision'), $request->validated('notes'), $request->user()); $this->audit($request, 'service_request_'.$request->validated('decision'), $updated, ['stage' => $serviceRequest->current_stage, 'notes' => $request->validated('notes')]);
        return back()->with('success', match ($request->validated('decision')) { 'approve' => $updated->status === 'completed' ? 'Seluruh tahap selesai dan surat resmi berhasil diterbitkan.' : 'Tahap berhasil disetujui dan diteruskan.', 'revision' => 'Permintaan revisi telah dikirim kepada mahasiswa.', default => 'Pengajuan telah ditolak dengan catatan.' });
    }

    public function attachment(Request $request, StudentServiceRequest $serviceRequest): StreamedResponse
    {
        Gate::authorize('view', $serviceRequest); abort_unless($serviceRequest->attachment_path && Storage::disk('local')->exists($serviceRequest->attachment_path), 404);
        return Storage::disk('local')->download($serviceRequest->attachment_path, $serviceRequest->attachment_name, ['Content-Type' => $serviceRequest->attachment_mime ?? 'application/octet-stream']);
    }

    public function storeType(ServiceRequestTypeRequest $request, StudentServiceWorkflow $workflow): RedirectResponse
    {
        $this->authorizeTypeManager($request); $data = $request->validated(); $data['workflow'] = $workflow->normalizeWorkflow($data['workflow']); $type = ServiceRequestType::create([...$data, 'created_by' => $request->user()->id]); $this->auditType($request, 'service_type_created', $type, $data);
        return back()->with('success', 'Jenis layanan berhasil ditambahkan.');
    }

    public function updateType(ServiceRequestTypeRequest $request, ServiceRequestType $type, StudentServiceWorkflow $workflow): RedirectResponse
    {
        $this->authorizeTypeManager($request); $data = $request->validated(); $data['workflow'] = $workflow->normalizeWorkflow($data['workflow']); $type->update($data); $this->auditType($request, 'service_type_updated', $type, $data);
        return back()->with('success', 'Konfigurasi jenis layanan berhasil diperbarui.');
    }

    public function destroyType(Request $request, ServiceRequestType $type): RedirectResponse
    {
        $this->authorizeTypeManager($request); if ($type->requests()->exists()) throw ValidationException::withMessages(['type' => 'Jenis layanan yang sudah memiliki histori pengajuan tidak dapat diarsipkan. Nonaktifkan saja jenis layanan tersebut.']);
        $type->delete(); $this->auditType($request, 'service_type_archived', $type, []); return back()->with('success', 'Jenis layanan berhasil diarsipkan.');
    }

    public function showDocument(Request $request, StudentServiceDocument $document): View
    {
        $document->loadMissing('request'); Gate::authorize('view', $document->request);
        return view('services.letter', $this->documentViewData($document, false));
    }

    public function pdfDocument(Request $request, StudentServiceDocument $document): HttpResponse
    {
        $document->loadMissing('request'); Gate::authorize('view', $document->request); $options = new Options(); $options->set('isRemoteEnabled', false); $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options); $dompdf->loadHtml(view('services.letter', $this->documentViewData($document, true))->render()); $dompdf->setPaper('A4'); $dompdf->render();
        return response($dompdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.str_replace('/', '-', $document->document_number).'.pdf"']);
    }

    public function revokeDocument(Request $request, StudentServiceDocument $document, StudentServiceDocumentService $documents): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]); $documents->revoke($document, $request->user(), $data['reason']); $this->audit($request, 'service_document_revoked', $document->request, ['document_id' => $document->id, 'reason' => $data['reason']]);
        return back()->with('success', 'Surat layanan berhasil dicabut dan status verifikasi diperbarui.');
    }

    public function verifyDocument(string $verificationCode, StudentServiceDocumentService $documents): View
    {
        $document = StudentServiceDocument::query()->with(['issuer:id,name', 'revoker:id,name'])->where('verification_code', $verificationCode)->firstOrFail(); $integrity = $documents->integrityValid($document);
        return view('services.verify', ['document' => $document, 'integrityValid' => $integrity, 'valid' => $integrity && ! $document->revoked_at]);
    }

    private function storeAttachment(StudentServiceRequestFormRequest $request, string $directory): array
    {
        if (! $request->hasFile('attachment')) return [];
        $file = $request->file('attachment'); return ['attachment_path' => $file->store($directory, 'local'), 'attachment_name' => $file->getClientOriginalName(), 'attachment_mime' => $file->getMimeType(), 'attachment_size' => $file->getSize()];
    }

    private function documentViewData(StudentServiceDocument $document, bool $pdf): array
    {
        $document->loadMissing(['issuer:id,name', 'revoker:id,name']); $url = route('services.documents.verify', $document->verification_code); $svg = (new Writer(new ImageRenderer(new RendererStyle(126, 1), new SvgImageBackEnd())))->writeString($url); $logoPath = public_path('img/logostai.png');
        return ['document' => $document, 'snapshot' => $document->snapshot, 'verificationUrl' => $url, 'qrCode' => 'data:image/svg+xml;base64,'.base64_encode($svg), 'logo' => is_file($logoPath) ? 'data:image/'.pathinfo($logoPath, PATHINFO_EXTENSION).';base64,'.base64_encode((string) file_get_contents($logoPath)) : null, 'pdf' => $pdf];
    }

    private function authorizeTypeManager(Request $request): void { abort_unless($request->user()->active_role === 'Admin' && $request->user()->can('service_types.update'), 403); }
    private function audit(Request $request, string $action, StudentServiceRequest $model, array $data): void { DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'module' => 'services', 'action' => $action, 'record_type' => 'student_service_request', 'record_id' => (string) $model->id, 'new_data' => json_encode($data), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]); }
    private function auditType(Request $request, string $action, ServiceRequestType $model, array $data): void { DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'module' => 'services', 'action' => $action, 'record_type' => 'service_request_type', 'record_id' => (string) $model->id, 'new_data' => json_encode($data), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]); }
}
