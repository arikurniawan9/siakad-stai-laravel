<?php

namespace App\Http\Controllers;

use App\Domain\Graduation\AlumniService;
use App\Domain\Graduation\GraduationService;
use App\Http\Requests\AlumniProfileRequest;
use App\Http\Requests\GraduationDecisionRequest;
use App\Http\Requests\GraduationDocumentRequest;
use App\Http\Requests\GraduationPeriodRequest;
use App\Http\Requests\TracerStudyRequest;
use App\Models\AlumniProfile;
use App\Models\AcademicTerm;
use App\Models\GraduateDocument;
use App\Models\GraduationApplication;
use App\Models\GraduationApplicationDocument;
use App\Models\GraduationPeriod;
use App\Services\NotificationService;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class GraduationController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', GraduationApplication::class);
        $filters = $request->validate(['status' => ['nullable', Rule::in(['draft', 'submitted', 'approved', 'rejected', 'graduated'])], 'period_id' => ['nullable', 'integer', 'exists:graduation_periods,id'], 'q' => ['nullable', 'string', 'max:100'], 'selected' => ['nullable', 'integer', 'exists:graduation_applications,id']]);
        $user = $request->user(); $search = trim((string) ($filters['q'] ?? ''));
        $scope = GraduationApplication::query()->with(['period:id,code,name,judicium_on,ceremony_on', 'student.user:id,name,email', 'student.program:id,code,name,degree'])
            ->when($user->active_role === 'Mahasiswa', fn (Builder $query) => $query->where('student_id', $user->student?->id ?? 0))
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(isset($filters['period_id']), fn (Builder $query) => $query->where('graduation_period_id', $filters['period_id']))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('application_number', 'like', "%{$search}%")->orWhereHas('student', fn (Builder $student) => $student->where('nim', 'like', "%{$search}%")->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', "%{$search}%")))));
        $selectedId = isset($filters['selected']) ? (clone $scope)->whereKey($filters['selected'])->value('id') : (clone $scope)->latest('id')->value('id');
        $selected = $selectedId ? (clone $scope)->with(['documents.uploader:id,name', 'reviewer:id,name', 'graduateDocuments.issuer:id,name', 'alumniProfile.tracerResponses'])->find($selectedId) : null;
        if ($selected) Gate::authorize('view', $selected);
        $manager = in_array($user->active_role, ['Admin', 'Prodi', 'Staff'], true);
        $ownProfile = $user->student ? AlumniProfile::query()->with('tracerResponses')->where('student_id', $user->student->id)->first() : null;

        return Inertia::render('Graduation/Index', [
            'applications' => $scope->latest('id')->paginate(15)->withQueryString(), 'selectedApplication' => $selected,
            'periods' => GraduationPeriod::query()->withCount(['applications', 'applications as approved_count' => fn (Builder $query) => $query->whereIn('status', ['approved', 'graduated'])])->with('academicTerm:id,code,name')->orderByDesc('judicium_on')->get(),
            'termOptions' => AcademicTerm::query()->select('id', 'code', 'name')->orderByDesc('starts_on')->get(),
            'filters' => ['status' => $filters['status'] ?? '', 'period_id' => (string) ($filters['period_id'] ?? ''), 'q' => $search, 'selected' => $selectedId],
            'alumniProfile' => $ownProfile, 'mode' => $user->active_role === 'Mahasiswa' ? 'student' : 'manager',
            'abilities' => ['managePeriods' => $manager && $user->can('graduation.create'), 'start' => Gate::forUser($user)->allows('create', GraduationApplication::class), 'update' => $selected ? Gate::forUser($user)->allows('update', $selected) : false, 'review' => $selected ? Gate::forUser($user)->allows('review', $selected) && $selected->status === 'submitted' : false, 'graduate' => $selected ? Gate::forUser($user)->allows('graduate', $selected) && $selected->status === 'approved' : false, 'updateAlumni' => $ownProfile ? Gate::forUser($user)->allows('update', $ownProfile) : false],
        ]);
    }

    public function storePeriod(GraduationPeriodRequest $request): RedirectResponse
    {
        $this->authorizeManager($request); $period = GraduationPeriod::create([...$request->validated(), 'created_by' => $request->user()->id]); $this->audit($request, 'graduation_period_created', 'graduation_period', $period->id, $period->only(['code', 'name'])); return back()->with('success', 'Periode yudisium/wisuda berhasil dibuat.');
    }
    public function updatePeriod(GraduationPeriodRequest $request, GraduationPeriod $period): RedirectResponse
    {
        $this->authorizeManager($request); $period->update($request->validated()); $this->audit($request, 'graduation_period_updated', 'graduation_period', $period->id, $period->only(['code', 'name'])); return back()->with('success', 'Periode berhasil diperbarui.');
    }
    public function start(Request $request, GraduationPeriod $period, GraduationService $service): RedirectResponse
    {
        Gate::authorize('create', GraduationApplication::class); $application = $service->start($request->user()->student()->firstOrFail(), $period); $this->audit($request, 'graduation_application_started', 'graduation_application', $application->id, ['period_id' => $period->id]); return redirect()->route('graduation.index', ['selected' => $application->id])->with('success', 'Draf pengajuan yudisium/wisuda siap dilengkapi.');
    }
    public function uploadDocument(GraduationDocumentRequest $request, GraduationApplication $application, GraduationService $service): RedirectResponse
    {
        Gate::authorize('update', $application); $document = $service->storeDocument($application, $request->validated('document_type'), $request->file('document'), $request->user()); $this->audit($request, 'graduation_document_uploaded', 'graduation_application', $application->id, ['document_id' => $document->id, 'type' => $document->document_type]); return back()->with('success', 'Dokumen persyaratan berhasil diunggah.');
    }
    public function downloadDocument(Request $request, GraduationApplication $application, GraduationApplicationDocument $document): StreamedResponse
    {
        Gate::authorize('view', $application); abort_unless((int) $document->graduation_application_id === (int) $application->id && Storage::disk($document->disk)->exists($document->path), 404); return Storage::disk($document->disk)->download($document->path, $document->original_name, ['Content-Type' => $document->mime_type]);
    }
    public function submit(Request $request, GraduationApplication $application, GraduationService $service, NotificationService $notifications): RedirectResponse
    {
        Gate::authorize('update', $application); $application = $service->submit($application); $this->notifyManagers($notifications, $application, 'Pengajuan yudisium baru', $application->application_number.' menunggu verifikasi.'); $this->audit($request, 'graduation_application_submitted', 'graduation_application', $application->id, ['eligibility' => $application->eligibility_snapshot]); return back()->with('success', 'Pengajuan berhasil dikirim untuk verifikasi.');
    }
    public function decide(GraduationDecisionRequest $request, GraduationApplication $application, GraduationService $service, NotificationService $notifications): RedirectResponse
    {
        Gate::authorize('review', $application); $application = $service->decide($application, $request->validated('decision'), $request->validated('notes'), $request->user()); $notifications->student($application->student, 'graduation', 'Status pengajuan kelulusan', $application->application_number.' berstatus '.$application->status.'.', '/graduation?selected='.$application->id); $this->audit($request, 'graduation_'.$request->validated('decision'), 'graduation_application', $application->id, ['notes' => $request->validated('notes')]); return back()->with('success', 'Keputusan pengajuan berhasil disimpan.');
    }
    public function graduate(Request $request, GraduationApplication $application, GraduationService $service, NotificationService $notifications): RedirectResponse
    {
        Gate::authorize('graduate', $application); $application = $service->markGraduated($application, $request->user()); $notifications->student($application->student, 'graduation', 'Selamat, Anda telah dinyatakan lulus', 'Dokumen ijazah, transkrip final, dan SKPI telah diterbitkan.', '/graduation?selected='.$application->id); $this->audit($request, 'student_graduated', 'graduation_application', $application->id, ['document_ids' => $application->graduateDocuments->pluck('id')->all()]); return back()->with('success', 'Peserta ditetapkan lulus dan tiga dokumen resmi berhasil diterbitkan.');
    }
    public function documentPdf(Request $request, GraduationApplication $application, GraduateDocument $document)
    {
        Gate::authorize('view', $application); abort_unless((int) $document->graduation_application_id === (int) $application->id, 404); return $this->renderDocument($document);
    }
    public function verifyDocument(string $verificationCode): View
    {
        $document = GraduateDocument::query()->with('application.student.user')->where('verification_code', $verificationCode)->firstOrFail(); $hash = hash('sha256', json_encode($document->snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); return view('graduation.verify', ['document' => $document, 'valid' => hash_equals($document->content_hash, $hash) && ! $document->revoked_at]);
    }
    public function updateAlumni(AlumniProfileRequest $request, AlumniProfile $profile, AlumniService $service): RedirectResponse
    {
        Gate::authorize('update', $profile); $profile = $service->updateProfile($profile, $request->validated()); $this->audit($request, 'alumni_profile_updated', 'alumni_profile', $profile->id, ['employment_status' => $profile->employment_status]); return back()->with('success', 'Profil alumni berhasil diperbarui.');
    }
    public function submitTracer(TracerStudyRequest $request, AlumniProfile $profile, AlumniService $service): RedirectResponse
    {
        Gate::authorize('update', $profile); $response = $service->submitTracer($profile, $request->validated()); $this->audit($request, 'tracer_study_submitted', 'tracer_study_response', $response->id, ['survey_year' => $response->survey_year]); return back()->with('success', 'Tracer study berhasil disimpan.');
    }

    private function renderDocument(GraduateDocument $document)
    {
        $verificationUrl = route('graduation.documents.verify', $document->verification_code); $svg = (new Writer(new ImageRenderer(new RendererStyle(120, 1), new SvgImageBackEnd())))->writeString($verificationUrl); $options = new Options(); $options->set('isRemoteEnabled', false); $options->set('defaultFont', 'DejaVu Sans'); $dompdf = new Dompdf($options); $dompdf->loadHtml(view('graduation.document', compact('document', 'verificationUrl') + ['qrCode' => 'data:image/svg+xml;base64,'.base64_encode($svg)])->render()); $dompdf->setPaper('A4', $document->document_type === 'diploma' ? 'landscape' : 'portrait'); $dompdf->render(); return response($dompdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.str_replace('/', '-', $document->document_number).'.pdf"']);
    }
    private function authorizeManager(Request $request): void { abort_unless(in_array($request->user()->active_role, ['Admin', 'Prodi', 'Staff'], true) && $request->user()->can('graduation.create'), 403); }
    private function notifyManagers(NotificationService $notifications, GraduationApplication $application, string $title, string $message): void { $ids = DB::table('users')->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->where('model_has_roles.model_type', \App\Models\User::class)->whereIn('roles.name', ['Admin', 'Prodi', 'Staff'])->where('users.is_active', true)->pluck('users.id')->unique(); foreach ($ids as $id) $notifications->send((int) $id, 'graduation_queue', $title, $message, '/graduation?selected='.$application->id); }
    private function audit(Request $request, string $action, string $type, int $id, array $data): void { DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'module' => 'graduation', 'action' => $action, 'record_type' => $type, 'record_id' => (string) $id, 'new_data' => json_encode($data), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]); }
}
