<?php

namespace App\Http\Controllers;

use App\Domain\Projects\AcademicProjectService;
use App\Domain\Projects\AcademicProjectProgressService;
use App\Http\Requests\AcademicProjectAssignmentRequest;
use App\Http\Requests\AcademicProjectDecisionRequest;
use App\Http\Requests\AcademicProjectDocumentRequest;
use App\Http\Requests\AcademicProjectRequest;
use App\Http\Requests\AcademicProjectDefenseCompletionRequest;
use App\Http\Requests\AcademicProjectDefenseRequest;
use App\Http\Requests\AcademicProjectGuidanceRequest;
use App\Http\Requests\AcademicProjectLogbookRequest;
use App\Http\Requests\AcademicProjectLogbookReviewRequest;
use App\Http\Requests\AcademicProjectRepositoryRequest;
use App\Http\Requests\AcademicProjectRubricRequest;
use App\Http\Requests\AcademicProjectScoreRequest;
use App\Models\AcademicProject;
use App\Models\AcademicProjectDefense;
use App\Models\AcademicProjectDocument;
use App\Models\AcademicProjectLogbook;
use App\Models\AcademicTerm;
use App\Models\Lecturer;
use App\Models\Room;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Dompdf\Dompdf;
use Dompdf\Options;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

final class AcademicProjectController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', AcademicProject::class);
        $filters = $request->validate([
            'project_type' => ['nullable', Rule::in(['thesis', 'internship', 'community_service'])],
            'status' => ['nullable', Rule::in(['draft', 'submitted', 'revision_required', 'approved', 'rejected', 'active', 'completed'])],
            'q' => ['nullable', 'string', 'max:100'],
            'selected' => ['nullable', 'integer', 'exists:academic_projects,id'],
        ]);
        $user = $request->user();
        $search = trim((string) ($filters['q'] ?? ''));
        $scope = AcademicProject::query()
            ->with(['student.user:id,name,email', 'student.program:id,code,name', 'academicTerm:id,code,name', 'lecturerAssignments.lecturer:id,name,nidn'])
            ->when($user->active_role === 'Mahasiswa', fn (Builder $query) => $query->where('student_id', $user->student?->id ?? 0))
            ->when($user->active_role === 'Dosen', fn (Builder $query) => $query->whereHas('lecturerAssignments', fn (Builder $assignment) => $assignment->where('lecturer_id', $user->lecturer?->id ?? 0)))
            ->when(isset($filters['project_type']), fn (Builder $query) => $query->where('project_type', $filters['project_type']))
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $query->where('project_number', 'like', "%{$search}%")->orWhere('title', 'like', "%{$search}%")->orWhereHas('student', fn (Builder $student) => $student->where('nim', 'like', "%{$search}%")->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', "%{$search}%")));
            }));
        $selectedId = isset($filters['selected']) ? (clone $scope)->whereKey($filters['selected'])->value('id') : (clone $scope)->latest('id')->value('id');
        $selected = $selectedId ? (clone $scope)->with(['documents.uploader:id,name', 'reviewer:id,name', 'lecturerAssignments.lecturer.user:id,name,email', 'logbooks.reviewer:id,name', 'guidanceRecords.lecturer:id,name,nidn', 'defenses.room.building:id,name', 'defenses.rubricItems.scores', 'repository.finalDocument'])->find($selectedId) : null;
        if ($selected) Gate::authorize('view', $selected);
        $manager = in_array($user->active_role, ['Admin', 'Prodi', 'Staff'], true);

        return Inertia::render('Academic/Projects', [
            'projects' => $scope->latest('id')->paginate(15)->withQueryString(),
            'selectedProject' => $selected,
            'filters' => ['project_type' => $filters['project_type'] ?? '', 'status' => $filters['status'] ?? '', 'q' => $search, 'selected' => $selectedId],
            'termOptions' => AcademicTerm::query()->orderByDesc('starts_on')->get(['id', 'code', 'name']),
            'lecturerOptions' => $manager ? Lecturer::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'nidn']) : [],
            'roomOptions' => $manager ? Room::query()->where('is_active', true)->with('building:id,name')->orderBy('name')->get(['id', 'building_id', 'name', 'code', 'capacity']) : [],
            'abilities' => [
                'create' => Gate::forUser($user)->allows('create', AcademicProject::class),
                'update' => $selected ? Gate::forUser($user)->allows('update', $selected) : false,
                'review' => $selected ? Gate::forUser($user)->allows('review', $selected) && $selected->status === 'submitted' : false,
                'assign' => $selected ? Gate::forUser($user)->allows('assign', $selected) && in_array($selected->status, ['approved', 'active'], true) : false,
                'upload' => $selected ? Gate::forUser($user)->allows('upload', $selected) : false,
                'log' => $selected ? Gate::forUser($user)->allows('log', $selected) : false,
                'supervise' => $selected ? Gate::forUser($user)->allows('supervise', $selected) : false,
                'schedule' => $selected ? Gate::forUser($user)->allows('schedule', $selected) : false,
                'score' => $selected ? Gate::forUser($user)->allows('score', $selected) : false,
                'publish' => $selected ? Gate::forUser($user)->allows('publish', $selected) : false,
            ],
            'mode' => $user->active_role === 'Mahasiswa' ? 'student' : 'manager',
            'currentLecturerId' => $user->lecturer?->id,
        ]);
    }

    public function store(AcademicProjectRequest $request, AcademicProjectService $service): RedirectResponse
    {
        Gate::authorize('create', AcademicProject::class);
        $project = $service->create($request->user()->student()->firstOrFail(), $request->validated(), $request->user());
        $this->audit($request, 'project_created', $project, ['project_type' => $project->project_type]);

        return redirect()->route('academic.projects', ['selected' => $project->id])->with('success', 'Draf kegiatan akademik berhasil dibuat.');
    }

    public function update(AcademicProjectRequest $request, AcademicProject $project, AcademicProjectService $service): RedirectResponse
    {
        Gate::authorize('update', $project); $project = $service->update($project, $request->validated()); $this->audit($request, 'project_updated', $project, ['title' => $project->title]);
        return back()->with('success', 'Draf kegiatan berhasil diperbarui.');
    }

    public function uploadDocument(AcademicProjectDocumentRequest $request, AcademicProject $project, AcademicProjectService $service): RedirectResponse
    {
        Gate::authorize('upload', $project); $document = $service->storeDocument($project, $request->validated('document_type'), $request->file('document'), $request->user()); $this->audit($request, 'project_document_uploaded', $project, ['document_id' => $document->id, 'type' => $document->document_type, 'version' => $document->version]);
        return back()->with('success', 'Dokumen privat berhasil disimpan sebagai versi baru.');
    }

    public function downloadDocument(Request $request, AcademicProject $project, AcademicProjectDocument $document): StreamedResponse
    {
        Gate::authorize('view', $project); abort_unless((int) $document->academic_project_id === (int) $project->id && Storage::disk($document->disk)->exists($document->path), 404);
        return Storage::disk($document->disk)->download($document->path, $document->original_name, ['Content-Type' => $document->mime_type]);
    }

    public function submit(Request $request, AcademicProject $project, AcademicProjectService $service, NotificationService $notifications): RedirectResponse
    {
        Gate::authorize('update', $project); $project = $service->submit($project, $request->user()); $this->notifyManagers($notifications, $project, 'Pengajuan kegiatan baru', $project->project_number.' menunggu pemeriksaan kelayakan.'); $this->audit($request, 'project_submitted', $project, ['eligibility' => $project->eligibility_snapshot]);
        return back()->with('success', 'Pengajuan berhasil dikirim untuk pemeriksaan kelayakan.');
    }

    public function decide(AcademicProjectDecisionRequest $request, AcademicProject $project, AcademicProjectService $service, NotificationService $notifications): RedirectResponse
    {
        Gate::authorize('review', $project); $project = $service->decide($project, $request->validated('decision'), $request->validated('notes'), $request->user()); $notifications->student($project->student, 'academic_project', 'Status kegiatan akademik diperbarui', $project->project_number.' kini berstatus '.str_replace('_', ' ', $project->status).'.', '/academic/projects?selected='.$project->id); $this->audit($request, 'project_'.$request->validated('decision'), $project, ['notes' => $request->validated('notes')]);
        return back()->with('success', 'Keputusan pemeriksaan berhasil disimpan.');
    }

    public function syncAssignments(AcademicProjectAssignmentRequest $request, AcademicProject $project, AcademicProjectService $service, NotificationService $notifications): RedirectResponse
    {
        Gate::authorize('assign', $project); $previous = $project->lecturerAssignments()->pluck('lecturer_id'); $project = $service->syncAssignments($project, $request->validated(), $request->user());
        foreach ($project->lecturerAssignments->whereNotIn('lecturer_id', $previous) as $assignment) {
            if ($assignment->lecturer?->user_id) $notifications->send($assignment->lecturer->user_id, 'academic_project_assignment', 'Penugasan kegiatan akademik', 'Anda ditetapkan sebagai '.($assignment->role === 'supervisor' ? 'pembimbing' : 'penguji').' untuk '.$project->project_number.'.', '/academic/projects?selected='.$project->id, ['academic_project_id' => $project->id]);
        }
        $this->audit($request, 'project_assignments_synced', $project, ['supervisors' => $request->validated('supervisor_ids'), 'examiners' => $request->validated('examiner_ids')]);
        return back()->with('success', 'Penetapan pembimbing dan penguji berhasil disimpan.');
    }

    public function storeLogbook(AcademicProjectLogbookRequest $request, AcademicProject $project, AcademicProjectProgressService $service, NotificationService $notifications): RedirectResponse
    {
        Gate::authorize('log', $project); $logbook = $service->createLogbook($project, $request->validated(), $request->user());
        foreach ($project->lecturerAssignments()->where('role', 'supervisor')->with('lecturer')->get() as $assignment) if ($assignment->lecturer?->user_id) $notifications->send($assignment->lecturer->user_id, 'academic_project_logbook', 'Logbook baru menunggu review', $project->project_number.' memiliki logbook baru.', '/academic/projects?selected='.$project->id);
        $this->audit($request, 'project_logbook_created', $project, ['logbook_id' => $logbook->id]); return back()->with('success', 'Logbook berhasil dikirim kepada pembimbing.');
    }

    public function reviewLogbook(AcademicProjectLogbookReviewRequest $request, AcademicProject $project, AcademicProjectLogbook $logbook, AcademicProjectProgressService $service, NotificationService $notifications): RedirectResponse
    {
        Gate::authorize('supervise', $project); $logbook = $service->reviewLogbook($project, $logbook, $request->validated('decision'), $request->validated('notes'), $request->user()); $notifications->student($project->student, 'academic_project_logbook', 'Logbook telah diperiksa', 'Logbook '.$logbook->activity_on->format('d M Y').' berstatus '.str_replace('_', ' ', $logbook->status).'.', '/academic/projects?selected='.$project->id); $this->audit($request, 'project_logbook_reviewed', $project, ['logbook_id' => $logbook->id, 'status' => $logbook->status]); return back()->with('success', 'Review logbook berhasil disimpan.');
    }

    public function storeGuidance(AcademicProjectGuidanceRequest $request, AcademicProject $project, AcademicProjectProgressService $service, NotificationService $notifications): RedirectResponse
    {
        Gate::authorize('supervise', $project); $record = $service->createGuidance($project, $request->validated(), $request->user()); $notifications->student($project->student, 'academic_project_guidance', 'Catatan bimbingan baru', 'Pembimbing menambahkan catatan dan tindak lanjut kegiatan.', '/academic/projects?selected='.$project->id); $this->audit($request, 'project_guidance_created', $project, ['guidance_id' => $record->id]); return back()->with('success', 'Catatan bimbingan berhasil disimpan.');
    }

    public function scheduleDefense(AcademicProjectDefenseRequest $request, AcademicProject $project, AcademicProjectProgressService $service, NotificationService $notifications): RedirectResponse
    {
        Gate::authorize('schedule', $project); $defense = $service->scheduleDefense($project, $request->validated(), $request->user()); $notifications->student($project->student, 'academic_project_defense', 'Jadwal seminar/sidang terbit', $project->project_number.' dijadwalkan pada '.$defense->scheduled_at->format('d M Y H:i').'.', '/academic/projects?selected='.$project->id); foreach ($project->lecturerAssignments()->with('lecturer')->get() as $assignment) if ($assignment->lecturer?->user_id) $notifications->send($assignment->lecturer->user_id, 'academic_project_defense', 'Jadwal seminar/sidang', $project->project_number.' dijadwalkan pada '.$defense->scheduled_at->format('d M Y H:i').'.', '/academic/projects?selected='.$project->id); $this->audit($request, 'project_defense_scheduled', $project, ['defense_id' => $defense->id]); return back()->with('success', 'Jadwal seminar/sidang berhasil dibuat.');
    }

    public function saveRubric(AcademicProjectRubricRequest $request, AcademicProject $project, AcademicProjectDefense $defense, AcademicProjectProgressService $service): RedirectResponse
    {
        Gate::authorize('schedule', $project); $items = $service->saveRubric($project, $defense, $request->validated('items')); $this->audit($request, 'project_rubric_saved', $project, ['defense_id' => $defense->id, 'items' => $items->count()]); return back()->with('success', 'Rubrik penilaian berhasil disimpan.');
    }

    public function saveScores(AcademicProjectScoreRequest $request, AcademicProject $project, AcademicProjectDefense $defense, AcademicProjectProgressService $service): RedirectResponse
    {
        Gate::authorize('score', $project); $scores = $service->saveScores($project, $defense, $request->validated('scores'), $request->user()); $this->audit($request, 'project_scores_saved', $project, ['defense_id' => $defense->id, 'scores' => $scores->count()]); return back()->with('success', 'Nilai rubrik Anda berhasil disimpan.');
    }

    public function completeDefense(AcademicProjectDefenseCompletionRequest $request, AcademicProject $project, AcademicProjectDefense $defense, AcademicProjectProgressService $service, NotificationService $notifications): RedirectResponse
    {
        Gate::authorize('schedule', $project); $defense = $service->completeDefense($project, $defense, $request->validated(), $request->user()); $notifications->student($project->student, 'academic_project_defense', 'Hasil seminar/sidang tersedia', 'Hasil '.$project->project_number.': '.$defense->result.' dengan nilai '.$defense->final_score.'.', '/academic/projects?selected='.$project->id); $this->audit($request, 'project_defense_completed', $project, ['defense_id' => $defense->id, 'result' => $defense->result, 'score' => $defense->final_score]); return back()->with('success', 'Seminar/sidang berhasil ditutup dan berita acara dikunci.');
    }

    public function publishRepository(AcademicProjectRepositoryRequest $request, AcademicProject $project, AcademicProjectProgressService $service, NotificationService $notifications): RedirectResponse
    {
        Gate::authorize('publish', $project); $repository = $service->publishRepository($project, $request->validated(), $request->user()); $notifications->student($project->student, 'academic_project_repository', 'Kegiatan akademik selesai', 'Repository '.$project->project_number.' telah diterbitkan.', '/academic/projects?selected='.$project->id); $this->audit($request, 'project_repository_published', $project, ['repository_id' => $repository->id]); return back()->with('success', 'Repository akhir berhasil diterbitkan dan kegiatan dinyatakan selesai.');
    }

    public function minutesPdf(Request $request, AcademicProject $project, AcademicProjectDefense $defense)
    {
        Gate::authorize('view', $project); abort_unless((int) $defense->academic_project_id === (int) $project->id && $defense->status === 'completed', 404); $this->loadDefenseForDocument($defense); $verificationUrl = route('academic.projects.defenses.verify', $defense->verification_code); $svg = (new Writer(new ImageRenderer(new RendererStyle(110, 1), new SvgImageBackEnd())))->writeString($verificationUrl); $options = new Options(); $options->set('isRemoteEnabled', false); $options->set('defaultFont', 'DejaVu Sans'); $dompdf = new Dompdf($options); $dompdf->loadHtml(view('projects.minutes', compact('project', 'defense', 'verificationUrl') + ['qrCode' => 'data:image/svg+xml;base64,'.base64_encode($svg)])->render()); $dompdf->setPaper('A4'); $dompdf->render(); return response($dompdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="berita-acara-'.str_replace('/', '-', $project->project_number).'.pdf"']);
    }

    public function verifyDefense(string $verificationCode): View
    {
        $defense = AcademicProjectDefense::query()->with(['project.student.user:id,name', 'project.student:id,user_id,nim', 'project.program:id,code,name'])->where('verification_code', $verificationCode)->firstOrFail(); return view('projects.verify', ['defense' => $defense, 'valid' => $defense->status === 'completed']);
    }

    public function repository(string $verificationCode): View
    {
        $repository = \App\Models\AcademicProjectRepository::query()->with(['project.student.user:id,name', 'project.student:id,user_id,nim', 'project.program:id,code,name', 'finalDocument'])->where('verification_code', $verificationCode)->firstOrFail(); return view('projects.repository', compact('repository'));
    }

    public function repositoryDownload(string $verificationCode): StreamedResponse
    {
        $repository = \App\Models\AcademicProjectRepository::query()->with('finalDocument')->where('verification_code', $verificationCode)->firstOrFail(); abort_unless($repository->publication_consent && Storage::disk($repository->finalDocument->disk)->exists($repository->finalDocument->path), 403); return Storage::disk($repository->finalDocument->disk)->download($repository->finalDocument->path, $repository->finalDocument->original_name, ['Content-Type' => $repository->finalDocument->mime_type]);
    }

    private function notifyManagers(NotificationService $notifications, AcademicProject $project, string $title, string $message): void
    {
        $ids = DB::table('users')->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->where('model_has_roles.model_type', \App\Models\User::class)->whereIn('roles.name', ['Admin', 'Prodi', 'Staff'])->where('users.is_active', true)->pluck('users.id')->unique();
        foreach ($ids as $id) $notifications->send((int) $id, 'academic_project_queue', $title, $message, '/academic/projects?selected='.$project->id, ['academic_project_id' => $project->id]);
    }

    private function audit(Request $request, string $action, AcademicProject $project, array $data): void
    {
        DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'module' => 'academic_projects', 'action' => $action, 'record_type' => 'academic_project', 'record_id' => (string) $project->id, 'new_data' => json_encode($data), 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function loadDefenseForDocument(AcademicProjectDefense $defense): void
    {
        $defense->load(['project.student.user:id,name', 'project.student.program:id,code,name', 'project.academicTerm:id,code,name', 'project.lecturerAssignments.lecturer:id,name,nidn', 'room.building:id,name', 'rubricItems.scores.lecturer:id,name', 'completer:id,name']);
    }
}
